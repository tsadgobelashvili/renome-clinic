<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\LabTechnicians\Pages\EditLabTechnician;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use App\Services\EmployeePayrollService;
use App\Services\SalarySettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00'));
    $this->owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->actingAs($this->owner);
    $this->employee = Employee::create([
        'first_name' => 'Nino', 'last_name' => 'Payroll',
        'position_id' => EmployeePosition::query()->where('name', 'Administrator')->sole()->id,
        'is_active' => true, 'salary_payout_day' => 10,
    ]);
    $this->employee->payrollSettings()->create([
        'source' => 'clinic', 'salary_model' => 'fixed_net', 'currency' => 'GEL',
        'default_payment_method' => 'bank_transfer', 'net_amount' => 1300, 'is_active' => true,
    ]);
    $this->doctor = Doctor::create([
        'first_name' => 'Salary', 'last_name' => 'Doctor', 'specialty' => 'Therapy',
        'compensation_percentage' => 40, 'is_active' => true,
    ]);
    $this->visit = payableOverviewVisit($this->doctor);
});

function payableOverviewVisit(Doctor $doctor, float $amount = 1000): Visit
{
    $patient = Patient::create(['first_name' => 'Overview', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::clinicId()]);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => $amount]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Overview work', 'quantity' => 1, 'unit_price' => $amount]);
    $visit->payments()->create(['amount' => $amount, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);

    return $visit;
}

test('doctor compensation page is presented as the unified salaries page', function () {
    expect(DoctorCompensation::getNavigationLabel())->toBe(__('salaries.title'));

    Livewire::test(DoctorCompensation::class)
        ->assertOk()->assertSee(__('salaries.title'))
        ->assertSee($this->doctor->full_name)->assertDontSee($this->employee->full_name)
        ->assertDontSeeHtml('wire:model.live="statusFilter"')
        ->assertDontSeeHtml('wire:model.live="sourceFilter"')
        ->assertDontSeeHtml('wire:model.live="staffTypeFilter"')
        ->assertDontSeeHtml('overviewFrom')
        ->set('staffTypeFilter', 'employees')->assertSee($this->employee->full_name);
});

test('employee payday saves in the valid monthly range', function () {
    Livewire::test(EditEmployee::class, ['record' => $this->employee->id])
        ->fillForm(['salary_payout_day' => 25])->call('save')->assertHasNoFormErrors();

    expect($this->employee->fresh()->salary_payout_day)->toBe(25);
    Livewire::test(EditEmployee::class, ['record' => $this->employee->id])
        ->fillForm(['salary_payout_day' => 32])->call('save')->assertHasFormErrors(['salary_payout_day']);
});

test('employee overview shows the configured calendar payday for its payroll period', function ($day, $periodEnd, $expected) {
    $this->travelTo(CarbonImmutable::parse($periodEnd.' 12:00:00'));
    $this->employee->update(['salary_payout_day' => $day]);
    $this->employee->payrollSettings()->update(['effective_from' => substr($periodEnd, 0, 7).'-01']);

    Livewire::test(DoctorCompensation::class)
        ->set('staffTypeFilter', 'employees')
        ->assertSee(__('salaries.payday'))
        ->assertSee($expected);
})->with([
    'overdue unpaid salary' => [1, '2026-09-10', '01.09.2026'],
    'salary on payday' => [16, '2026-09-16', '16.09.2026'],
    'older unpaid period retains its date' => [16, '2026-08-31', '16.08.2026'],
    'short month' => [31, '2026-02-28', '28.02.2026'],
    'leap year' => [31, '2028-02-29', '29.02.2028'],
]);

test('laboratory technicians are excluded and do not see the payday field', function () {
    $technician = Employee::create([
        'first_name' => 'Lab', 'last_name' => 'Technician',
        'position_id' => EmployeePosition::query()->where('name', 'Technician')->sole()->id,
        'is_active' => true, 'salary_type' => 'performance', 'salary_active' => true,
    ]);
    $technician->payrollSettings()->create([
        'source' => 'clinic', 'salary_model' => 'fixed_net', 'currency' => 'GEL',
        'default_payment_method' => 'cash', 'net_amount' => 900, 'is_active' => true,
    ]);

    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertDontSee($technician->full_name);
    expect(fn () => Livewire::test(DoctorCompensation::class)->call('openEmployeeSalary', $technician->id, 'clinic'))
        ->toThrow(ModelNotFoundException::class);
    Livewire::test(EditLabTechnician::class, ['record' => $technician->id])->assertFormFieldHidden('salary_payout_day');
});

