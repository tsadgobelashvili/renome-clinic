<?php

use App\Filament\Pages\LabSalaries;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\LabCases\Pages\CreateLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Filament\Resources\LabTechnicianRates\LabTechnicianRateResource;
use App\Filament\Resources\LabTechnicianRates\Pages\ListLabTechnicianRates;
use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\LabSalarySettlement;
use App\Models\LabTechnicianRate;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\LabSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function labUser(string $name, string $role): User
{
    return User::factory()->create(['name' => $name, 'role' => $role, 'locale' => 'ka']);
}

function labPatient(): Patient
{
    PatientGroup::query()->firstOrCreate(['slug' => PatientGroup::CLINIC_SLUG], ['name' => 'Clinic', 'is_active' => true]);

    return Patient::create(['first_name' => 'Lab', 'last_name' => uniqid()]);
}

function labRate(User $technician, string $work, string $component, float $rate): void
{
    LabTechnicianRate::create(['technician_id' => $technician->id, 'work_type' => $work, 'component_type' => $component, 'rate_per_unit' => $rate, 'is_active' => true]);
}

function labCaseFor(Patient $patient, ?Doctor $doctor = null, ?LabCase $related = null, ?string $relationship = null): LabCase
{
    return LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $doctor?->id, 'case_date' => today(), 'status' => 'open', 'related_case_id' => $related?->id, 'case_relationship' => $relationship]);
}

test('lab case reuses shared patient and doctor records', function () {
    $patient = labPatient();
    $doctor = Doctor::create(['first_name' => 'Lab', 'last_name' => 'Doctor', 'is_active' => true]);
    $case = labCaseFor($patient, $doctor);

    expect($case->patient->is($patient))->toBeTrue()
        ->and($case->doctor->is($doctor))->toBeTrue()
        ->and($patient->fresh()->labCases)->toHaveCount(1);
});

test('lab visibility follows owner technician and administrator roles', function () {
    $owner = labUser('Owner', User::ROLE_OWNER);
    $technician = labUser('Tech', User::ROLE_LAB_TECHNICIAN);
    $administrator = labUser('Admin', User::ROLE_ADMINISTRATOR);

    $this->actingAs($owner);
    expect(LabCaseResource::canViewAny())->toBeTrue()->and(LabTechnicianRateResource::canViewAny())->toBeTrue();
    $this->actingAs($technician);
    expect(LabCaseResource::canViewAny())->toBeTrue()->and(LabTechnicianRateResource::canViewAny())->toBeFalse();
    $this->actingAs($administrator);
    expect(LabCaseResource::canViewAny())->toBeFalse();
});

test('owner lab pages render and technician is restricted to the lab cases flow', function () {
    $owner = labUser('Owner', User::ROLE_OWNER);
    Livewire::actingAs($owner)->test(ListLabCases::class)->assertSuccessful();
    Livewire::actingAs($owner)->test(CreateLabCase::class)->assertSuccessful();
    Livewire::actingAs($owner)->test(ListLabTechnicianRates::class)->assertSuccessful();
    Livewire::actingAs($owner)->test(LabSalaries::class)->assertSuccessful();

    $technician = labUser('Technician', User::ROLE_LAB_TECHNICIAN);
    Livewire::actingAs($technician)->test(ListLabCases::class)->assertSuccessful();
});

test('technician salary includes traceable completed work and additional work', function () {
    $tech = labUser('Alex', User::ROLE_LAB_TECHNICIAN);
    labRate($tech, 'zirconia', 'production', 25);
    labRate($tech, 'milling', 'additional', 5);
    $case = labCaseFor(labPatient());
    $case->workItems()->create(['work_type' => 'zirconia', 'component_type' => 'production', 'quantity' => 4, 'technician_id' => $tech->id, 'work_date' => today(), 'status' => 'completed']);
    $case->workItems()->create(['work_type' => 'milling', 'component_type' => 'additional', 'quantity' => 2, 'technician_id' => $tech->id, 'work_date' => today(), 'status' => 'completed']);

    $report = app(LabSalaryService::class)->calculate($tech->id, today()->toDateString(), today()->toDateString());
    expect($report['items'])->toHaveCount(2)->and($report['total'])->toBe(110.0);
});

test('same case pays zircon design once and suppresses pmma design while new case pays both', function () {
    $tech = labUser('Designer', User::ROLE_LAB_TECHNICIAN);
    labRate($tech, 'pmma', 'design', 5);
    labRate($tech, 'zirconia', 'design', 10);
    $patient = labPatient();
    $pmma = labCaseFor($patient);
    $zirconSame = labCaseFor($patient, related: $pmma, relationship: 'same_case');
    $pmma->workItems()->create(['work_type' => 'pmma', 'component_type' => 'design', 'quantity' => 24, 'technician_id' => $tech->id, 'work_date' => today(), 'status' => 'completed']);
    $zirconSame->workItems()->create(['work_type' => 'zirconia', 'component_type' => 'design', 'quantity' => 24, 'technician_id' => $tech->id, 'work_date' => today(), 'status' => 'completed']);

    $same = app(LabSalaryService::class)->calculate($tech->id, today()->toDateString(), today()->toDateString());
    expect($same['items'])->toHaveCount(1)->and($same['total'])->toBe(240.0);

    $zirconSame->update(['case_relationship' => 'new_case']);
    $new = app(LabSalaryService::class)->calculate($tech->id, today()->toDateString(), today()->toDateString());
    expect($new['items'])->toHaveCount(2)->and($new['total'])->toBe(360.0);
});

test('settlement stores exact items excludes them and undo reopens only those items', function () {
    $owner = labUser('Owner', User::ROLE_OWNER);
    $tech = labUser('Alex', User::ROLE_LAB_TECHNICIAN);
    labRate($tech, 'pmma', 'production', 5);
    $case = labCaseFor(labPatient());
    $item = $case->workItems()->create(['work_type' => 'pmma', 'component_type' => 'production', 'quantity' => 3, 'technician_id' => $tech->id, 'work_date' => today(), 'status' => 'completed']);
    $service = app(LabSalaryService::class);
    $settlement = $service->settle($tech->id, today()->toDateString(), today()->toDateString(), $owner->id);

    expect($settlement->salary_total)->toEqual('15.00')
        ->and($settlement->items()->where('lab_work_item_id', $item->id)->exists())->toBeTrue()
        ->and($service->eligibleItems($tech->id, today()->toDateString(), today()->toDateString()))->toBeEmpty();

    $service->undo($settlement);
    expect(LabSalarySettlement::find($settlement->id))->toBeNull()
        ->and($service->eligibleItems($tech->id, today()->toDateString(), today()->toDateString())->pluck('id')->all())->toBe([$item->id]);
});

test('pending work is not salary eligible', function () {
    $tech = labUser('Alex', User::ROLE_LAB_TECHNICIAN);
    labRate($tech, 'pmma', 'production', 5);
    labCaseFor(labPatient())->workItems()->create(['work_type' => 'pmma', 'component_type' => 'production', 'quantity' => 1, 'technician_id' => $tech->id, 'work_date' => today(), 'status' => 'pending']);
    expect(app(LabSalaryService::class)->eligibleItems($tech->id, today()->toDateString(), today()->toDateString()))->toBeEmpty();
});
