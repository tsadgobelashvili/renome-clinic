<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\ClinicPayrollCycle;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\FinanceTransaction;
use App\Models\Patient;
use App\Models\PayrollEntry;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Models\Visit;
use App\Services\ClinicEmployeePayrollAmounts;
use App\Services\ClinicPayrollCashPosting;
use App\Services\ClinicPayrollCycleService;
use App\Services\EmployeePayrollService;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\CashOutflowReport;
use App\Services\Finance\LiquidityReport;
use App\Services\FinanceManager;
use App\Services\SalarySettlementService;
use App\Support\CashboxManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 12:00:00'));
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->employee = Employee::create(['first_name' => 'Cash', 'last_name' => 'Employee', 'is_active' => true, 'salary_payout_day' => 16,
        'position_id' => EmployeePosition::where('name', 'Administrator')->sole()->id]);
    $this->setting = $this->employee->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'fixed_net', 'net_amount' => 800,
        'currency' => 'GEL', 'default_payment_method' => 'cash', 'effective_from' => '2026-09-01', 'is_active' => true]);
    $this->doctor = Doctor::create(['first_name' => 'Cash', 'last_name' => 'Doctor', 'is_active' => true,
        'compensation_percentage' => 40, 'clinic_salary_payment_method' => 'cash']);
    $patient = Patient::create(['first_name' => 'Cash', 'last_name' => 'Patient']);
    $this->visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 1000]);
    $this->visit->treatmentCaseItems()->create(['custom_service_name' => 'Paid work', 'quantity' => 1, 'unit_price' => 1000]);
    $this->visit->payments()->create(['amount' => 1000, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);
    app(FinanceManager::class)->create(['type' => 'income', 'transaction_date' => now(), 'category' => 'other_income',
        'amount' => 5000, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
});

function clinicCurrentCash(): float
{
    return (float) app(LiquidityReport::class)->current('clinic')['cash']['GEL']['amount'];
}

test('cash and bank requirements and reactive payment method use the same calculation', function () {
    expect(ClinicEmployeePayrollAmounts::fromNet(800, 'bank_transfer')['required_amount'])->toBe(1040.82)
        ->and(ClinicEmployeePayrollAmounts::fromNet(800, 'cash')['required_amount'])->toBe(800.0);
    $this->setting->update(['default_payment_method' => 'bank_transfer']);
    $page = Livewire::test(EditEmployee::class, ['record' => $this->employee->id])->assertSee('1,040.82');
    $key = array_key_first($page->get('data.payrollSettings'));
    $page->set('data.payrollSettings.'.$key.'.default_payment_method', 'cash')->assertDontSee('1,040.82')->assertSee('800.00');
});

test('individual cash employee posts one salary expense and leaves the later batch', function () {
    $before = clinicCurrentCash();
    $entry = app(EmployeePayrollService::class)->finalize($this->employee, 'clinic', '2026-09-01', '2026-09-14');
    expect($entry->calculation_details['required_amount'])->toEqual(800)->and($entry->payout_status)->toBe('paid')
        ->and(clinicCurrentCash())->toBe($before - 800);
    app(ClinicPayrollCashPosting::class)->record($entry);
    expect(clinicCurrentCash())->toBe($before - 800);
    expect(fn () => app(EmployeePayrollService::class)->finalize($this->employee, 'clinic', '2026-09-01', '2026-09-14'))->toThrow(ValidationException::class);
    expect(fn () => app(EmployeePayrollService::class)->finalize($this->employee, 'clinic', '2026-09-15', '2026-09-16'))->toThrow(ValidationException::class);
    $service = app(ClinicPayrollCycleService::class);
    $review = $service->preview();
    expect($review['employees'])->toBe([]);
    $service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    expect(clinicCurrentCash())->toBe($before - 1200)->and(FinanceTransaction::where('type', 'expense')->count())->toBe(2);
});

test('individual cash doctor is paid once and only new work enters the batch', function () {
    $before = clinicCurrentCash();
    $service = app(SalarySettlementService::class);
    $record = $service->settle($this->doctor->id, '2026-09-01', '2026-09-14', 40, $this->owner->id, patientGroup: 'clinic')[0];
    expect($record->salary_total)->toBe('400.00')->and(clinicCurrentCash())->toBe($before - 400);
    app(ClinicPayrollCashPosting::class)->record($record);
    expect(clinicCurrentCash())->toBe($before - 400);
    expect(fn () => $service->undo($record->id))->toThrow(ValidationException::class);
    expect(fn () => $service->settle($this->doctor->id, '2026-09-01', '2026-09-14', 40, $this->owner->id, patientGroup: 'clinic'))->toThrow(ValidationException::class);
    $cycle = app(ClinicPayrollCycleService::class);
    expect($cycle->preview()['doctors'])->toBe([]);
    $this->visit->treatmentCaseItems()->create(['custom_service_name' => 'New work', 'quantity' => 1, 'unit_price' => 250]);
    $preview = $cycle->preview();
    expect($preview['doctor_totals']['GEL'])->toEqual(100);
    $cycle->finalize($preview['payroll_date'], $preview['fingerprint'], $this->owner);
    expect(clinicCurrentCash())->toBe($before - 1300)->and(FinanceTransaction::where('salary_settlement_id', $record->id)->count())->toBe(1);
});

test('batch cash payroll reaches both drilldowns and P and L exactly once', function () {
    $before = clinicCurrentCash();
    $service = app(ClinicPayrollCycleService::class);
    $review = $service->preview();
    expect($review['totals']['GEL'])->toEqual(1200);
    $cycle = $service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    expect(clinicCurrentCash())->toBe($before - 1200)->and($cycle->payment_status)->toBe('paid');
    expect(app(CashOutflowReport::class)->entries(null, null, 'clinic')->sum('amount'))->toEqual(1200)
        ->and(app(AccountingLedger::class)->pnl(null, null, 'cash', 'clinic')->where('metric', 'expense')->sum('amount'))->toEqual(1200);
    foreach (FinanceTransaction::where('type', 'expense')->get() as $expense) {
        expect($expense->category)->toBe('salary')->and($expense->cashboxTransaction()->count())->toBe(1);
        expect(fn () => app(FinanceManager::class)->delete($expense))->toThrow(ValidationException::class);
    }
    expect(fn () => $service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner))->toThrow(ValidationException::class);
    $service->overview();
    $service->preview();
    expect(clinicCurrentCash())->toBe($before - 1200);
});