test('staff selector switches between doctors and employees', function () {
    Livewire::test(DoctorCompensation::class)
        ->set('staffTypeFilter', 'employees')
        ->assertSee($this->employee->full_name)->assertDontSee($this->doctor->full_name)
        ->set('staffTypeFilter', 'doctors')->assertSee($this->doctor->full_name);
});

test('paid payroll leaves the employee visible with zero currently payable', function () {
    $entry = app(EmployeePayrollService::class)->finalize(
        $this->employee, 'clinic', today()->startOfMonth()->toDateString(), today()->toDateString()
    );
    $entry->update(['payout_status' => 'paid']);

    Livewire::test(DoctorCompensation::class)
        ->set('staffTypeFilter', 'employees')->assertSee($this->employee->full_name)->assertSee('0.00 ₾')->assertDontSee('1,300.00 ₾');
    $this->travel(1)->days();
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertSee($this->employee->full_name)->assertDontSee('1,300.00 ₾');
});

test('doctor row opens the existing doctor calculation workflow', function () {
    Livewire::test(DoctorCompensation::class)
        ->call('openDoctorSalary', $this->doctor->id, 'clinic')
        ->assertActionMounted('calculateSalary')
        ->assertSet('activeSalaryDoctorId', $this->doctor->id)
        ->assertSet('salaryHistoryDoctorId', null)
        ->assertActionDataSet([
            'patient_group' => 'clinic',
            'from' => today()->toDateString(),
            'until' => today()->toDateString(),
        ])
        ->assertMountedActionModalSee([$this->doctor->full_name, __('salaries.history')]);
});

test('doctor salary history is opt in inside the shared modal', function () {
    Livewire::test(DoctorCompensation::class)
        ->call('openDoctorSalary', $this->doctor->id, 'clinic')
        ->assertActionMounted('calculateSalary')
        ->assertSet('salaryHistoryDoctorId', null)
        ->call('toggleDoctorSalaryHistory', $this->doctor->id)
        ->assertActionMounted('calculateSalary')
        ->assertSet('salaryHistoryDoctorId', $this->doctor->id)
        ->call('toggleDoctorSalaryHistory', $this->doctor->id)
        ->assertSet('salaryHistoryDoctorId', null);
});

test('employees preview their configured salary before payday without generated entries', function () {
    $this->employee->update(['salary_payout_day' => 16]);
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertSee($this->employee->full_name)
        ->assertSee('16.09.2026')->assertSee('1,300.00 ₾')->assertDontSee(__('salaries.salary_not_configured'));
    expect($this->employee->payrollEntries()->count())->toBe(0);
    $this->travel(6)->days();
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertSee($this->employee->full_name)->assertSee('16.09.2026');
});

test('both administrators appear even when the second has no payroll settings or history', function () {
    $second = Employee::create(['first_name' => 'Second', 'last_name' => 'Administrator', 'position_id' => $this->employee->position_id, 'is_active' => true]);
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')
        ->assertSee($this->employee->full_name)->assertSee($second->full_name)
        ->assertSee('1,300.00 ₾')->assertSee(__('salaries.salary_not_configured'))
        ->assertViewHas('staffRows', fn ($rows) => $rows->count() === 2
            && $rows->firstWhere('id', $second->id)['payday'] === null
            && $rows->firstWhere('id', $second->id)['salary_configured'] === false);
});

test('incomplete and zero salary rules remain visible and are distinguished', function ($model, $field) {
    $this->employee->payrollSettings()->update(['salary_model' => $model, $field => null]);
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')
        ->assertSee($this->employee->full_name)->assertSee(__('salaries.salary_not_configured'));
    $this->employee->payrollSettings()->update([$field => 0]);
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')
        ->assertSee($this->employee->full_name)->assertSee('0.00 ₾')->assertDontSee(__('salaries.salary_not_configured'));
})->with([
    ['fixed_net', 'net_amount'], ['fixed_gross', 'gross_amount'],
    ['percentage', 'percentage_rate'], ['per_unit', 'per_unit_amount'],
]);

