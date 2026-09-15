<?php

use App\Filament\Pages\LabSalaries;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Filament\Resources\LabTechnicianRates\LabTechnicianRateResource;
use App\Filament\Resources\LabTechnicianRates\Pages\ListLabTechnicianRates;
use App\Filament\Resources\LabTechnicians\LabTechnicianResource;
use App\Filament\Resources\LabTechnicians\Pages\ListLabTechnicians;
use App\Filament\Resources\LabTechnicians\Pages\ViewLabTechnician;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\FinanceTransaction;
use App\Models\LabCase;
use App\Models\LabSalarySettlement;
use App\Models\LabTechnicianRate;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\SalarySettlementItem;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use App\Services\FinanceUsdUsageService;
use App\Services\LabPartyAutocomplete;
use App\Services\LabSalaryFunding;
use App\Services\LabSalaryService;
use App\Services\SalarySettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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
    $case = labCaseFor(labPatient());
    expect(LabCaseResource::canViewAny())->toBeTrue()
        ->and(LabCaseResource::canCreate())->toBeTrue()
        ->and(LabCaseResource::canEdit($case))->toBeTrue()
        ->and(LabCaseResource::canDelete($case))->toBeFalse()
        ->and(LabTechnicianRateResource::canViewAny())->toBeFalse();
    $this->actingAs($administrator);
    expect(LabCaseResource::canViewAny())->toBeFalse();
});

test('owner lab pages render and technician is restricted to the lab cases flow', function () {
    $owner = labUser('Owner', User::ROLE_OWNER);
    Livewire::actingAs($owner)->test(ListLabCases::class)->assertSuccessful();
    Livewire::actingAs($owner)->test(ListLabTechnicianRates::class)->assertSuccessful();
    Livewire::actingAs($owner)->test(LabSalaries::class)->assertSuccessful();

    $technician = labUser('Technician', User::ROLE_LAB_TECHNICIAN);
    Livewire::actingAs($technician)->test(ListLabCases::class)->assertSuccessful();
});

