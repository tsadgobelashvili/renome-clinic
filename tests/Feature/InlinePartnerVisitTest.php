<?php

use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('inline visit patient defaults to clinic group', function () {
    $patientId = VisitForm::createInlinePatient([
        'first_name' => 'Inline',
        'last_name' => 'Clinic',
        'phone' => '555123123',
        'is_israel_patient' => false,
    ]);

    expect(Patient::findOrFail($patientId)->patientGroup->slug)->toBe(PatientGroup::CLINIC_SLUG);
});

test('inline visit patient can be assigned to israel partner group without phone', function () {
    $patientId = VisitForm::createInlinePatient([
        'first_name' => 'Inline',
        'last_name' => 'Israel',
        'phone' => null,
        'is_israel_patient' => true,
    ]);

    $patient = Patient::findOrFail($patientId);
    expect($patient->patientGroup->slug)->toBe(PatientGroup::ISRAEL_PARTNER_SLUG)
        ->and($patient->phone)->toBeNull();
});

test('israel partner visit requires at least one manipulation', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create([
        'first_name' => 'No', 'last_name' => 'Work',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    $component = Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'visit_type' => 'treatment',
            'treatmentCaseItems' => [['quantity' => 1]],
        ])
        ->call('create');

    $component->assertHasErrors();

    expect(Visit::query()->count())->toBe(0);
});

test('israel partner visit saves unpaid and preserves manipulation value', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'Partner', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create([
        'first_name' => 'Unpaid', 'last_name' => 'Israel',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_type' => 'treatment',
            'treatmentCaseItems' => [[
                'service_choice' => '__manual__',
                'treatment_case_id' => null,
                'custom_service_name' => 'Partner treatment',
                'quantity' => 2,
                'unit_price' => 120,
            ]],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $visit = Visit::query()->with(['patient.patientGroup', 'treatmentCaseItems', 'payments'])->sole();
    expect((float) $visit->total_price)->toBe(240.0)
        ->and($visit->treatmentCaseItems->sole()->manipulation_total)->toBe(240.0)
        ->and($visit->payments)->toBeEmpty()
        ->and($visit->remaining_amount)->toBe(0.0);
});

test('israel unpaid work is excluded from clinic debt while clinic debt is unchanged', function () {
    $partner = Patient::create([
        'first_name' => 'Debtless', 'last_name' => 'Partner',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $clinic = Patient::create(['first_name' => 'Debt', 'last_name' => 'Clinic']);

    Visit::create(['patient_id' => $partner->getKey(), 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 240]);
    $doctor = Doctor::create(['first_name' => 'Debt', 'last_name' => 'Doctor', 'is_active' => true]);
    Visit::query()->where('patient_id', $partner->getKey())->update(['doctor_id' => $doctor->getKey()]);
    Visit::create(['patient_id' => $clinic->getKey(), 'doctor_id' => $doctor->getKey(), 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 350]);

    $rows = Patient::query()->withClinicDebtBalances()->get()->keyBy('id');

    expect((float) $rows[$partner->getKey()]->remaining_amount_gel)->toBe(0.0)
        ->and($partner->fresh()->getFinancialSummary()['remaining_amount'])->toBe(0.0)
        ->and(Patient::query()->whereHasClinicDebt()->pluck('id')->all())->toBe([$clinic->getKey()])
        ->and(Patient::query()->whereHasClinicDebt(false)->pluck('id')->all())->toContain($partner->getKey())
        ->and((float) $rows[$clinic->getKey()]->remaining_amount_gel)->toBe(350.0)
        ->and($clinic->fresh()->getFinancialSummary()['remaining_amount'])->toBe(350.0)
        ->and($doctor->getFinancialSummary()['remaining_amount'])->toBe(350.0);
});
