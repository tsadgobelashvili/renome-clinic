<?php

use App\Filament\Pages\DoctorCompensation;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use App\Services\EmployeePayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-04 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    app()->setLocale('ka');
});

function fixedGroupEmployee(string $name, ?int $day, array $setting = []): Employee
{
    $employee = Employee::create(['first_name' => $name, 'last_name' => 'Synthetic employee', 'is_active' => true,
        'position_id' => EmployeePosition::where('name', 'Administrator')->sole()->id, 'salary_payout_day' => $day]);
    $employee->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'fixed_net', 'net_amount' => 1000,
        'currency' => 'GEL', 'default_payment_method' => 'bank_transfer', 'effective_from' => '2026-11-01', 'is_active' => true, ...$setting]);

    return $employee;
}

function fixedGroupSnapshot(): array
{
    return collect(['employees', 'employee_payroll_settings', 'doctors', 'payroll_entries', 'payroll_runs', 'salary_settlements',
        'clinic_payroll_cycles', 'finance_transactions', 'partner_finance_transactions', 'cashbox_transactions', 'bank_transactions', 'payments'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

test('one table defaults to the complete roster and filters fixed salaries by configured day', function () {
    $first = fixedGroupEmployee('First', 1);
    $sixteenth = fixedGroupEmployee('Sixteenth', 16, ['net_amount' => 500, 'default_payment_method' => 'cash']);
    $missing = fixedGroupEmployee('Missing', null, ['salary_model' => 'fixed_gross', 'net_amount' => null, 'gross_amount' => 200, 'employee_deductions' => 40, 'employer_cost' => 10]);
    $other = fixedGroupEmployee('Other', 20, ['net_amount' => 300, 'default_payment_method' => 'cash']);
    $variable = fixedGroupEmployee('Variable', 1, ['salary_model' => 'per_unit', 'net_amount' => null, 'per_unit_amount' => 10]);
    $unconfigured = Employee::create(['first_name' => 'Unconfigured', 'last_name' => 'Synthetic', 'is_active' => true,
        'position_id' => $first->position_id, 'salary_payout_day' => 16]);
    $snapshot = fixedGroupSnapshot();
    $page = Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')
        ->assertSet('employeePaymentDayFilter', 'all')->assertSee('1 რიცხვი')->assertSee('16 რიცხვი')->assertSee('ყველა')
        ->assertSee($variable->full_name)->assertSee($unconfigured->full_name);
    expect($page->viewData('staffRows'))->toHaveCount(6)
        ->and(substr_count($page->html(), '<table '))->toBe(1)
        ->and($page->viewData('salaryTotals'))->toBe(['GEL' => ['net' => 196000, 'cost' => 231102]]);
    $page->set('employeePaymentDayFilter', '1');
    expect($page->viewData('staffRows')->pluck('id')->all())->toBe([$first->id])
        ->and($page->viewData('salaryTotals'))->toBe(['GEL' => ['net' => 100000, 'cost' => 130102]])
        ->and(substr_count($page->html(), '<table '))->toBe(1);
    $page->call('openEmployeeSalary', $first->id, 'clinic')->assertSet('employeeDetail.net_amount', 1000.0);
    $page->set('employeePaymentDayFilter', '16');
    expect($page->viewData('staffRows')->pluck('id')->all())->toBe([$sixteenth->id])
        ->and($page->viewData('salaryTotals'))->toBe(['GEL' => ['net' => 50000, 'cost' => 50000]]);
    $page->set('employeePaymentDayFilter', 'all');
    expect($page->viewData('staffRows')->pluck('id')->sort()->values()->all())
        ->toBe(collect([$first, $sixteenth, $missing, $other, $variable, $unconfigured])->pluck('id')->sort()->values()->all())
        ->and(fixedGroupSnapshot())->toBe($snapshot);
});

test('doctor view and compensation remain independent of every employee filter', function () {
    $employee = fixedGroupEmployee('Employee only', 16, ['net_amount' => 100]);
    $doctor = Doctor::create(['first_name' => 'Synthetic', 'last_name' => 'Doctor', 'is_active' => true, 'compensation_percentage' => 40]);
    $patient = Patient::create(['first_name' => 'Synthetic', 'last_name' => 'Patient']);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 1000]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Synthetic eligible treatment', 'quantity' => 1, 'unit_price' => 1000]);
    $visit->payments()->create(['amount' => 1000, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);
    $calculator = app(DoctorCompensationCalculator::class);
    $before = $calculator->calculate($doctor->id, '2026-11-01', '2026-11-04', patientGroup: 'clinic');
    $snapshot = fixedGroupSnapshot();
    $page = Livewire::test(DoctorCompensation::class);
    foreach (['all', '1', '16'] as $day) {
        $page->set('employeePaymentDayFilter', $day)->assertDontSeeHtml('data-employee-payment-day-filter');
        expect($page->viewData('salaryTotals'))->toBeNull()
            ->and($page->viewData('staffRows')->pluck('type')->unique()->all())->toBe(['doctor']);
        $page->set('staffTypeFilter', 'employees')->assertSeeHtml('data-employee-payment-day-filter');
        expect($page->viewData('staffRows')->pluck('type')->unique()->values()->all())->toBe($day === '1' ? [] : ['employee']);
        $page->set('staffTypeFilter', 'doctors');
    }
    expect($calculator->calculate($doctor->id, '2026-11-01', '2026-11-04', patientGroup: 'clinic'))->toBe($before)
        ->and(fixedGroupSnapshot())->toBe($snapshot);
});

test('all preserves inactive and future settings while day totals keep currencies and source rows separate', function () {
    $employee = fixedGroupEmployee('Effective rules', 1, ['net_amount' => 1200]);
    fixedGroupEmployee('Future setting', 1, ['net_amount' => 9000, 'effective_from' => '2026-12-01']);
    fixedGroupEmployee('Inactive setting', 1, ['net_amount' => 9000, 'is_active' => false]);
    $employee->payrollSettings()->create(['source' => 'israeli', 'salary_model' => 'fixed_net', 'net_amount' => 300, 'employee_deductions' => 20, 'employer_cost' => 10,
        'currency' => 'USD', 'default_payment_method' => 'bank_transfer', 'effective_from' => '2026-11-01', 'is_active' => true]);
    $snapshot = fixedGroupSnapshot();
    $page = Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees');
    expect($page->viewData('staffRows'))->toHaveCount(4);
    $page->set('employeePaymentDayFilter', '1');
    expect($page->viewData('staffRows'))->toHaveCount(2)
        ->and($page->viewData('staffRows')->pluck('key')->unique())->toHaveCount(2)
        ->and($page->viewData('salaryTotals'))->toBe(['GEL' => ['net' => 120000, 'cost' => 156122], 'USD' => ['net' => 30000, 'cost' => 33000]])
        ->and(fixedGroupSnapshot())->toBe($snapshot);
    $page->set('employeePaymentDayFilter', '16');
    expect($page->viewData('staffRows'))->toBeEmpty()
        ->and($page->viewData('salaryTotals'))->toBe(['GEL' => ['net' => 0, 'cost' => 0]])
        ->and(substr_count($page->html(), '<table '))->toBe(1);
});

test('totals include the entire matching dataset with no duplicate salary rows', function () {
    $employees = collect(range(1, 31))->map(fn ($i) => fixedGroupEmployee('Employee '.$i, 1, ['net_amount' => 0.10, 'default_payment_method' => 'cash']));
    $page = Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->set('employeePaymentDayFilter', '1');
    expect($page->viewData('staffRows'))->toHaveCount(31)
        ->and($page->viewData('salaryTotals'))->toBe(['GEL' => ['net' => 310, 'cost' => 310]]);
    foreach ($employees as $employee) {
        expect(substr_count($page->html(), 'wire:key="salary-row-employee-'.$employee->id.'-clinic-current"'))->toBe(1);
    }
});

test('all includes earned variable pay in its totals and preserves its salary action', function () {
    fixedGroupEmployee('Fixed', 1, ['net_amount' => 100, 'default_payment_method' => 'cash']);
    $variable = fixedGroupEmployee('Variable', 1, ['salary_model' => 'per_unit', 'net_amount' => null,
        'per_unit_amount' => 10, 'default_payment_method' => 'cash']);
    $patient = Patient::create(['first_name' => 'Synthetic', 'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::clinicId()]);
    $doctor = Doctor::create(['first_name' => 'Synthetic', 'last_name' => 'Doctor', 'is_active' => true]);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id,
        'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 100]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Synthetic treatment', 'quantity' => 2, 'unit_price' => 50]);
    $snapshot = fixedGroupSnapshot();
    $page = Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees');
    expect($page->viewData('salaryTotals'))->toBe(['GEL' => ['net' => 12000, 'cost' => 12000]]);
    $page->call('openEmployeeSalary', $variable->id, 'clinic')->assertSet('employeeDetail.net_amount', 20.0);
    $page->set('employeePaymentDayFilter', '1');
    expect($page->viewData('salaryTotals'))->toBe(['GEL' => ['net' => 10000, 'cost' => 10000]])
        ->and(fixedGroupSnapshot())->toBe($snapshot);
});

test('paid salary retains its scheduled filter and payment state independently of actual payment day', function () {
    $employee = fixedGroupEmployee('Already paid', 16);
    $entry = app(EmployeePayrollService::class)->finalize($employee, 'clinic', '2026-11-01', '2026-11-04');
    $snapshot = fixedGroupSnapshot();
    $page = Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->set('employeePaymentDayFilter', '16');
    expect($entry->finalized_at->day)->toBe(4)
        ->and($page->viewData('staffRows')->pluck('id')->all())->toBe([$employee->id]);
    $page->set('employeePaymentDayFilter', '1');
    expect($page->viewData('staffRows'))->toBeEmpty()->and(fixedGroupSnapshot())->toBe($snapshot);
});
