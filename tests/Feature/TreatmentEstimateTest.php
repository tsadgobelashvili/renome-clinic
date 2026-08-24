<?php

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentEstimate;
use App\Models\User;
use App\Models\Visit;
use App\Services\TreatmentEstimateExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

function createTreatmentEstimate(): TreatmentEstimate
{
    $patient = Patient::create(['first_name' => 'Estimate', 'last_name' => 'Patient']);
    $doctor = Doctor::create([
        'first_name' => 'Estimate',
        'last_name' => 'Doctor',
        'is_active' => true,
    ]);

    $estimate = TreatmentEstimate::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'estimate_date' => now()->toDateString(),
        'estimated_duration' => '3-4 თვე',
    ]);

    $estimate->options()->create([
        'name' => 'ვარიანტი 1',
        'estimated_duration' => '3-4 თვე',
    ]);

    return $estimate;
}

test('estimate calculates line totals and overall total dynamically', function () {
    $estimate = createTreatmentEstimate();
    $estimate->options()->first()->items()->createMany([
        ['description' => 'იმპლანტაცია', 'quantity' => 4, 'unit_price' => 1200],
        ['description' => 'ცირკონის გვირგვინი', 'quantity' => 4, 'unit_price' => 900],
        ['description' => 'ექსტრაქცია', 'quantity' => 2, 'unit_price' => 150],
    ]);

    $estimate->load('options.items');

    expect($estimate->options->first()->items->first()->line_total)->toBe(4800.0)
        ->and($estimate->total_amount)->toBe(8700.0)
        ->and($estimate->options->first()->stages()->count())->toBe(1)
        ->and($estimate->options->first()->stages()->first()->subtotal)->toBe(8700.0)
        ->and($estimate->patient->treatmentEstimates()->count())->toBe(1)
        ->and($estimate->doctor->treatmentEstimates()->count())->toBe(1);
});

test('option and stage names have server-side fallbacks when hidden fields are blank', function () {
    $estimate = createTreatmentEstimate();
    $estimate->options()->delete();

    $option = $estimate->options()->create(['name' => null]);
    $firstStage = $option->stages()->create(['name' => null, 'sort_order' => 1]);
    $secondStage = $option->stages()->create(['name' => '', 'sort_order' => 2]);

    expect($option->name)->toBe('ვარიანტი 1')
        ->and($firstStage->name)->toBe('I ეტაპი')
        ->and($secondStage->name)->toBe('II ეტაპი');
});

test('estimate option supports ordered stages with independent subtotals', function () {
    $estimate = createTreatmentEstimate();
    $option = $estimate->options()->first();
    $surgery = $option->stages()->create([
        'name' => 'ქირურგიული ეტაპი',
        'sort_order' => 1,
    ]);
    $orthopedics = $option->stages()->create([
        'name' => 'ორთოპედიული ეტაპი',
        'notes' => 'საბოლოო კონსტრუქცია',
        'sort_order' => 2,
    ]);
    $surgery->items()->createMany([
        ['description' => 'იმპლანტაცია', 'quantity' => 4, 'unit_price' => 1200],
        ['description' => 'აუგმენტაცია', 'quantity' => 1, 'unit_price' => 1000],
    ]);
    $orthopedics->items()->create([
        'description' => 'ცირკონის გვირგვინი',
        'quantity' => 4,
        'unit_price' => 900,
    ]);

    expect($option->fresh()->stages->pluck('name')->all())->toBe([
        'ქირურგიული ეტაპი',
        'ორთოპედიული ეტაპი',
    ])->and($surgery->fresh()->subtotal)->toBe(5800.0)
        ->and($orthopedics->fresh()->subtotal)->toBe(3600.0)
        ->and($option->fresh()->total_amount)->toBe(9400.0);

    $html = view('exports.treatment-estimate', [
        'estimate' => $estimate->fresh()->load(['patient', 'doctor', 'options.stages.items']),
        'clinicName' => config('app.name'),
        'exportFontFamily' => 'Segoe UI',
    ])->render();

    expect($html)->toContain('ქირურგიული ეტაპი')
        ->and($html)->toContain('ორთოპედიული ეტაპი')
        ->and($html)->not->toContain('საბოლოო კონსტრუქცია');
});

