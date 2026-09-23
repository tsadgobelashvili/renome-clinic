<?php

use App\Models\Patient;
use App\Models\TreatmentEstimate;
use App\Models\User;
use App\Support\TreatmentPlanDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
    expect($html)->toContain($labels['title'], $labels['patient'], $labels['date'], $labels['manipulation'],
        $labels['quantity'], $labels['unit_price'], $labels['stage_total'], $labels['duration'],
        $labels['clinic'], $labels['address'], TreatmentPlanDocument::CONTACT, e($this->description), '3 კვირა / weeks', '216.00');

    $this->get(route('treatment-estimates.pdf', [...$this->parameters, 'language' => $language]))
        ->assertOk()->assertHeader('content-type', 'application/pdf');
    $response = $this->get(route('treatment-estimates.word', [...$this->parameters, 'language' => $language]))
        ->assertOk()->assertDownload('treatment-estimate-'.$estimate->id.'.docx');
    $path = $response->baseResponse->getFile()->getPathname();
    $archive = new ZipArchive;
    expect($archive->open($path))->toBeTrue();
    $document = new DOMDocument;
    expect($document->loadXML($archive->getFromName('word/document.xml')))->toBeTrue();
    expect($document->textContent)->toContain($labels['title'], $labels['patient'], $labels['date'], $labels['manipulation'],
        $labels['quantity'], $labels['unit_price'], $labels['stage_total'], $labels['duration'],
        $this->description, $this->option->name, '3 კვირა / weeks', '216.00');
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
