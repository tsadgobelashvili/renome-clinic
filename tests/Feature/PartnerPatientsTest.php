<?php

use App\Filament\Resources\PartnerPatients\Pages\CreatePartnerPatient;
use App\Filament\Resources\PartnerPatients\Pages\EditPartnerPatient;
use App\Filament\Resources\PartnerPatients\Pages\ListPartnerPatients;
use App\Filament\Resources\PartnerPatients\Pages\ViewPartnerPatient;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('partner patients list is database scoped to israel partner patients', function () {
    $this->actingAs(User::factory()->create());
    $clinicPatient = Patient::create([
        'first_name' => 'Clinic',
        'last_name' => 'Patient',
        'phone' => '555111111',
    ]);
    $partnerPatient = Patient::create([
        'first_name' => 'Partner',
        'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    Livewire::test(ListPartnerPatients::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$partnerPatient])
        ->assertCanNotSeeTableRecords([$clinicPatient])
        ->assertTableColumnDoesNotExist('outstanding_balance');
});

test('creating a partner patient assigns the fixed group and shares the main patient record', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreatePartnerPatient::class)
        ->assertFormFieldDoesNotExist('patient_group_id')
        ->fillForm([
            'first_name' => 'Israel',
            'last_name' => 'Partner',
            'phone' => null,
            'birth_date' => '1992-03-14',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $patient = Patient::query()->where('last_name', 'Partner')->sole();

    expect($patient->patientGroup->slug)->toBe(PatientGroup::ISRAEL_PARTNER_SLUG)
        ->and($patient->phone)->toBeNull()
        ->and($patient->birth_date->toDateString())->toBe('1992-03-14');

    Livewire::test(ListPatients::class)->assertCanSeeTableRecords([$patient]);
});

test('partner and main patient modules edit the same record', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create([
        'first_name' => 'Shared',
        'last_name' => 'Record',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    Livewire::test(EditPartnerPatient::class, ['record' => $patient->getRouteKey()])
        ->fillForm(['birth_date' => '1988-07-09'])
        ->call('save')
        ->assertHasNoFormErrors();

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->fillForm(['last_name' => 'Updated'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Patient::query()->count())->toBe(1)
        ->and($patient->fresh()->last_name)->toBe('Updated')
        ->and($patient->fresh()->birth_date->toDateString())->toBe('1988-07-09');
});

test('partner profile uses shared doctors visits and treatment history', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create([
        'first_name' => 'History',
        'last_name' => 'Partner',
        'patient_group_id' => PatientGroup::israelPartnerId(),
        'notes' => 'Partner note',
    ]);
    $doctor = Doctor::create([
        'first_name' => 'Shared',
        'last_name' => 'Doctor',
        'specialty' => 'თერაპევტი',
        'is_active' => true,
    ]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
    ]);
    $treatment = TreatmentCase::create([
        'name' => 'Shared treatment',
        'category' => 'therapy',
        'is_active' => true,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 2,
        'unit_price' => 100,
    ]);

    Livewire::test(ListPartnerPatients::class)
        ->assertCanSeeTableRecords([$patient])
        ->assertSee($doctor->full_name);

    Livewire::test(ViewPartnerPatient::class, ['record' => $patient->getRouteKey()])
        ->assertOk()
        ->assertSee($doctor->full_name)
        ->assertSee('Shared treatment ×2')
        ->assertSee('Partner note');

    expect(Visit::query()->count())->toBe(1)
        ->and($visit->patient->is($patient))->toBeTrue()
        ->and($visit->doctor->is($doctor))->toBeTrue();
});
