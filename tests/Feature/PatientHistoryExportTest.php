<?php

use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\PatientHistoryExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('patient history exports complete related history as pdf and editable word', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create([
        'first_name' => 'გიორგი',
        'last_name' => 'ბერიძე',
        'phone' => '555123456',
        'personal_id' => '01010112345',
        'birth_date' => '1990-01-02',
        'notes' => 'პაციენტის შენიშვნა',
    ]);
    $doctor = Doctor::create(['first_name' => 'ნოდარ', 'last_name' => 'ელიშაკოვი', 'is_active' => true]);
    $treatment = TreatmentCase::create([
        'name' => 'იმპლანტაცია',
        'category' => 'surgery',
        'is_active' => true,
    ]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => '2026-08-20',
        'visit_type' => 'treatment',
        'total_price' => 2400,
        'currency' => 'GEL',
        'comment' => 'ვიზიტის კომენტარი',
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 2,
        'unit_price' => 1200,
        'teeth' => '14, 16',
    ]);
    $visit->payments()->create([
        'amount' => 1000,
        'currency' => 'GEL',
        'payment_date' => '2026-08-20',
        'payment_method' => 'cash',
        'comment' => 'პირველი გადახდა',
    ]);

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->assertOk()
        ->assertSee('ისტორიის ექსპორტი');

    $exporter = app(PatientHistoryExportService::class);
    $html = view('exports.patient-history', [
        'patient' => $patient->load(['visits.doctor', 'visits.treatmentCaseItems.treatmentCase', 'visits.payments.splits']),
        'financialSummaries' => $patient->getFinancialSummariesByCurrency(),
        'exportFontFamily' => 'Segoe UI',
    ])->render();

    expect($html)
        ->toContain('პაციენტის მკურნალობის ისტორია')
        ->toContain('იმპლანტაცია')
        ->toContain('ქირურგია')
        ->toContain('1,000.00 ₾');

    $pdf = $exporter->pdf($patient->fresh());
    expect($pdf->getContent())->toStartWith('%PDF-');

    $word = $exporter->word($patient->fresh());
    $archive = new ZipArchive;
    expect($archive->open($word->getFile()->getPathname()))->toBeTrue();
    $documentXml = $archive->getFromName('word/document.xml');
    $archive->close();

    expect($documentXml)
        ->toContain('პაციენტის მკურნალობის ისტორია')
        ->toContain('იმპლანტაცია')
        ->toContain('₾');

    @unlink($word->getFile()->getPathname());
});

test('patient history exports safely when no history exists', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'ცარიელი', 'last_name' => 'ისტორია']);

    $pdf = app(PatientHistoryExportService::class)->pdf($patient);

    expect($pdf->getContent())->toStartWith('%PDF-');
});
