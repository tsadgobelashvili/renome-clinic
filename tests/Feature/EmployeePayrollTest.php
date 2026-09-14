<?php

use App\Enums\PaymentMethod;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePayrollSetting;
use App\Models\EmployeePosition;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\PayrollEntry;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\EmployeePayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->actingAs($this->owner);
    $this->employee = Employee::create([
        'first_name' => 'Nino',
        'last_name' => 'Admin',
        'position_id' => EmployeePosition::query()->where('name', 'Administrator')->sole()->id,
        'is_active' => true,
    ]);
    $this->service = app(EmployeePayrollService::class);
});

function payrollSetting(Employee $employee, array $attributes): EmployeePayrollSetting
{
    return $employee->payrollSettings()->create([
        'source' => 'clinic',
        'salary_model' => 'fixed_net',
        'currency' => 'GEL',
        'default_payment_method' => PaymentMethod::BankTransfer->value,
        'is_active' => true,
        'net_amount' => 1300,
        ...$attributes,
    ]);
}

function payrollVisitItem(string $source, string $date, TreatmentCase $treatment, int $quantity, float $unitPrice = 0): void
{
    $patient = Patient::create([
        'first_name' => fake()->firstName(),
        'last_name' => fake()->lastName(),
        'patient_group_id' => $source === 'israeli' ? PatientGroup::israelPartnerId() : PatientGroup::clinicId(),
    ]);
    $doctor = Doctor::create(['first_name' => 'Payroll', 'last_name' => fake()->unique()->lastName(), 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_date' => $date,
        'visit_type' => 'treatment',
        'currency' => 'GEL',
        'total_price' => $quantity * $unitPrice,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->id,
        'quantity' => $quantity,
        'unit_price' => $unitPrice,
        'currency' => 'GEL',
    ]);
}

test('clinic fixed net payroll pays the configured net amount', function () {
    payrollSetting($this->employee, ['employee_deductions' => 100]);

    $calculation = $this->service->calculate($this->employee, 'clinic', '2026-09-01', '2026-09-30');

    expect($calculation)->toMatchArray([
        'base_amount' => 1300.0,
        'gross_amount' => 1658.16,
        'net_amount' => 1300.0,
        'deductions' => 358.16,
        'employer_cost' => 33.16,
        'required_amount' => 1691.33,
        'currency' => 'GEL',
    ]);
});

test('clinic fixed gross payroll keeps gross net and deductions separate', function () {
    payrollSetting($this->employee, [
        'salary_model' => 'fixed_gross', 'net_amount' => null, 'gross_amount' => 1800,
        'employee_deductions' => 200, 'employer_cost' => 50,
    ]);

    $calculation = $this->service->calculate($this->employee, 'clinic', '2026-09-01', '2026-09-30');

    expect($calculation['gross_amount'])->toBe(1800.0)
        ->and($calculation['net_amount'])->toBe(1600.0)
        ->and($calculation['employer_cost'])->toBe(50.0);
});

test('clinic percentage payroll uses the configured category base in one currency', function () {
    $therapy = TreatmentCase::create(['name' => 'Composite filling', 'category' => 'therapy', 'default_price' => 100, 'is_active' => true]);
    $surgery = TreatmentCase::create(['name' => 'Extraction', 'category' => 'surgery', 'default_price' => 500, 'is_active' => true]);
    payrollVisitItem('clinic', '2026-09-10', $therapy, 2, 100);
    payrollVisitItem('clinic', '2026-09-10', $surgery, 1, 500);
    payrollSetting($this->employee, [
        'salary_model' => 'percentage', 'net_amount' => null, 'percentage_rate' => 40, 'category' => 'therapy',
    ]);

    $calculation = $this->service->calculate($this->employee, 'clinic', '2026-09-01', '2026-09-30');

    expect($calculation['base_amount'])->toBe(200.0)
        ->and($calculation['gross_amount'])->toBe(80.0)
        ->and($calculation['net_amount'])->toBe(80.0);
});

test('israeli per unit payroll uses eligible item quantity without requiring a payment', function () {
    $work = TreatmentCase::create(['name' => 'Israeli unit work', 'category' => 'orthopedics', 'default_price' => 0, 'is_active' => true]);
    payrollVisitItem('israeli', '2026-09-12', $work, 4);
    payrollSetting($this->employee, [
        'source' => 'israeli', 'salary_model' => 'per_unit', 'net_amount' => null,
        'per_unit_amount' => 25, 'treatment_case_id' => $work->id,
        'default_payment_method' => PaymentMethod::Cash->value,
    ]);

    $calculation = $this->service->calculate($this->employee, 'israeli', '2026-09-01', '2026-09-30');

    expect($calculation['eligible_units'])->toBe(4)
        ->and($calculation['net_amount'])->toBe(100.0)
        ->and($calculation['payment_method'])->toBe(PaymentMethod::Cash->value);
});

test('clinic and israeli settings remain independent for the same employee', function () {
    payrollSetting($this->employee, []);
    payrollSetting($this->employee, [
        'source' => 'israeli', 'salary_model' => 'fixed_gross', 'net_amount' => null,
        'gross_amount' => 900, 'default_payment_method' => PaymentMethod::Cash->value,
    ]);

    $clinic = $this->service->calculate($this->employee, 'clinic', '2026-09-01', '2026-09-30');
    $israeli = $this->service->calculate($this->employee, 'israeli', '2026-09-01', '2026-09-30');

    expect($clinic['salary_model'])->toBe('fixed_net')
        ->and($clinic['payment_method'])->toBe(PaymentMethod::BankTransfer->value)
        ->and($israeli['salary_model'])->toBe('fixed_gross')
        ->and($israeli['payment_method'])->toBe(PaymentMethod::Cash->value);
});

