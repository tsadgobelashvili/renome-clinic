<?php

use App\Filament\Pages\DoctorCompensation;
use App\Models\ClinicPayrollCycle;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\Patient;
use App\Models\PayrollEntry;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Models\Visit;
use App\Services\ClinicPayrollCycleService;
use App\Services\EmployeePayrollService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\DatabaseSafety;

beforeEach(function () {
    DatabaseSafety::assertInMemory(DB::connection()->getConfig());
    Http::preventStrayRequests();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Selection', 'last_name' => 'Doctor', 'is_active' => true, 'compensation_percentage' => 40]);
    $patient = Patient::create(['first_name' => 'Selection', 'last_name' => 'Patient']);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 1000]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Selection work', 'quantity' => 1, 'unit_price' => 1000]);
    $visit->payments()->create(['amount' => 1000, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);
    $this->employee = Employee::create(['first_name' => 'Selection', 'last_name' => 'Employee', 'is_active' => true,
        'position_id' => EmployeePosition::where('name', 'Administrator')->sole()->id, 'salary_payout_day' => 16]);
    $this->setting = $this->employee->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'fixed_net',
        'currency' => 'GEL', 'net_amount' => 1000, 'default_payment_method' => 'bank_transfer', 'effective_from' => '2026-09-01', 'is_active' => true]);
    $this->service = app(ClinicPayrollCycleService::class);
});

test('clinic payroll selects all by default and totals only checked rows', function () {
    $page = Livewire::test(DoctorCompensation::class)->mountAction('clinicPayroll');
    $review = $page->get('clinicPayrollReview');
    expect($page->get('selectedClinicPayrollRows'))->toHaveCount(2);
    $doctorKey = ClinicPayrollCycleService::rowKey('doctors', $review['doctors'][0]);
    $page->call('selectClinicPayrollRows', false)->assertSet('selectedClinicPayrollRows', []);
    expect($page->instance()->clinicPayrollAction()->getModalSubmitAction()->isDisabled())->toBeTrue();
    $page->set('selectedClinicPayrollRows', [$doctorKey]);
    $selected = $this->service->selectedReview($review, [$doctorKey]);
    expect($selected['totals']['GEL'])->toBe(400.0)->and($selected['employee_totals'])->toBe([]);
    $page->callMountedAction()->assertHasNoActionErrors();
    expect(SalarySettlement::count())->toBe(1)->and(PayrollEntry::count())->toBe(0);
});

test('select all retains the existing authorized payout and selection totals require no queries', function () {
    $review = $this->service->preview();
    $keys = [ClinicPayrollCycleService::rowKey('doctors', $review['doctors'][0]),
        ClinicPayrollCycleService::rowKey('employees', $review['employees'][0])];
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($this->service->selectedReview($review, $keys)['totals'])->toEqual($review['totals'])
        ->and(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    expect(fn () => $this->service->finalize($review['payroll_date'], $review['fingerprint'], $admin, $keys))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(ClinicPayrollCycle::count())->toBe(0);
    $cycle = $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner, $keys);
    expect($cycle->status)->toBe('finalized')->and(SalarySettlement::count())->toBe(1)->and(PayrollEntry::count())->toBe(1)
        ->and($cycle->snapshot['totals']['GEL'])->toEqual(1701.02);
    expect(fn () => $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner, $keys))->toThrow(ValidationException::class);
});

test('unselected employee keeps original period amount and advance until later payout', function () {
    $advance = app(\App\Services\EmployeeAdvanceService::class)->issue([
        'posting_key' => (string) \Illuminate\Support\Str::uuid(), 'employee_id' => $this->employee->id,
        'amount' => 300, 'date' => today()->toDateString(), 'source' => 'other', 'is_salary_advance' => true,
    ], $this->owner);
    $review = $this->service->preview();
    $key = ClinicPayrollCycleService::rowKey('doctors', $review['doctors'][0]);
    $before = $advance->fresh()->attributesToArray();
    $cycle = $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner, [$key]);
    expect($cycle->status)->toBe('partial')->and(PayrollEntry::count())->toBe(0)
        ->and($advance->fresh()->attributesToArray())->toBe($before);
    $this->travelTo(now()->addMonth());
    $next = $this->service->preview();
    expect($next['doctors'])->toBe([])->and($next['employees'])->toBe($review['employees']);
    $employeeKey = ClinicPayrollCycleService::rowKey('employees', $next['employees'][0]);
    $cycle = $this->service->finalize($next['payroll_date'], $next['fingerprint'], $this->owner, [$employeeKey]);
    expect($cycle->status)->toBe('finalized')->and(PayrollEntry::count())->toBe(1)
        ->and($cycle->snapshot['totals']['GEL'])->toEqual(1701.02)
        ->and(PayrollEntry::sole()->salary_advance_applied)->toBe('300.00')
        ->and(PayrollEntry::sole()->period_start->toDateString())->toBe($review['employees'][0]['period_start'])
        ->and(SalarySettlement::count())->toBe(1);
});