test('laboratory technicians use existing employee records in their dedicated resource', function () {
    app()->setLocale('ka');
    $owner = labUser('Owner Technician Manager', User::ROLE_OWNER);
    $technicianPosition = EmployeePosition::create(['name' => 'Laboratory technician', 'is_active' => true, 'is_technician' => true]);
    $officePosition = EmployeePosition::create(['name' => 'Office', 'is_active' => true, 'is_technician' => false]);
    $technician = Employee::create([
        'first_name' => 'Alexi', 'last_name' => 'Technician', 'position_id' => $technicianPosition->id,
        'is_active' => true, 'salary_type' => 'performance', 'salary_active' => true,
    ]);
    $officeEmployee = Employee::create([
        'first_name' => 'Office', 'last_name' => 'Employee', 'position_id' => $officePosition->id, 'is_active' => true,
    ]);
    $rate = $technician->salaryRates()->create([
        'work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true,
    ]);
    $case = labCaseFor(labPatient());
    $work = $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 2, 'technician_id' => $technician->id]);

    $this->actingAs($owner);

    expect(__('lab.navigation.cases'))->toBe('სამუშაო')
        ->and(__('lab.navigation.technicians'))->toBe('ტექნიკები')
        ->and(LabTechnicianResource::canViewAny())->toBeTrue()
        ->and(LabTechnicianResource::getEloquentQuery()->pluck('employees.id')->all())->toBe([$technician->id])
        ->and(EmployeeResource::getEloquentQuery()->pluck('employees.id')->all())->toBe([$officeEmployee->id])
        ->and(LabTechnicianRateResource::shouldRegisterNavigation())->toBeFalse()
        ->and(LabSalaries::shouldRegisterNavigation())->toBeFalse()
        ->and($work->fresh()->technician_id)->toBe($technician->id)
        ->and($rate->fresh()->employee_id)->toBe($technician->id);

    app()->setLocale('en');
    expect(__('lab.navigation.cases'))->toBe('Work')
        ->and(__('lab.navigation.technicians'))->toBe('Technicians');
    app()->setLocale('ka');

    Livewire::actingAs($owner)->test(ListLabTechnicians::class)
        ->assertSuccessful()
        ->assertSee('Alexi Technician');
    Livewire::actingAs($owner)->test(ViewLabTechnician::class, ['record' => $technician->id])
        ->assertSuccessful()
        ->assertSee('Alexi Technician')
        ->assertSee('25.00 ₾');

    $this->actingAs(labUser('Restricted Technician', User::ROLE_LAB_TECHNICIAN));
    expect(LabTechnicianResource::canViewAny())->toBeFalse();
    $this->actingAs(labUser('Restricted Admin', User::ROLE_ADMINISTRATOR));
    expect(LabTechnicianResource::canViewAny())->toBeFalse();
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
    PatientGroup::query()->firstOrCreate(['slug' => PatientGroup::ISRAEL_PARTNER_SLUG], ['name' => 'Israeli', 'is_active' => true]);
    $fundingPatient = Patient::create(['first_name' => 'Funding', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $fundingPatient->partnerPayments()->create(['amount' => 10, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now()]);
    app(FinanceUsdUsageService::class)->record([
        'source' => 'israeli', 'usage_type' => 'exchange_only', 'usd_amount' => 10,
        'exchange_rate' => 2.5, 'received_gel_amount' => 25, 'transacted_at' => now(),
    ]);
    $service = app(LabSalaryService::class);
    $settlement = $service->settle($tech->id, today()->toDateString(), today()->toDateString(), $owner->id);
    $expense = $settlement->financeExpense;

    expect($settlement->salary_total)->toEqual('15.00')
        ->and($settlement->actual_paid_gel)->toEqual('15.00')
        ->and($settlement->israeli_cash_gel)->toEqual('15.00')
        ->and($settlement->items()->where('lab_work_item_id', $item->id)->exists())->toBeTrue()
        ->and($expense->description)->toBe('Laboratory salary')
        ->and((float) $expense->amount)->toBe(15.0)
        ->and($expense->israeliCashMovement)->not->toBeNull()
        ->and(PartnerFinanceTransaction::where('type', PartnerFinanceTransaction::TYPE_EXPENSE)->count())->toBe(0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli'))->toBe(['GEL' => 10.0, 'USD' => 0.0])
        ->and($service->eligibleItems($tech->id, today()->toDateString(), today()->toDateString()))->toBeEmpty();

    app(LabSalaryFunding::class)->pay($settlement);
    expect(FinanceTransaction::where('type', 'expense')->count())->toBe(1)
        ->and(PartnerFinanceTransaction::where('type', PartnerFinanceTransaction::TYPE_SALARY_CASH)->count())->toBe(1);

    $service->undo($settlement);
    expect(LabSalarySettlement::find($settlement->id)->status)->toBe('undone')
        ->and(FinanceTransaction::where('type', 'income')->count())->toBe(1)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli'))->toBe(['GEL' => 25.0, 'USD' => 0.0])
        ->and($service->eligibleItems($tech->id, today()->toDateString(), today()->toDateString())->pluck('id')->all())->toBe([$item->id]);

    $service->undo($settlement);
    expect(FinanceTransaction::where('type', 'income')->count())->toBe(1);
});

test('technician salary finalize is blocked atomically when Israeli GEL cash is insufficient', function () {
    $tech = labUser('No Cash Tech', User::ROLE_LAB_TECHNICIAN);
    labRate($tech, 'pmma', 'production', 5);
    $case = labCaseFor(labPatient());
    $item = $case->workItems()->create(['work_type' => 'pmma', 'component_type' => 'production', 'quantity' => 3, 'technician_id' => $tech->id, 'work_date' => today(), 'status' => 'completed']);

    expect(fn () => app(LabSalaryService::class)->settle($tech->id, today()->toDateString(), today()->toDateString()))
        ->toThrow(ValidationException::class)
        ->and(LabSalarySettlement::query()->count())->toBe(0)
        ->and(FinanceTransaction::query()->count())->toBe(0)
        ->and(app(LabSalaryService::class)->eligibleItems($tech->id, today()->toDateString(), today()->toDateString())->pluck('id')->all())->toBe([$item->id]);
});

test('pending work is not salary eligible', function () {
    $tech = labUser('Alex', User::ROLE_LAB_TECHNICIAN);
    labRate($tech, 'pmma', 'production', 5);
    labCaseFor(labPatient())->workItems()->create(['work_type' => 'pmma', 'component_type' => 'production', 'quantity' => 1, 'technician_id' => $tech->id, 'work_date' => today(), 'status' => 'pending']);
    expect(app(LabSalaryService::class)->eligibleItems($tech->id, today()->toDateString(), today()->toDateString()))->toBeEmpty();
});

test('owner creates compact laboratory work with detected clinic source and additional rows', function () {
    $owner = labUser('Owner', User::ROLE_OWNER);
    $position = EmployeePosition::query()->where('name', 'Technician')->sole();
    $technician = Employee::create(['first_name' => 'Lab', 'last_name' => 'Tech', 'position_id' => $position->id, 'is_active' => true]);
    $patient = labPatient();
    $doctor = Doctor::create(['first_name' => 'Lab', 'last_name' => 'Sheet Doctor', 'is_active' => true]);

    $component = Livewire::actingAs($owner)->test(ListLabCases::class)
        ->mountAction('create')
        ->fillForm([
            'case_date' => today()->toDateString(),
            'doctor_id' => $doctor->getKey(),
            'doctor_search' => $doctor->full_name,
            'patient_id' => $patient->getKey(),
            'patient_search' => $patient->lab_selection_label,
            'source' => 'clinic',
            'mainWorks' => [[
                'doctor_search' => $doctor->full_name, 'patient_search' => $patient->lab_selection_label,
                'material' => 'pmma', 'quantity' => 12, 'shade' => 'A1',
            ], [
                'material' => 'zircon', 'quantity' => 12, 'shade' => 'A2',
            ]],
            'additionalWorks' => [[
                'work_type' => 'milling', 'quantity' => 2,
                'technician_id' => $technician->getKey(), 'note' => 'Anterior',
            ], [
                'work_type' => 'individual_abutment', 'quantity' => 1,
                'technician_id' => $technician->getKey(), 'note' => null,
            ]],
            'notes' => 'Spreadsheet entry',
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertActionNotMounted();

    $case = LabCase::query()->with(['mainWorks', 'additionalWorks'])->sole();
    $component->assertCanSeeTableRecords([$case]);
    expect($case->patient->is($patient))->toBeTrue()
        ->and($case->doctor->is($doctor))->toBeTrue()
        ->and($case->source)->toBe('clinic')
        ->and($case->mainWorks)->toHaveCount(2)
        ->and($case->mainWorks->pluck('material')->all())->toBe(['pmma', 'zircon'])
        ->and($case->mainWorks->pluck('quantity')->all())->toBe([12, 12])
        ->and($case->material)->toBe('pmma')
        ->and($case->quantity)->toBe(12)
        ->and($case->created_by)->toBe($owner->getKey())
        ->and($case->additionalWorks)->toHaveCount(2)
        ->and($case->additionalWorks->first()->work_type)->toBe('milling')
        ->and($case->additionalWorks->first()->technicianEmployee->is($technician))->toBeTrue()
        ->and($case->workItems)->toHaveCount(0)
        ->and(Visit::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(LabSalarySettlement::query()->count())->toBe(0);
});

test('laboratory work detects Israeli source and preserves explicit external source', function () {
    PatientGroup::query()->firstOrCreate(
        ['slug' => PatientGroup::ISRAEL_PARTNER_SLUG],
        ['name' => 'Israeli', 'is_active' => true],
    );
    $israeli = Patient::create([
        'first_name' => 'Israeli', 'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    $detected = LabCase::create([
        'patient_id' => $israeli->getKey(), 'case_date' => today(),
        'material' => 'pmma', 'quantity' => 1,
    ]);
    $external = LabCase::create([
        'patient_id' => $israeli->getKey(), 'case_date' => today(),
        'source' => 'external', 'external_doctor_name' => 'Outside Doctor',
        'material' => 'other', 'quantity' => 2,
    ]);

    expect($detected->source)->toBe('israeli')
        ->and($external->source)->toBe('external')
        ->and($external->doctor_display)->toBe('Outside Doctor');
});

test('laboratory work labels are available in Georgian and English', function () {
    app()->setLocale('ka');
    expect(__('lab.work_details'))->toBe('ლაბორატორიული სამუშაო')
        ->and(__('lab.add_additional_work'))->toBe('+ დამატებითი');

    app()->setLocale('en');
    expect(__('lab.work_details'))->toBe('Laboratory Work')
        ->and(__('lab.add_additional_work'))->toBe('+ Additional');
});

test('laboratory patient lookup uses manual or generated Latin names without changing the ERP name', function () {
    $latin = Patient::create([
        'first_name' => 'მოშე', 'last_name' => 'ლევი', 'lab_display_name' => 'Moshe Levi', 'birth_date' => '1968-03-14',
    ]);
    $fallback = Patient::create(['first_name' => 'ანა', 'last_name' => 'ბერიძე']);

    expect(Patient::query()->searchForLab('levi')->sole()->is($latin))->toBeTrue()
        ->and(Patient::query()->searchForLab('მოშე')->sole()->is($latin))->toBeTrue()
        ->and($latin->lab_name)->toBe('Moshe Levi')
        ->and($latin->full_name)->toBe('მოშე ლევი')
        ->and($fallback->lab_name)->toBe('Ana Beridze');

    $autocomplete = app(LabPartyAutocomplete::class);
    expect($autocomplete->patientSuggestions('Moshe'))->toContain('Moshe Levi — 14.03.1968')
        ->and($autocomplete->patientIdFromLabel('Moshe Levi — 14.03.1968'))->toBe($latin->id);
});

test('laboratory text autocompletes select the correct patient and doctor ids', function () {
    $owner = labUser('Autocomplete Owner', User::ROLE_OWNER);
    $patient = Patient::create([
        'first_name' => 'Avraham', 'last_name' => 'Cohen', 'birth_date' => '1968-03-14', 'lab_display_name' => 'Abraham Cohen',
    ]);
    $doctor = Doctor::create(['first_name' => 'Nino', 'last_name' => 'Doctor', 'is_active' => true]);
    $autocomplete = app(LabPartyAutocomplete::class);

    expect($autocomplete->patientSuggestions('Cohen'))->toContain('Abraham Cohen — 14.03.1968')
        ->and($autocomplete->doctorSuggestions('Nino'))->toContain('Nino Doctor');

    Livewire::actingAs($owner)->test(ListLabCases::class)
        ->mountAction('create')
        ->fillForm([
            'case_date' => today()->toDateString(),
            'mainWorks' => [[
                'patient_search' => 'Abraham Cohen — 14.03.1968',
                'doctor_search' => 'Nino Doctor',
                'material' => 'pmma', 'quantity' => 1, 'shade' => null,
            ]],
        ])
        ->assertActionDataSet(['patient_id' => $patient->id, 'doctor_id' => $doctor->id])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $case = LabCase::query()->sole();
    expect($case->patient_id)->toBe($patient->id)->and($case->doctor_id)->toBe($doctor->id);
});

test('laboratory doctor lookup matches either script while preserving stored names', function () {
    $georgian = Doctor::create(['first_name' => 'შალვა', 'last_name' => 'ბერძული', 'is_active' => true]);
    $latin = Doctor::create(['first_name' => 'Nodar', 'last_name' => 'Elishakovi', 'is_active' => true]);
    $autocomplete = app(LabPartyAutocomplete::class);

    expect($autocomplete->doctorSuggestions('Shalva'))->toContain('შალვა ბერძული')
        ->and($autocomplete->doctorSuggestions('შალვა'))->toContain('შალვა ბერძული')
        ->and($autocomplete->doctorIdFromLabel('Shalva Berdzuli'))->toBe($georgian->id)
        ->and($autocomplete->doctorSuggestions('Nodar'))->toContain('Nodar Elishakovi')
        ->and($autocomplete->doctorSuggestions('ნოდარ'))->toContain('Nodar Elishakovi')
        ->and($autocomplete->doctorIdFromLabel('ნოდარ ელიშაკოვი'))->toBe($latin->id)
        ->and($autocomplete->doctorIdFromLabel('Nodar Elishakovi'))->toBe($latin->id)
        ->and(Doctor::query()->count())->toBe(2)
        ->and($georgian->fresh()->full_name)->toBe('შალვა ბერძული')
        ->and($latin->fresh()->full_name)->toBe('Nodar Elishakovi');
});

test('laboratory saves free text as a shared patient and links it automatically', function () {
    $owner = labUser('Automatic Patient Owner', User::ROLE_OWNER);

    Livewire::actingAs($owner)->test(ListLabCases::class)->mountAction('create')
        ->fillForm([
            'source' => 'clinic',
            'mainWorks' => [[
                'patient_search' => 'Sara Levi', 'material' => 'pmma', 'quantity' => 1,
            ]],
        ])->callMountedAction()->assertHasNoActionErrors();

    $patient = Patient::query()->where('first_name', 'Sara')->where('last_name', 'Levi')->sole();
    expect(LabCase::query()->sole()->patient_id)->toBe($patient->id)
        ->and($patient->patientGroup?->slug)->toBe(PatientGroup::CLINIC_SLUG);
});

test('laboratory patient resolver reuses exact matches and blocks ambiguous names', function () {
    $service = app(LabPartyAutocomplete::class);
    $first = Patient::create(['first_name' => 'Daniel', 'last_name' => 'Cohen', 'birth_date' => '1980-01-01']);

    expect($service->resolvePatientForLab(null, 'Daniel Cohen — 01.01.1980', 'clinic')->is($first))->toBeTrue()
        ->and(Patient::query()->where('first_name', 'Daniel')->where('last_name', 'Cohen')->count())->toBe(1);

    Patient::create(['first_name' => 'Daniel', 'last_name' => 'Cohen', 'birth_date' => '1990-01-01']);
    expect(fn () => $service->resolvePatientForLab(null, 'Daniel Cohen', 'clinic'))
        ->toThrow(ValidationException::class);
});

test('laboratory create and create new actions close or reset as requested', function () {
    $owner = labUser('Rapid Entry Owner', User::ROLE_OWNER);
    $patient = labPatient();
    $data = [
        'mainWorks' => [[
            'patient_search' => $patient->lab_selection_label,
            'material' => 'pmma', 'quantity' => 2, 'shade' => 'A1',
        ]],
    ];

    Livewire::actingAs($owner)->test(ListLabCases::class)
        ->mountAction('create')->fillForm($data)->callMountedAction()->assertActionNotMounted();

    Livewire::actingAs($owner)->test(ListLabCases::class)
        ->mountAction('create')->fillForm($data)->callMountedAction(['another' => true])
        ->assertActionMounted('create');

    expect(LabCase::query()->count())->toBe(2);
});

test('compact modal omits main-work technician fields and uses day month year dates', function () {
    $technician = labUser('Daily Modeler', User::ROLE_LAB_TECHNICIAN);

    $component = Livewire::actingAs($technician)->test(ListLabCases::class)
        ->mountAction('create')
        ->assertActionDataSet([
            'case_date' => today()->toDateString(),
        ])
        ->assertMountedActionModalSee([
            today()->format('d.m.Y'),
            __('lab.add_additional_work'),
            __('lab.create'),
            __('lab.create_another'),
            __('lab.cancel'),
        ]);

    $modalData = data_get($component->get('mountedActions'), '0.data', []);
    expect($modalData)->not->toHaveKeys(['modeled_by', 'milling_quantity', 'milled_by']);

    expect(today()->format('d.m.Y'))->toMatch('/^\d{2}\.\d{2}\.\d{4}$/');
});

test('only active technician employees are available for laboratory assignments', function () {
    $technician = EmployeePosition::query()->where('name', 'Technician')->sole();
    $administrator = EmployeePosition::query()->where('name', 'Administrator')->sole();
    $inactivePosition = EmployeePosition::create(['name' => 'Old Technician', 'is_active' => false, 'is_technician' => true]);
    $active = Employee::create(['first_name' => 'Active', 'last_name' => 'Tech', 'position_id' => $technician->id, 'is_active' => true]);
    Employee::create(['first_name' => 'Inactive', 'last_name' => 'Tech', 'position_id' => $technician->id, 'is_active' => false]);
    Employee::create(['first_name' => 'Active', 'last_name' => 'Admin', 'position_id' => $administrator->id, 'is_active' => true]);
    Employee::create(['first_name' => 'Old', 'last_name' => 'Tech', 'position_id' => $inactivePosition->id, 'is_active' => true]);

    expect(Employee::query()->activeTechnicians()->pluck('id')->all())->toBe([$active->id]);
});

test('laboratory source defaults from patient group but remains an independent work snapshot', function () {
    $clinic = labPatient();
    PatientGroup::query()->firstOrCreate(['slug' => PatientGroup::ISRAEL_PARTNER_SLUG], ['name' => 'Israeli', 'is_active' => true]);
    $israeli = Patient::create(['first_name' => 'Israel', 'last_name' => 'Source', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $doctor = Doctor::create(['first_name' => 'Source', 'last_name' => 'Doctor', 'is_active' => true]);

    $clinicCase = LabCase::create(['patient_id' => $clinic->id, 'case_date' => today(), 'source' => 'clinic']);
    $israeliCase = LabCase::create(['patient_id' => $israeli->id, 'doctor_id' => $doctor->id, 'case_date' => today(), 'source' => 'israeli']);
    $externalCase = LabCase::create(['patient_id' => $clinic->id, 'case_date' => today(), 'source' => 'external']);

    expect(LabCase::create(['patient_id' => $clinic->id, 'case_date' => today(), 'source' => 'israeli'])->source)
        ->toBe('israeli')
        ->and($clinicCase->source)->toBe('clinic')->and($israeliCase->source)->toBe('israeli')
        ->and($externalCase->source)->toBe('external');

    $clinic->patientGroup()->associate(PatientGroup::israelPartnerId())->save();
    expect($clinicCase->fresh()->source)->toBe('clinic')->and($externalCase->fresh()->source)->toBe('external');
});

test('added main work copies all previous row values except toggled material and remains editable', function () {
    $owner = labUser('Defaults Owner', User::ROLE_OWNER);
    $patient = labPatient();
    $doctor = Doctor::create(['first_name' => 'Copy', 'last_name' => 'Doctor', 'is_active' => true]);
    $technician = Employee::create(['first_name' => 'Copy', 'last_name' => 'Technician',
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id, 'is_active' => true]);
    $component = Livewire::actingAs($owner)->test(ListLabCases::class)->mountAction('create')
        ->fillForm(['mainWorks' => [[
            'doctor_search' => $doctor->full_name,
            'patient_search' => $patient->lab_selection_label,
            'material' => 'pmma', 'quantity' => 12, 'shade' => 'A1', 'technician_id' => $technician->id,
        ]]])
        ->callFormComponentAction('mainWorks', 'add', formName: 'mountedActionSchema0');

    $works = array_values(data_get($component->get('mountedActions'), '0.data.mainWorks'));
    expect($works)->toHaveCount(2)->and($works[1]['material'])->toBe('zircon')
        ->and($works[1]['quantity'])->toBe(12)->and($works[1]['shade'])->toBe('A1')
        ->and($works[1]['doctor_search'])->toBe($doctor->full_name)
        ->and($works[1]['patient_search'])->toBe($patient->lab_selection_label)
        ->and($works[1]['technician_id'])->toBe($technician->id);

    $keys = array_keys(data_get($component->get('mountedActions'), '0.data.mainWorks'));
    $component->set('mountedActions.0.data.mainWorks.'.$keys[1].'.quantity', 24)
        ->set('mountedActions.0.data.mainWorks.'.$keys[1].'.shade', 'B2')
        ->callFormComponentAction('mainWorks', 'add', formName: 'mountedActionSchema0');
    $works = array_values(data_get($component->get('mountedActions'), '0.data.mainWorks'));
    expect($works[0]['quantity'])->toBe(12)->and($works[1]['quantity'])->toBe(24)
        ->and($works[2]['material'])->toBe('pmma')->and($works[2]['quantity'])->toBe(24)
        ->and($works[2]['shade'])->toBe('B2')->and($works[2]['technician_id'])->toBe($technician->id);
});

test('Israeli zircon lab work uses configured doctor rates and settlement snapshots', function () {
    PatientGroup::query()->firstOrCreate(['slug' => PatientGroup::ISRAEL_PARTNER_SLUG], ['name' => 'Israeli', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Salary', 'last_name' => 'Lab', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $owner = labUser('Salary Owner', User::ROLE_OWNER);

    foreach ([['David', 'Chumburidze', 100.0], ['Shalva', 'Berdzuli', 100.0], ['Levan', 'Berikashvili', 200.0], ['Nodar', 'Elishakov', 200.0]] as [$first, $last, $rate]) {
        $doctor = Doctor::create(['first_name' => $first, 'last_name' => $last, 'is_active' => true]);
        $case = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'case_date' => today(), 'source' => 'israeli']);
        $zircon = $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 2, 'shade' => 'A2']);
        $case->mainWorks()->create(['material' => 'pmma', 'quantity' => 2, 'shade' => 'A2']);

        $report = app(DoctorCompensationCalculator::class)->calculate($doctor->id, today()->toDateString(), today()->toDateString(), null, null, PatientGroup::ISRAEL_PARTNER_SLUG);
        $labItem = collect($report['details'])->flatMap(fn (array $row): array => $row['items'])->where('source_type', 'lab')->sole();
        expect($labItem['id'])->toBe($zircon->id)->and($labItem['unit_rate'])->toBe($rate)->and($labItem['doctor_share'])->toBe($rate * 2);

        $settlement = app(SalarySettlementService::class)->settle($doctor->id, today()->toDateString(), today()->toDateString(), (float) $doctor->compensation_percentage, $owner->id, null, PatientGroup::ISRAEL_PARTNER_SLUG)[0];
        $snapshot = $settlement->items()->where('lab_main_work_id', $zircon->id)->sole();
        expect((int) $snapshot->quantity_snapshot)->toBe(2)->and((float) $snapshot->unit_rate_snapshot)->toBe($rate)
            ->and((float) $snapshot->doctor_share_snapshot)->toBe($rate * 2)
            ->and(app(DoctorCompensationCalculator::class)->calculate($doctor->id, today()->toDateString(), today()->toDateString(), null, null, PatientGroup::ISRAEL_PARTNER_SLUG)['details'])->toBeEmpty();

        app(SalarySettlementService::class)->undo($settlement->id, $doctor->id);
        expect(SalarySettlementItem::query()->where('lab_main_work_id', $zircon->id)->exists())->toBeFalse()
            ->and(app(DoctorCompensationCalculator::class)->calculate($doctor->id, today()->toDateString(), today()->toDateString(), null, null, PatientGroup::ISRAEL_PARTNER_SLUG)['details'])->toHaveCount(1);
    }
});

test('an explicitly linked Israeli zircon service accrues once from its stable lab item id', function () {
    PatientGroup::query()->firstOrCreate(['slug' => PatientGroup::ISRAEL_PARTNER_SLUG], ['name' => 'Israeli', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Linked', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $doctor = Doctor::create(['first_name' => 'David', 'last_name' => 'Chumburidze', 'is_active' => true]);
    $case = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'case_date' => today(), 'source' => 'israeli']);
    $labWork = $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 2]);
    $catalog = TreatmentCase::create(['name' => 'Linked Zircon', 'category' => 'orthopedics', 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(),
        'currency' => 'GEL', 'total_price' => 500,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $catalog->id, 'lab_main_work_id' => $labWork->id,
        'quantity' => 2, 'unit_price' => 250,
    ]);

    $items = collect(app(DoctorCompensationCalculator::class)->calculate(
        $doctor->id, today()->toDateString(), today()->toDateString(), null, null, PatientGroup::ISRAEL_PARTNER_SLUG,
    )['details'])->flatMap(fn (array $row): array => $row['items']);

    expect($items)->toHaveCount(1)->and($items->sole()['source_type'])->toBe('lab')
        ->and($items->sole()['id'])->toBe($labWork->id)->and($items->sole()['doctor_share'])->toBe(200.0);
});