test('missing payday and a salary configured only for Israeli do not hide an employee', function () {
    $this->employee->update(['salary_payout_day' => null]);
    $this->employee->payrollSettings()->update(['source' => 'israeli']);
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')
        ->assertSee($this->employee->full_name)->assertSee('1,300.00 ₾')
        ->assertViewHas('staffRows', fn ($rows) => $rows->sole()['payday'] === null);
});

test('unconfigured employee roster adds no per employee queries and excludes inactive employees', function () {
    $page = Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees');
    $measure = function () use ($page) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('$refresh');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };
    $baseline = $measure();
    foreach (range(1, 10) as $number) {
        Employee::create(['first_name' => 'Unconfigured', 'last_name' => (string) $number, 'position_id' => $this->employee->position_id, 'is_active' => true]);
    }
    $inactive = Employee::create(['first_name' => 'Inactive', 'last_name' => 'Administrator', 'position_id' => $this->employee->position_id, 'is_active' => false]);
    expect($measure())->toBe($baseline);
    $page->assertDontSee($inactive->full_name)->assertViewHas('staffRows', fn ($rows) => $rows->count() === 11);
});

test('employee row lazily loads payroll details without loading technician payroll', function () {
    $component = Livewire::test(DoctorCompensation::class);
    expect($component->get('employeeDetail'))->toBeNull();

    $component->call('openEmployeeSalary', $this->employee->id, 'clinic')
        ->assertSet('employeeDetail.employee_id', $this->employee->id)
        ->assertSet('employeeDetail.net_amount', 1300.0)
        ->assertSee(__('salaries.employee_details'))
        ->assertSee('1,300.00 ₾');
});

test('overview query count stays constant as employee count grows', function () {
    $position = EmployeePosition::query()->where('name', 'Assistant')->sole();
    foreach (range(1, 5) as $number) {
        $employee = Employee::create(['first_name' => 'Assistant', 'last_name' => (string) $number, 'position_id' => $position->id, 'is_active' => true]);
        $employee->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'fixed_net', 'currency' => 'GEL', 'default_payment_method' => 'cash', 'net_amount' => 500, 'is_active' => true]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees');
    $selects = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'))->count();

    DB::disableQueryLog();
    foreach (range(1, 10) as $number) {
        $employee = Employee::create(['first_name' => 'Extra', 'last_name' => (string) $number, 'position_id' => $position->id, 'is_active' => true, 'salary_payout_day' => 16]);
        $employee->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'fixed_net', 'currency' => 'GEL', 'default_payment_method' => 'cash', 'net_amount' => 500, 'is_active' => true]);
        Doctor::create(['first_name' => 'Extra', 'last_name' => (string) $number, 'is_active' => true, 'compensation_percentage' => 40]);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees');
    $grownSelects = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_starts_with(strtolower(ltrim($query['query'])), 'select'))->count();
    DB::disableQueryLog();
    // The shared cycle adds fixed batched reads, independent of roster size.
    expect($grownSelects)->toBe($selects);
});

test('doctor compact totals match shared clinic rules including expenses exclusions and discounts', function () {
    $this->visit->treatmentCaseItems()->first()->directExpenses()->create(['name' => 'Material', 'amount' => 125, 'currency' => 'GEL']);
    $this->visit->payments()->update(['amount' => 500]);
    $excluded = TreatmentCase::create(['name' => 'Consultation', 'category' => 'consultation', 'is_active' => true]);
    $this->visit->treatmentCaseItems()->create(['treatment_case_id' => $excluded->id, 'quantity' => 1, 'unit_price' => 100]);
    $discount = payableOverviewVisit($this->doctor, 200);
    $discount->payments()->delete();
    $discount->update(['discount_type' => 'percent', 'discount_value' => 100, 'discount_reason' => 'gift']);
    $calculator = app(DoctorCompensationCalculator::class);
    $modal = $calculator->calculate($this->doctor->id, '1900-01-01', today()->toDateString(), patientGroup: PatientGroup::CLINIC_SLUG);
    $summary = $calculator->payableSummaries(collect([$this->doctor]));

    expect($summary[$this->doctor->id]['clinic']['GEL'])->toBe($modal['totals']['GEL']['doctor_share'])->toBe(150.0);
    Livewire::test(DoctorCompensation::class)->assertSee('150.00 ₾')->assertDontSee('Overview work');
});