test('mixed cash and bank finalization deducts only the cash salary', function () {
    $this->setting->update(['default_payment_method' => 'bank_transfer']);
    $before = clinicCurrentCash();
    $service = app(ClinicPayrollCycleService::class);
    $review = $service->preview();
    expect($review['totals']['GEL'])->toEqual(1440.82);
    $cycle = $service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    expect(clinicCurrentCash())->toBe($before - 400)->and($cycle->payment_status)->toBe('pending')
        ->and($cycle->employeeEntries()->sole()->payout_status)->toBe('pending');
});

test('insufficient cash rolls back all salaries and postings in the batch', function () {
    $this->setting->update(['net_amount' => 20000]);
    $before = clinicCurrentCash();
    $service = app(ClinicPayrollCycleService::class);
    $review = $service->preview();
    expect(fn () => $service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner))->toThrow(ValidationException::class);
    expect(clinicCurrentCash())->toBe($before)->and(ClinicPayrollCycle::count())->toBe(0)
        ->and(SalarySettlement::count())->toBe(0)->and(PayrollEntry::count())->toBe(0)
        ->and(FinanceTransaction::where('type', 'expense')->count())->toBe(0);
});

test('previously zero payable doctor work becomes eligible when payment arrives', function () {
    $visit = Visit::create(['patient_id' => $this->visit->patient_id, 'doctor_id' => $this->doctor->id,
        'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 500]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Later paid', 'quantity' => 1, 'unit_price' => 500]);
    $service = app(ClinicPayrollCycleService::class);
    $review = $service->preview();
    $service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    $visit->payments()->create(['amount' => 500, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);
    expect($service->preview()['doctor_totals']['GEL'])->toEqual(200)->and($service->preview()['payroll_date'])->toBe('2026-10-01');
});

test('individual owner pays only that owner and the batch later pays the pending counterpart once', function () {
    $this->doctor->update(['owner_split_key' => 'levan']);
    $this->visit->update(['owner_split_override' => 'on']);
    $other = Doctor::create(['first_name' => 'Other', 'last_name' => 'Owner', 'is_active' => true, 'owner_split_key' => 'nodar',
        'compensation_percentage' => 40, 'clinic_salary_payment_method' => 'cash']);
    $before = clinicCurrentCash();
    app(SalarySettlementService::class)->settle($this->doctor->id, '2026-09-01', '2026-09-14', 40, $this->owner->id, patientGroup: 'clinic');
    expect(clinicCurrentCash())->toBe($before - 500)->and(SalarySettlement::where('doctor_id', $other->id)->count())->toBe(0);
    $service = app(ClinicPayrollCycleService::class);
    $review = $service->preview();
    expect($review['doctors'])->toHaveCount(1)->and($review['doctors'][0]['id'])->toBe($other->id);
    $service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    expect(clinicCurrentCash())->toBe($before - 1800)->and(SalarySettlement::count())->toBe(2);
});

test('cash payroll uses its own currency without conversion', function () {
    $this->setting->update(['currency' => 'USD']);
    app(FinanceManager::class)->create(['type' => 'income', 'transaction_date' => now(), 'category' => 'other_income',
        'amount' => 1000, 'currency' => 'USD', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    $gel = clinicCurrentCash();
    app(EmployeePayrollService::class)->finalize($this->employee, 'clinic', '2026-09-01', '2026-09-14');
    expect(app(LiquidityReport::class)->current('clinic')['cash']['USD']['amount'])->toEqual(200)
        ->and(clinicCurrentCash())->toBe($gel);
});

test('closed cashier day rejects payroll without retaining an expense or salary record', function () {
    app(CashboxManager::class)->today()->update(['status' => 'closed']);
    expect(fn () => app(EmployeePayrollService::class)->finalize($this->employee, 'clinic', '2026-09-01', '2026-09-14'))->toThrow(ValidationException::class);
    expect(PayrollEntry::count())->toBe(0)->and(FinanceTransaction::where('type', 'expense')->count())->toBe(0);
});

test('both individual UI actions use the same cash posting as the combined cycle', function () {
    $before = clinicCurrentCash();
    Livewire::test(DoctorCompensation::class)
        ->call('openDoctorSalary', $this->doctor->id, 'clinic')->callMountedAction()->assertHasNoActionErrors();
    expect(clinicCurrentCash())->toBe($before - 400);
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')
        ->call('openEmployeeSalary', $this->employee->id, 'clinic')->call('finalizeEmployeePayroll')->assertHasNoErrors();
    expect(clinicCurrentCash())->toBe($before - 1200)->and(FinanceTransaction::where('type', 'expense')->count())->toBe(2);
});

test('individual doctor modal displays insufficient cash without finalizing', function () {
    app(FinanceManager::class)->create(['type' => 'expense', 'transaction_date' => now(), 'category' => 'other_expense',
        'amount' => clinicCurrentCash(), 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'clinic')
        ->callMountedAction()->assertHasErrors(['payroll'])->assertMountedActionModalSee(__('clinic-payroll.insufficient_cash', ['currency' => 'GEL']));
    expect(SalarySettlement::count())->toBe(0);
});