test('estimate item validates quantity and unit price', function () {
    $estimate = createTreatmentEstimate();

    $option = $estimate->options()->first();

    expect(fn () => $option->items()->create([
        'description' => 'Invalid quantity',
        'quantity' => 0,
        'unit_price' => 100,
    ]))->toThrow(ValidationException::class)
        ->and(fn () => $option->items()->create([
            'description' => 'Invalid price',
            'quantity' => 1,
            'unit_price' => -1,
        ]))->toThrow(ValidationException::class);
});

test('estimate does not affect patient visit financial summary', function () {
    $estimate = createTreatmentEstimate();
    $estimate->options()->first()->items()->create([
        'description' => 'კონსულტაციის შეთავაზება',
        'quantity' => 1,
        'unit_price' => 8700,
    ]);

    expect($estimate->patient->getFinancialSummary())->toBe([
        'gross_amount' => 0.0,
        'discount_amount' => 0.0,
        'net_amount' => 0.0,
        'paid_amount' => 0.0,
        'remaining_amount' => 0.0,
    ]);
});

test('consultation can be linked to an estimate without affecting its finances', function () {
    $estimate = createTreatmentEstimate();
    $visit = Visit::create([
        'patient_id' => $estimate->patient_id,
        'doctor_id' => $estimate->doctor_id,
        'visit_date' => now()->toDateString(),
        'visit_type' => 'consultation',
        'comment' => 'Initial consultation',
    ]);

    $estimate->update(['visit_id' => $visit->getKey()]);

    expect($visit->fresh()->treatmentEstimates)->toHaveCount(1)
        ->and($estimate->fresh()->visit->is($visit))->toBeTrue()
        ->and($visit->payments()->exists())->toBeFalse()
        ->and($visit->net_amount)->toBeNull();
});

test('estimate exports pdf and editable word documents', function () {
    $estimate = createTreatmentEstimate();
    $option = $estimate->options()->first();
    $option->items()->createMany([
        ['description' => 'ქართული ტექსტი', 'quantity' => 1, 'unit_price' => 100],
        ['description' => 'მკურნალობის გეგმა და კალკულაცია', 'quantity' => 1, 'unit_price' => 1800],
    ]);
    $option->update(['discount_type' => 'percent', 'discount_value' => 10]);
    $estimate->load(['patient', 'doctor', 'options.items']);
    $html = view('exports.treatment-estimate', [
        'estimate' => $estimate,
        'clinicName' => config('app.name'),
        'exportFontFamily' => 'Segoe UI',
    ])->render();

    expect($html)->toContain('მკურნალობის გეგმა და კალკულაცია')
        ->and($html)->toContain('პაციენტი:')
        ->and($html)->toContain('მანიპულაცია')
        ->and($html)->not->toContain('ძირითადი ეტაპი')
        ->and($html)->toContain('ეტაპის ჯამი')
        ->and($html)->toContain('100.00 ₾')
        ->and($html)->toContain('1,800.00 ₾')
        ->and($html)->not->toContain('????')
        ->and($html)->not->toContain('£');

    $service = app(TreatmentEstimateExportService::class);

    $pdf = $service->pdf($estimate);
    $word = $service->word($estimate);

    expect($pdf->headers->get('content-type'))->toBe('application/pdf')
        ->and($pdf->getContent())->toContain('%PDF-')
        ->and($pdf->getContent())->toContain('SegoeUI')
        ->and($word->getFile()->isFile())->toBeTrue()
        ->and(substr((string) file_get_contents($word->getFile()->getPathname()), 0, 2))->toBe('PK');

    $archive = new ZipArchive;
    expect($archive->open($word->getFile()->getPathname()))->toBeTrue();
    $documentXml = (string) $archive->getFromName('word/document.xml');
    $stylesXml = (string) $archive->getFromName('word/styles.xml');
    $archive->close();

    expect($documentXml)->toContain('100.00 ₾')
        ->and($documentXml)->toContain('1,800.00 ₾')
        ->and($documentXml)->toContain('მკურნალობის გეგმა და კალკულაცია')
        ->and($stylesXml)->toContain('Segoe UI');

    @unlink($word->getFile()->getPathname());
});