test('doctor overview combines both sources and excludes finalized work while retaining new same day work', function () {
    $this->doctor->update(['israeli_lab_zircon_rate' => 100]);
    $patient = Patient::create(['first_name' => 'Israeli', 'last_name' => 'Overview', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $case = LabCase::create(['doctor_id' => $this->doctor->id, 'patient_id' => $patient->id, 'source' => 'israeli', 'case_date' => today()]);
    $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 2]);
    $calculator = app(DoctorCompensationCalculator::class);
    $summary = $calculator->payableSummaries(collect([$this->doctor]));
    $modal = $calculator->calculate($this->doctor->id, '1900-01-01', today()->toDateString(), patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG, israeliLabOnly: true);
    expect($summary[$this->doctor->id]['israeli']['GEL'])->toBe($modal['totals']['GEL']['doctor_share'])->toBe(200.0);
    $page = Livewire::test(DoctorCompensation::class)->assertSee('400.00 ₾')->assertSee('200.00 ₾');
    $page->assertViewHas('staffRows', fn ($rows) => $rows->count() === 1);

    app(SalarySettlementService::class)->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, $this->owner->id, patientGroup: PatientGroup::CLINIC_SLUG);
    $page->call('$refresh')->assertDontSee('400.00 ₾')->assertSee('200.00 ₾');
    payableOverviewVisit($this->doctor, 250);
    $page->call('$refresh')->assertSee('100.00 ₾')->assertSee('200.00 ₾');
    expect($calculator->payableSummaries(collect([$this->doctor]))[$this->doctor->id]['clinic']['GEL'])->toBe(100.0);
    $patient->partnerPayments()->create(['amount' => 500, 'currency' => 'GEL', 'payment_method' => 'cash', 'paid_at' => now()]);
    app(SalarySettlementService::class)->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, $this->owner->id, patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG, israeliLabOnly: true);
    $page->call('$refresh')->assertDontSee('200.00 ₾')->assertSee('100.00 ₾');
});

test('finalizing through the shared modal refreshes the payable overview', function () {
    Livewire::test(DoctorCompensation::class)
        ->assertSee($this->doctor->full_name)
        ->call('openDoctorSalary', $this->doctor->id, 'clinic')
        ->callMountedAction()->assertHasNoActionErrors()
        ->assertDontSee($this->doctor->full_name);
});

test('employee fixed gross percentage and per unit previews use the payroll calculator', function ($model, $attributes) {
    $this->employee->payrollSettings()->update(['salary_model' => $model, ...$attributes]);
    $employee = $this->employee->fresh()->load('payrollSettings');
    $service = app(EmployeePayrollService::class);
    $rows = $service->payableSummaries(collect([$employee]));
    $calculation = $service->calculate($employee, 'clinic', '2026-09-01', '2026-09-10');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['net_amount'])->toBe($calculation['net_amount']);
})->with([
    'fixed gross' => ['fixed_gross', ['gross_amount' => 1500, 'employee_deductions' => 100]],
    'percentage' => ['percentage', ['percentage_rate' => 10, 'employee_deductions' => 20]],
    'per unit' => ['per_unit', ['per_unit_amount' => 75, 'employee_deductions' => 5]],
]);