test('empty invalid duplicate and already paid selection keys cannot create payouts', function () {
    $review = $this->service->preview();
    $key = ClinicPayrollCycleService::rowKey('doctors', $review['doctors'][0]);
    foreach ([[], ['doctors:999'], [$key, $key], [123]] as $keys) {
        expect(fn () => $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner, $keys))->toThrow(ValidationException::class);
        expect(ClinicPayrollCycle::count())->toBe(0)->and(SalarySettlement::count())->toBe(0)->and(PayrollEntry::count())->toBe(0);
    }
    $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner, [$key]);
    $next = $this->service->preview();
    expect(fn () => $this->service->finalize($next['payroll_date'], $next['fingerprint'], $this->owner, [$key]))->toThrow(ValidationException::class);
    expect(SalarySettlement::count())->toBe(1)->and(PayrollEntry::count())->toBe(0);
});

test('one employee can select a later payable period while the earlier row remains payable', function (bool $withAdvance) {
    if ($withAdvance) {
        app(\App\Services\EmployeeAdvanceService::class)->issue([
            'posting_key' => (string) \Illuminate\Support\Str::uuid(), 'employee_id' => $this->employee->id,
            'amount' => 300, 'date' => today()->toDateString(), 'source' => 'other', 'is_salary_advance' => true,
        ], $this->owner);
    }
    $review = $this->service->preview();
    $first = $review['employees'][0];
    $second = [...$first, 'period_start' => '2026-09-17', 'period_end' => '2026-10-16', 'payday' => '2026-10-16'];
    $remaining = [...$review, 'doctors' => [], 'employees' => [$first, $second]];
    unset($remaining['fingerprint']);
    $keys = array_map(fn ($row) => ClinicPayrollCycleService::rowKey('employees', $row), [$first, $second]);
    $remaining = $this->service->selectedReview($remaining, $keys);
    // Existing snapshot storage represents a deferred batch; no synthetic person IDs.
    DB::table('clinic_payroll_cycles')->insert(['payroll_date' => $review['payroll_date'], 'status' => 'partial',
        'snapshot' => json_encode(['doctors' => [], 'employees' => [], 'remaining' => $remaining])]);
    $pending = $this->service->preview();
    $this->service->finalize($pending['payroll_date'], $pending['fingerprint'], $this->owner, [$keys[1]]);
    expect(PayrollEntry::sole()->period_start->toDateString())->toBe($second['period_start']);
    $pending = $this->service->preview();
    expect($pending['employees'])->toHaveCount(1)->and($pending['employees'][0]['period_start'])->toBe($first['period_start']);
    $cycle = $this->service->finalize($pending['payroll_date'], $pending['fingerprint'], $this->owner, [$keys[0]]);
    expect($cycle->status)->toBe('finalized')->and(PayrollEntry::count())->toBe(2)
        ->and($cycle->snapshot['employee_entry_ids'])->toHaveCount(2)
        ->and((float) PayrollEntry::sum('salary_advance_applied'))->toBe($withAdvance ? 300.0 : 0.0);
})->with([false, true]);

test('selected cash employee alone deducts cash and deferred doctor is paid later', function () {
    $this->setting->update(['default_payment_method' => 'cash', 'net_amount' => 800]);
    $this->doctor->update(['clinic_salary_payment_method' => 'cash']);
    app(\App\Services\FinanceManager::class)->create(['type' => 'income', 'transaction_date' => now(), 'category' => 'other_income',
        'amount' => 5000, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    $balance = fn () => app(\App\Services\FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'];
    $before = $balance();
    $review = $this->service->preview();
    $key = ClinicPayrollCycleService::rowKey('employees', $review['employees'][0]);
    $cycle = $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner, [$key]);
    expect(SalarySettlement::count())->toBe(0)->and(PayrollEntry::sole()->payout_status)->toBe('paid')
        ->and($balance())->toBe($before - 800);
    $next = $this->service->preview();
    expect($next['doctors'])->toBe($review['doctors'])->and($next['employees'])->toBe([]);
    $this->service->finalize($next['payroll_date'], $next['fingerprint'], $this->owner,
        [ClinicPayrollCycleService::rowKey('doctors', $next['doctors'][0])]);
    expect($balance())->toBe($before - 1200)->and(SalarySettlement::count())->toBe(1);
});

test('linked owner shares cannot finalize an unchecked counterpart', function () {
    $this->doctor->update(['owner_split_key' => 'levan', 'compensation_percentage' => 50]);
    Visit::firstOrFail()->update(['owner_split_override' => 'on']);
    Doctor::create(['first_name' => 'Other', 'last_name' => 'Owner', 'owner_split_key' => 'nodar', 'compensation_percentage' => 50, 'is_active' => true]);
    $review = $this->service->preview();
    expect($review['doctors'])->toHaveCount(2);
    $keys = array_map(fn ($row) => ClinicPayrollCycleService::rowKey('doctors', $row), $review['doctors']);
    foreach ($keys as $key) {
        expect(fn () => $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner, [$key]))->toThrow(ValidationException::class);
    }
    expect(SalarySettlement::count())->toBe(0)->and(ClinicPayrollCycle::count())->toBe(0);
    $cycle = $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner, $keys);
    expect($cycle->doctorSettlements()->count())->toBe(2)->and(PayrollEntry::count())->toBe(0);
});