test('authenticated export routes return binary files outside livewire', function () {
    $estimate = createTreatmentEstimate();
    $estimate->options()->first()->items()->create([
        'description' => 'Export treatment',
        'quantity' => 1,
        'unit_price' => 100,
    ]);
    $this->actingAs(User::factory()->create());

    $this->get(route('treatment-estimates.pdf', $estimate))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
    $this->get(route('treatment-estimates.word', $estimate))
        ->assertOk()
        ->assertDownload("treatment-estimate-{$estimate->getKey()}.docx");
});

test('pdf download uses a safe Georgian patient name', function () {
    $estimate = createTreatmentEstimate();
    $estimate->patient->update([
        'first_name' => 'გიორგი/',
        'last_name' => 'ბერიძე:*?',
    ]);

    $response = app(TreatmentEstimateExportService::class)->pdf($estimate->fresh());

    $disposition = (string) $response->headers->get('content-disposition');
    $encodedFilename = str($disposition)->after("filename*=utf-8''")->toString();

    expect(rawurldecode($encodedFilename))->toBe('გიორგი_ბერიძე.pdf');
});

test('treatment plan resource stays routable while hidden from sidebar', function () {
    $estimate = createTreatmentEstimate();

    expect(TreatmentEstimateResource::shouldRegisterNavigation())->toBeFalse()
        ->and(TreatmentEstimateResource::getUrl('view', ['record' => $estimate]))->toContain('/treatment-estimates/')
        ->and(PatientResource::getUrl('treatment-plans', ['record' => $estimate->patient]))
        ->toContain('/patients/'.$estimate->patient_id.'/treatment-plans');
});

test('pdf template uses treatment plan document title', function () {
    $estimate = createTreatmentEstimate()->load(['patient', 'doctor', 'options.items']);

    expect(view('exports.treatment-estimate', [
        'estimate' => $estimate,
        'clinicName' => config('app.name'),
        'exportFontFamily' => 'Segoe UI',
    ])->render())->toContain('მკურნალობის გეგმა და კალკულაცია');
});

test('estimate options calculate independent totals', function () {
    $estimate = createTreatmentEstimate();
    $first = $estimate->options()->first();
    $second = $estimate->options()->create(['name' => 'პრემიუმ', 'estimated_duration' => '5-7 თვე']);

    $first->items()->create(['description' => 'ვარიანტი 1', 'quantity' => 4, 'unit_price' => 2100]);
    $second->items()->create(['description' => 'ვარიანტი 2', 'quantity' => 6, 'unit_price' => 2100]);

    expect($first->fresh()->total_amount)->toBe(8400.0)
        ->and($second->fresh()->total_amount)->toBe(12600.0)
        ->and($estimate->options()->count())->toBe(2);
});

test('pdf hides a single option heading and shows headings for multiple options', function () {
    $estimate = createTreatmentEstimate();
    $estimate->options()->first()->update(['name' => 'ONLY_OPTION_HEADING']);

    $render = fn (): string => view('exports.treatment-estimate', [
        'estimate' => $estimate->fresh()->load(['patient', 'doctor', 'options.stages.items']),
        'clinicName' => config('app.name'),
        'exportFontFamily' => 'Segoe UI',
    ])->render();

    expect($render())->not->toContain('ONLY_OPTION_HEADING');

    $estimate->options()->create(['name' => 'SECOND_OPTION_HEADING']);

    expect($render())->toContain('ONLY_OPTION_HEADING')
        ->and($render())->toContain('SECOND_OPTION_HEADING');
});