test('employee finalization removes the preview and advances the next calculation past its cutoff', function () {
    $page = Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')
        ->call('openEmployeeSalary', $this->employee->id, 'clinic')->call('finalizeEmployeePayroll')
        ->assertSet('employeeDetail', null)->assertDontSee('1,300.00 ₾')->assertSee('0.00 ₾');
    $entry = $this->employee->payrollEntries()->sole();
    $this->employee->payrollSettings()->update(['net_amount' => 1600]);
    $page->call('$refresh')->assertDontSee('1,300.00 ₾')->assertDontSee('1,600.00 ₾');
    $entry->update(['payout_status' => 'paid']);
    $this->travelTo(CarbonImmutable::parse('2026-10-10 12:00:00'));
    $page->call('$refresh')->call('openEmployeeSalary', $this->employee->id, 'clinic')
        ->assertSet('employeeDetail.period_start', '2026-09-11')
        ->assertSet('employeeDetail.net_amount', 1600.0)->assertSee('10.10.2026');
});

test('an unpaid employee payday survives a calendar month change', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 12:00:00'));
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertSee('10.09.2026')
        ->call('openEmployeeSalary', $this->employee->id, 'clinic')->call('finalizeEmployeePayroll')->assertDontSee('1,300.00 ₾');
});

test('batch doctor summary queries do not grow per doctor or load modal relationships', function () {
    $calculator = app(DoctorCompensationCalculator::class);
    $measure = function ($doctors) use ($calculator) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $calculator->payableSummaries($doctors);
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };
    $baseline = $measure(collect([$this->doctor]));
    $doctors = collect([$this->doctor]);
    foreach (range(1, 10) as $number) {
        $doctor = Doctor::create(['first_name' => 'Batch', 'last_name' => (string) $number, 'compensation_percentage' => 40, 'is_active' => true]);
        payableOverviewVisit($doctor);
        $doctors->push($doctor);
    }
    $queries = $measure($doctors);
    expect($queries->count())->toBe($baseline->count())->toBeLessThan(12);
    expect($queries->contains(fn ($query) => str_starts_with($query['query'], 'select * from "patients"') || str_starts_with($query['query'], 'select * from "payments"')))->toBeFalse();
});

test('variable payroll summaries use a single aggregate query as employee count grows', function () {
    $this->employee->payrollSettings()->update(['salary_model' => 'percentage', 'percentage_rate' => 10]);
    $service = app(EmployeePayrollService::class);
    $measure = function ($employees) use ($service) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = $service->payableSummaries($employees);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$rows, $count];
    };
    [, $baseline] = $measure(collect([$this->employee->fresh()->load('payrollSettings')]));
    foreach (range(1, 10) as $number) {
        $employee = Employee::create(['first_name' => 'Variable', 'last_name' => (string) $number, 'position_id' => $this->employee->position_id, 'is_active' => true, 'salary_payout_day' => 1]);
        $employee->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'per_unit', 'per_unit_amount' => 25, 'currency' => 'GEL', 'default_payment_method' => 'cash', 'is_active' => true]);
    }
    [$rows, $count] = $measure(Employee::with('payrollSettings')->get());
    expect($rows)->toHaveCount(11)->and($count)->toBe($baseline)->toBe(2);
});

test('fixed net previews the configured unpaid amount before the sixteenth', function ($amount) {
    $this->employee->update(['salary_payout_day' => 16]);
    $this->employee->payrollSettings()->update(['net_amount' => $amount, 'effective_from' => '2026-09-01']);
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')
        ->assertSee(number_format($amount, 2).' ₾')->assertSee('16.09.2026')
        ->call('openEmployeeSalary', $this->employee->id, 'clinic')->assertSet('employeeDetail.net_amount', (float) $amount);
    expect($this->employee->payrollEntries()->count())->toBe(0);
})->with([500, 1300]);

test('early finalization does not preview the same monthly salary again before payday', function ($status) {
    $this->employee->update(['salary_payout_day' => 16]);
    $this->employee->payrollSettings()->update(['net_amount' => 500, 'effective_from' => '2026-09-01']);
    $entry = app(EmployeePayrollService::class)->finalize($this->employee, 'clinic', '2026-09-01', '2026-09-10');
    $entry->update(['payout_status' => $status]);
    $this->travel(1)->days();
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertSee('0.00 ₾')->assertDontSee('500.00 ₾');
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertSee('500.00 ₾')->assertSee('16.10.2026');
})->with(['pending', 'paid']);

