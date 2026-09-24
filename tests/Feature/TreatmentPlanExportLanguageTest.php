<?php

use App\Models\Patient;
use App\Models\TreatmentEstimate;
use App\Models\User;
use App\Services\TreatmentEstimateExportService;
use App\Support\TreatmentPlanDocument;
use FontLib\Font;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->estimate = TreatmentEstimate::create([
        'patient_id' => Patient::create(['first_name' => 'გიორგი', 'last_name' => 'Test'])->id,
        'estimate_date' => today(),
    ]);
    $this->option = $this->estimate->options()->create([
        'name' => 'ჩემი ვარიანტი & вариант', 'estimated_duration' => '3 კვირა / weeks',
        'discount_type' => 'percent', 'discount_value' => 10,
    ]);
    $this->description = 'იმპლანტაცია & Коронка <custom> "Exact"';
    $this->option->items()->create(['description' => $this->description, 'quantity' => 2, 'unit_price' => 120]);
    $this->parameters = ['patient' => $this->estimate->patient_id, 'estimate' => $this->estimate->id];
});

test('plan exports share localized labels identity and unchanged free text', function (string $language) {
    $labels = TreatmentPlanDocument::labels($language);
    $estimate = $this->estimate->fresh()->load(['patient', 'doctor', 'options.items', 'options.stages.items']);
    $before = $estimate->toArray();
    $html = view('exports.treatment-estimate', ['estimate' => $estimate, 'language' => $language, 'exportFontFamily' => 'Segoe UI'])->render();
    expect($html)->toContain('<td class="number">2</td>')->not->toContain('<td class="number">2.00</td>');
    expect($html)->toContain($labels['title'], $labels['patient'], $labels['date'], $labels['manipulation'],
        $labels['quantity'], $labels['unit_price'], $labels['stage_total'], $labels['duration'],
        $labels['clinic'], $labels['address'], TreatmentPlanDocument::CONTACT, e($this->description), '3 კვირა / weeks', '216 GEL', '10.00% (24 GEL)')
        ->not->toContain('₾');

    $this->get(route('treatment-estimates.pdf', [...$this->parameters, 'language' => $language]))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $response = $this->get(route('treatment-estimates.word', [...$this->parameters, 'language' => $language]))
        ->assertOk()->assertDownload('treatment-estimate-'.$estimate->id.'.docx');
    $path = $response->baseResponse->getFile()->getPathname();
    $archive = new ZipArchive;
    expect($archive->open($path))->toBeTrue();
    $document = new DOMDocument;
    expect($document->loadXML($archive->getFromName('word/document.xml')))->toBeTrue();
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    expect($xpath->evaluate('string((//w:tbl/w:tr[2]/w:tc[2]//w:t)[1])'))->toBe('2');
    expect($document->textContent)->toContain($labels['title'], $labels['patient'], $labels['date'], $labels['manipulation'],
        $labels['quantity'], $labels['unit_price'], $labels['stage_total'], $labels['duration'],
        $this->description, $this->option->name, '3 კვირა / weeks', '216 GEL', '10.00% (24 GEL)')
        ->not->toContain('₾');
    $header = new DOMDocument;
    expect($header->loadXML($archive->getFromName('word/header1.xml')))->toBeTrue();
    expect($header->textContent)->toContain($labels['clinic'], $labels['address'], TreatmentPlanDocument::CONTACT);
    expect($archive->getFromName('word/footer1.xml'))->toContain('info@renome.ge');
    $archive->close();
    unlink($path);
    expect($estimate->fresh()->load(['patient', 'doctor', 'options.items', 'options.stages.items'])->toArray())->toBe($before);
})->with(['ka', 'en', 'ru']);

test('language chooser uses one selection for both formats and remembers it', function () {
    $url = route('treatment-estimates.export', $this->parameters);
    $this->get($url)->assertOk()->assertSee(['ქართული', 'English', 'Русский', 'PDF', 'Word'])
        ->assertViewHas('language', 'ka');
    $this->get(route('treatment-estimates.pdf', [...$this->parameters, 'language' => 'ru']))->assertOk();
    $this->get($url)->assertViewHas('language', 'ru');
    $response = $this->get(route('treatment-estimates.word', $this->parameters))->assertOk();
    $path = $response->baseResponse->getFile()->getPathname();
    $archive = new ZipArchive;
    $archive->open($path);
    expect($archive->getFromName('word/document.xml'))->toContain(TreatmentPlanDocument::labels('ru')['title']);
    $archive->close();
    unlink($path);
});

test('export language validation and plan ownership are enforced', function () {
    foreach (['pdf', 'word'] as $format) {
        $this->getJson(route('treatment-estimates.'.$format, [...$this->parameters, 'language' => 'fr']))
            ->assertUnprocessable()->assertJsonValidationErrors('language');
    }
    $otherPatient = Patient::create(['first_name' => 'Other', 'last_name' => 'Patient']);
    $this->get(route('treatment-estimates.export', [...$this->parameters, 'patient' => $otherPatient->id]))->assertNotFound();
});

test('both exports accept DejaVu regular and bold without the lari glyph', function () {
    $fontCache = storage_path('framework/testing/treatment-plan-fonts');
    File::ensureDirectoryExists($fontCache);
    config(['dompdf.options.font_dir' => $fontCache, 'dompdf.options.font_cache' => $fontCache]);
    $fontDirectory = base_path('vendor/dompdf/dompdf/lib/fonts');
    $settings = [
        'EXPORT_UNICODE_FONT_FAMILY' => 'DejaVu Sans',
        'EXPORT_UNICODE_FONT_PATH' => $fontDirectory.'/DejaVuSans.ttf',
        'EXPORT_UNICODE_BOLD_FONT_PATH' => $fontDirectory.'/DejaVuSans-Bold.ttf',
    ];
    $originalEnv = $_ENV;
    $originalServer = $_SERVER;
    $wordPath = null;

    try {
        foreach ($settings as $key => $value) {
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $service = app(TreatmentEstimateExportService::class);
        $validate = new ReflectionMethod($service, 'fontSupportsExportCharacters');
        foreach (['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf'] as $file) {
            $font = Font::load($fontDirectory.'/'.$file);
            $font->parse();
            $characters = $font->getUnicodeCharMap();
            $font->close();
            expect(empty($characters[0x20BE]))->toBeTrue()
                ->and($validate->invoke($service, $fontDirectory.'/'.$file))->toBeTrue();
        }
        $selected = (new ReflectionMethod($service, 'unicodeExportFont'))->invoke($service);
        expect($selected['family'])->toBe('DejaVu Sans');
        expect($service->pdf($this->estimate, 'ru')->getContent())->toStartWith('%PDF-');
        $word = $service->word($this->estimate, 'ru');
        $wordPath = $word->getFile()->getPathname();
        $archive = new ZipArchive;
        expect($archive->open($wordPath))->toBeTrue();
        $xml = new DOMDocument;
        expect($xml->loadXML($archive->getFromName('word/document.xml')))->toBeTrue();
        expect($xml->textContent)->toContain($this->description, '216 GEL')->not->toContain('₾');
        $archive->close();
    } finally {
        $_ENV = $originalEnv;
        $_SERVER = $originalServer;
        if ($wordPath !== null && is_file($wordPath)) {
            unlink($wordPath);
        }
    }
});