test('finalized payroll snapshots do not change with future setting edits', function () {
    $setting = payrollSetting($this->employee, []);
    $entry = $this->service->finalize($this->employee, 'clinic', '2026-09-01', '2026-09-30');

    $setting->update(['net_amount' => 1700, 'default_payment_method' => PaymentMethod::Cash->value]);

    expect($entry->fresh()->net_amount)->toBe('1300.00')
        ->and($entry->fresh()->settings_snapshot['net_amount'])->toBe('1300.00')
        ->and($entry->fresh()->payment_method)->toBe(PaymentMethod::BankTransfer->value)
        ->and(fn () => $this->service->finalize($this->employee, 'clinic', '2026-09-01', '2026-09-30'))
        ->toThrow(ValidationException::class);
});

test('general employee profile configures both payroll sources while technicians keep their existing salary settings', function () {
    Livewire::test(EditEmployee::class, ['record' => $this->employee->id])
        ->fillForm(['payrollSettings' => [
            ['source' => 'clinic', 'salary_model' => 'fixed_net', 'currency' => 'GEL', 'default_payment_method' => 'bank_transfer', 'net_amount' => 1300, 'is_active' => true, 'taxable' => false, 'employee_deductions' => 0, 'employer_cost' => 0],
            ['source' => 'israeli', 'salary_model' => 'fixed_gross', 'currency' => 'USD', 'default_payment_method' => 'cash', 'gross_amount' => 500, 'is_active' => true, 'taxable' => false, 'employee_deductions' => 0, 'employer_cost' => 0],
        ]])->call('save')->assertHasNoFormErrors();

    expect($this->employee->payrollSettings()->count())->toBe(2);
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->assertSee(__('employees.payroll.title'))->assertSee(__('employees.payroll.clinic'))->assertSee(__('employees.payroll.israeli'));

    $technician = Employee::create([
        'first_name' => 'Alexi', 'last_name' => 'Tech',
        'position_id' => EmployeePosition::query()->where('name', 'Technician')->sole()->id,
        'is_active' => true, 'salary_type' => 'performance', 'salary_active' => true,
    ]);
    $technician->salaryRates()->create(['work_type' => 'zircon', 'amount' => 20, 'basis' => 'per_unit', 'is_active' => true]);

    expect(fn () => $this->service->calculate($technician, 'clinic', '2026-09-01', '2026-09-30'))
        ->toThrow(ValidationException::class)
        ->and($technician->salaryRates()->sole()->amount)->toBe('20.00')
        ->and(PayrollEntry::query()->where('employee_id', $technician->id)->exists())->toBeFalse();
});

test('Clinic required amount follows the exact net formula with final monetary rounding', function ($net, $required) {
    payrollSetting($this->employee, ['net_amount' => $net, 'employee_deductions' => 999, 'employer_cost' => 999]);
    $calculation = $this->service->calculate($this->employee, 'clinic', '2026-09-01', '2026-09-30');
    expect($calculation['net_amount'])->toBe((float) $net)->and($calculation['required_amount'])->toBe($required);
    $entry = $this->service->finalize($this->employee, 'clinic', '2026-09-01', '2026-09-30');
    expect($entry->calculation_details['required_amount'])->toBe($required)->and((float) $entry->net_amount)->toBe((float) $net);
})->with([[1000, 1301.02], [1300, 1691.33], [1500, 1951.53]]);

test('Clinic 1000 net tax and pension breakdown is computed without manual deductions', function () {
    payrollSetting($this->employee, ['net_amount' => 1000, 'taxable' => false]);
    expect($this->service->calculate($this->employee, 'clinic', '2026-09-01', '2026-09-30'))->toMatchArray([
        'net_amount' => 1000.0, 'income_tax' => 250.0, 'employee_pension' => 25.51, 'employer_pension' => 25.51,
        'gross_amount' => 1275.51, 'deductions' => 275.51, 'required_amount' => 1301.02,
    ]);
});

test('Israeli fixed net keeps its existing manual deduction and employer cost calculation', function () {
    payrollSetting($this->employee, ['source' => 'israeli', 'net_amount' => 1000, 'employee_deductions' => 100, 'employer_cost' => 50]);
    $calculation = $this->service->calculate($this->employee, 'israeli', '2026-09-01', '2026-09-30');
    expect($calculation)->toMatchArray(['net_amount' => 1000.0, 'gross_amount' => 1100.0, 'deductions' => 100.0, 'employer_cost' => 50.0])
        ->and($calculation)->not->toHaveKey('required_amount');
});

test('Clinic net editor recalculates its read only required amount and persists only the configured salary', function () {
    payrollSetting($this->employee, ['net_amount' => 1000]);
    $page = Livewire::test(EditEmployee::class, ['record' => $this->employee->id])->assertSee('1,301.02');
    $key = array_key_first($page->get('data.payrollSettings'));
    $page->set('data.payrollSettings.'.$key.'.net_amount', 1500)->assertSee('1,951.53')->call('save')->assertHasNoFormErrors();
    expect($this->employee->payrollSettings()->sole()->net_amount)->toBe('1500.00');
    $page->set('data.payrollSettings.'.$key.'.source', 'israeli')->assertDontSee(__('employees.payroll.funding_required'));
});