test('estimate option supports amount and percentage discounts', function () {
    $estimate = createTreatmentEstimate();
    $option = $estimate->options()->first();
    $option->items()->create(['description' => 'Treatment', 'quantity' => 1, 'unit_price' => 10000]);

    $option->update(['discount_type' => 'amount', 'discount_value' => 1000]);
    $option->refresh();

    expect($option->total_amount)->toBe(10000.0)
        ->and($option->discount_amount)->toBe(1000.0)
        ->and($option->final_amount)->toBe(9000.0);

    $option->update(['discount_type' => 'percent', 'discount_value' => 10]);
    $option->refresh();

    expect($option->discount_amount)->toBe(1000.0)
        ->and($option->final_amount)->toBe(9000.0)
        ->and($option->discount_display)->toBe('10.00% (1,000.00 ₾)');
});

test('estimate option without discount keeps its subtotal', function () {
    $estimate = createTreatmentEstimate();
    $option = $estimate->options()->first();
    $option->items()->create(['description' => 'Treatment', 'quantity' => 2, 'unit_price' => 500]);

    expect($option->discount_amount)->toBe(0.0)
        ->and($option->final_amount)->toBe(1000.0);
});

test('estimate progress summarizes linked treatment visits and payments', function () {
    $estimate = createTreatmentEstimate();
    $option = $estimate->options()->first();
    $option->items()->create(['description' => 'Treatment', 'quantity' => 4, 'unit_price' => 1000]);

    $firstVisit = Visit::create([
        'patient_id' => $estimate->patient_id,
        'doctor_id' => $estimate->doctor_id,
        'visit_date' => now()->subDay()->toDateString(),
        'visit_type' => 'treatment',
        'treatment_estimate_id' => $estimate->getKey(),
        'treatment_estimate_option_id' => $option->getKey(),
        'total_price' => 1500,
        'discount_type' => 'amount',
        'discount_value' => 100,
    ]);
    $secondVisit = Visit::create([
        'patient_id' => $estimate->patient_id,
        'doctor_id' => $estimate->doctor_id,
        'visit_date' => now()->toDateString(),
        'visit_type' => 'treatment',
        'treatment_estimate_id' => $estimate->getKey(),
        'treatment_estimate_option_id' => $option->getKey(),
        'total_price' => 1000,
    ]);
    $firstVisit->payments()->create([
        'amount' => 900,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ]);
    $secondVisit->payments()->create([
        'amount' => 500,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'card',
    ]);

    expect($estimate->fresh()->getProgressSummary())->toBe([
        'planned_amount' => 4000.0,
        'executed_amount' => 2400.0,
        'paid_amount' => 1400.0,
        'remaining_amount' => 1000.0,
    ]);
});

test('estimate without visits has zero real progress and keeps option plan separate', function () {
    $estimate = createTreatmentEstimate();
    $first = $estimate->options()->first();
    $first->items()->create(['description' => 'Basic', 'quantity' => 2, 'unit_price' => 500]);
    $premium = $estimate->options()->create(['name' => 'Premium']);
    $premium->items()->create(['description' => 'Premium', 'quantity' => 2, 'unit_price' => 900]);

    expect($estimate->fresh()->getProgressSummary())->toBe([
        'planned_amount' => 1000.0,
        'executed_amount' => 0.0,
        'paid_amount' => 0.0,
        'remaining_amount' => 0.0,
    ]);
});

test('visit rejects estimate from another patient and option from another estimate', function () {
    $estimate = createTreatmentEstimate();
    $otherEstimate = createTreatmentEstimate();

    expect(fn () => Visit::create([
        'patient_id' => $otherEstimate->patient_id,
        'doctor_id' => $estimate->doctor_id,
        'visit_date' => now()->toDateString(),
        'visit_type' => 'treatment',
        'treatment_estimate_id' => $estimate->getKey(),
    ]))->toThrow(ValidationException::class)
        ->and(fn () => Visit::create([
            'patient_id' => $estimate->patient_id,
            'doctor_id' => $estimate->doctor_id,
            'visit_date' => now()->toDateString(),
            'visit_type' => 'treatment',
            'treatment_estimate_id' => $estimate->getKey(),
            'treatment_estimate_option_id' => $otherEstimate->options()->first()->getKey(),
        ]))->toThrow(ValidationException::class);
});