test('employee preview respects a future effective date and inactive settings', function () {
    $this->employee->payrollSettings()->update(['net_amount' => 500, 'effective_from' => '2026-10-01']);
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertDontSee('500.00 ₾');
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertSee('500.00 ₾');
    $this->employee->payrollSettings()->update(['is_active' => false]);
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertDontSee('500.00 ₾');
});

test('latest active effective setting wins per source when historical settings are loaded', function () {
    $setting = $this->employee->payrollSettings()->sole();
    // The current schema is unique per employee/source; exercise historical collections
    // directly so selection remains deterministic if such records are supplied.
    $older = clone $setting;
    $older->forceFill(['id' => 100, 'effective_from' => '2026-08-01', 'net_amount' => 300]);
    $current = clone $setting;
    $current->forceFill(['id' => 101, 'effective_from' => '2026-09-01', 'net_amount' => 500]);
    $future = clone $setting;
    $future->forceFill(['id' => 102, 'effective_from' => '2026-10-01', 'net_amount' => 900]);
    $inactive = clone $current;
    $inactive->forceFill(['id' => 103, 'is_active' => false, 'net_amount' => 1000]);
    $israeli = clone $current;
    $israeli->forceFill(['id' => 104, 'source' => 'israeli', 'net_amount' => 200]);
    $this->employee->setRelation('payrollSettings', collect([$future, $older, $inactive, $current, $israeli]));
    $rows = collect(app(EmployeePayrollService::class)->payableSummaries(collect([$this->employee])))->keyBy('source');
    expect($rows)->toHaveCount(2)->and($rows['clinic']['net_amount'])->toBe(500.0)
        ->and($rows['israeli']['net_amount'])->toBe(200.0);
});

test('overlapping employee payroll cannot finalize previously finalized work again', function () {
    $service = app(EmployeePayrollService::class);
    $service->finalize($this->employee, 'clinic', '2026-09-01', '2026-09-10');
    expect(fn () => $service->finalize($this->employee, 'clinic', '2026-09-05', '2026-09-16'))
        ->toThrow(ValidationException::class);
});

test('Clinic upcoming employee payroll totals required amounts per payday while retaining net and doctor calculations', function () {
    $this->employee->update(['salary_payout_day' => 16]);
    $this->employee->payrollSettings()->first()->update(['net_amount' => 1000, 'effective_from' => '2026-09-01']);
    $other = Employee::create(['first_name' => 'Second', 'last_name' => 'Requirement', 'position_id' => $this->employee->position_id, 'is_active' => true, 'salary_payout_day' => 16]);
    $other->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'fixed_net', 'currency' => 'GEL', 'default_payment_method' => 'cash', 'net_amount' => 1300, 'is_active' => true, 'effective_from' => '2026-09-01']);
    $this->employee->payrollSettings()->create(['source' => 'israeli', 'salary_model' => 'fixed_net', 'currency' => 'GEL', 'default_payment_method' => 'cash', 'net_amount' => 500, 'is_active' => true, 'effective_from' => '2026-09-01']);
    $doctorBefore = app(DoctorCompensationCalculator::class)->payableSummaries(collect([$this->doctor]));
    $page = Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')
        ->assertViewHas('clinicPayroll', fn ($card) => $card['totals']['GEL'] === 3001.02)
        ->assertSee('3,001.02')->assertSee('16.09.2026')->assertSee('1,000.00')->assertSee('1,301.02')
        ->call('openEmployeeSalary', $this->employee->id, 'clinic')->assertSet('employeeDetail.required_amount', 1301.02)
        ->assertSet('employeeDetail.net_amount', 1000.0)->assertSet('employeeDetail.tax_breakdown.income_tax', 250.0)
        ->call('openEmployeeSalary', $this->employee->id, 'israeli')->assertSet('employeeDetail.required_amount', null)->assertSet('employeeDetail.net_amount', 500.0);
    expect(app(DoctorCompensationCalculator::class)->payableSummaries(collect([$this->doctor])))->toBe($doctorBefore);
    app(EmployeePayrollService::class)->finalize($this->employee, 'clinic', '2026-09-01', '2026-09-30');
    $page->call('$refresh')->assertViewHas('clinicPayroll', fn ($card) => $card['totals']['GEL'] === 1700.0);
});
