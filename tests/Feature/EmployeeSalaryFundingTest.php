<?php

use App\Filament\Pages\Finance;
use App\Filament\Resources\LabTechnicians\Pages\ViewLabTechnician;
use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\EmployeeSalarySettlement;
use App\Models\FinanceTransaction;
use App\Models\LabCase;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\EmployeeSalaryFunding;
use App\Services\EmployeeSalaryService;
use App\Services\FinanceManager;
use App\Services\FinanceUsdUsageService;
use App\Services\PartnerFinanceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-09-07 10:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->employee = Employee::create([
        'first_name' => 'Salary', 'last_name' => 'Technician',
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id,
        'is_active' => true, 'salary_active' => true, 'salary_type' => 'performance', 'salary_main_technician' => true,
    ]);
    $this->employee->salaryRates()->create(['work_type' => 'zircon', 'amount' => 50, 'basis' => 'per_unit', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Salary', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $doctor = Doctor::create(['first_name' => 'David', 'last_name' => 'Chumburidze', 'is_active' => true]);
    foreach (['clinic', 'israeli'] as $source) {
        $case = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'source' => $source, 'case_date' => today()]);
        $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 25]);
    }
    foreach (['GEL' => 3000, 'USD' => 100] as $currency => $amount) {
        $patient->partnerPayments()->create(['amount' => $amount, 'currency' => $currency, 'payment_method' => 'cash', 'paid_at' => now()]);
        app(FinanceManager::class)->create(['type' => 'income', 'category' => 'other_income', 'amount' => $amount,
            'currency' => $currency, 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => today()->subDay()]);
    }
    \App\Models\CashboxDay::whereDate('date', today()->subDay())->update(['status' => 'closed', 'actual_closing_balance' => 3000, 'actual_closing_balance_usd' => 100, 'carry_forward_balance' => 0, 'carry_forward_balance_usd' => 0]);
    $this->service = app(EmployeeSalaryService::class);
    $this->keys = $this->service->pending($this->employee)->keys()->all();
    $this->balances = app(FinanceUsdUsageService::class);
});

test('combined technician settlement posts one expense and exact source cash with reversible audit', function ($clinic, $israeli, $source) {
    $allocation = ['actual_paid_gel' => 2500, 'clinic_cash_gel' => $clinic, 'israeli_cash_gel' => $israeli];
    $settlement = $this->service->settle($this->employee, $this->keys, allocation: $allocation);
    $expense = $settlement->financeExpense;
    expect($settlement->total_gel)->toBe('2500.00')->and($settlement->actual_paid_gel)->toBe('2500.00')
        ->and((float) $settlement->clinic_cash_gel)->toBe((float) $clinic)
        ->and((float) $settlement->israeli_cash_gel)->toBe((float) $israeli)
        ->and($settlement->items()->count())->toBe(2)
        ->and(FinanceTransaction::where('type', 'expense')->count())->toBe(1)
        ->and((float) $expense->amount)->toBe(2500.0)
        ->and($expense->funding_source)->toBe($source)
        ->and((float) ($expense->cashboxTransaction?->amount ?? 0))->toBe(0.0)
        ->and((float) ($expense->israeliCashMovement?->amount ?? 0))->toBe((float) $israeli)
        ->and(PartnerFinanceTransaction::where('type', 'expense')->count())->toBe(0)
        ->and($this->balances->cashBalances('clinic'))->toBe(['GEL' => 3000.0 - $clinic, 'USD' => 100.0])
        ->and($this->balances->cashBalances('israeli'))->toBe(['GEL' => 3000.0 - $israeli, 'USD' => 100.0])
        ->and($this->balances->balances('clinic')['GEL'])->toBe(3000.0 - $clinic)
        ->and(app(PartnerFinanceSummary::class)->currentCashTotals()['GEL'])->toBe(3000.0 - $israeli);
    Livewire::test(Finance::class)->call('showHistory', 'expenses')
        ->assertViewHas('entries', fn ($entries): bool => $entries->contains(fn (array $entry): bool => $entry['key'] === 'finance-'.$expense->id
            && $entry['source'] === ($source === FinanceTransaction::FUNDING_ISRAELI ? 'partner' : $source)));
    expect(fn () => $this->service->settle($this->employee, $this->keys, allocation: $allocation))->toThrow(ValidationException::class);
    app(EmployeeSalaryFunding::class)->pay($settlement, ['actual_paid_gel' => 1, 'clinic_cash_gel' => 1, 'israeli_cash_gel' => 0]);
    expect($settlement->fresh()->actual_paid_gel)->toBe('2500.00');
    expect(FinanceTransaction::where('type', 'expense')->count())->toBe(1);
    $this->service->undo($settlement);
    $this->service->undo($settlement);
    expect($settlement->fresh()->status)->toBe('undone')
        ->and($this->service->pending($this->employee))->toHaveCount(2)
        ->and((float) $expense->fresh()->reversal->amount)->toBe(2500.0)
        ->and(FinanceTransaction::whereNotNull('reversal_of_finance_transaction_id')->count())->toBe(1)
        ->and(PartnerFinanceTransaction::where('type', PartnerFinanceTransaction::TYPE_SALARY_CASH)->count())->toBe($israeli > 0 ? 2 : 0)
        ->and($this->balances->cashBalances('clinic'))->toBe(['GEL' => 3000.0, 'USD' => 100.0])
        ->and($this->balances->cashBalances('israeli'))->toBe(['GEL' => 3000.0, 'USD' => 100.0])
        ->and($settlement->fresh()->actual_paid_gel)->toBe('2500.00');
})->with([
    [1800, 700, FinanceTransaction::FUNDING_MIXED],
    [2500, 0, FinanceTransaction::FUNDING_CLINIC],
    [0, 2500, FinanceTransaction::FUNDING_ISRAELI],
]);

test('invalid or unavailable allocations roll back settlement items and movements', function ($allocation) {
    expect(fn () => $this->service->settle($this->employee, $this->keys, allocation: $allocation))->toThrow(ValidationException::class);
    expect(EmployeeSalarySettlement::count())->toBe(0)->and($this->service->pending($this->employee))->toHaveCount(2)
        ->and(FinanceTransaction::where('type', 'expense')->count())->toBe(0)->and(PartnerFinanceTransaction::count())->toBe(0);
})->with([
    [[]],
    [['clinic_cash_gel' => 0, 'israeli_cash_gel' => 0]],
    [['actual_paid_gel' => 2500, 'clinic_cash_gel' => -1, 'israeli_cash_gel' => 2501]],
    [['actual_paid_gel' => 3500, 'clinic_cash_gel' => 3500, 'israeli_cash_gel' => 0]],
    [['actual_paid_gel' => 3500, 'clinic_cash_gel' => 0, 'israeli_cash_gel' => 3500]],
    [['actual_paid_gel' => 2500, 'clinic_cash_gel' => '1800.001', 'israeli_cash_gel' => '699.999']],
]);

test('partial payment creates unpaid carry and linked expense cannot be edited or deleted', function () {
    $settlement = $this->service->settle($this->employee, $this->keys, allocation: ['actual_paid_gel' => 2499, 'clinic_cash_gel' => 1800, 'israeli_cash_gel' => 699]);
    expect($settlement->total_gel)->toBe('2500.00')->and($settlement->actual_paid_gel)->toBe('2499.00')
        ->and($settlement->opening_carry_gel)->toBe('0.00')->and($settlement->current_salary_gel)->toBe('2500.00')
        ->and($settlement->closing_carry_gel)->toBe('1.00');
    $expense = $settlement->financeExpense;
    expect(fn () => app(FinanceManager::class)->update($expense, ['amount' => 1]))->toThrow(ValidationException::class);
    expect(fn () => app(FinanceManager::class)->delete($expense))->toThrow(ValidationException::class);
    expect(fn () => $expense->israeliCashMovement->delete())->toThrow(ValidationException::class);
    expect((float) $expense->fresh()->amount)->toBe(2499.0)->and($expense->fresh()->cashboxTransaction)->toBeNull();
});

test('Israeli USD cannot fund GEL salary until a separate exchange has been recorded', function () {
    PartnerPatientPayment::where('currency', 'GEL')->delete();
    $allocation = ['actual_paid_gel' => 2500, 'clinic_cash_gel' => 2300, 'israeli_cash_gel' => 200];
    expect(fn () => $this->service->settle($this->employee, $this->keys, allocation: $allocation))->toThrow(ValidationException::class);
    expect(PartnerFinanceTransaction::count())->toBe(0);
    $this->balances->record(['source' => 'israeli', 'usage_type' => 'exchange_only', 'usd_amount' => 100,
        'exchange_rate' => 2.5, 'received_gel_amount' => 250, 'transacted_at' => now()]);
    $settlement = $this->service->settle($this->employee, $this->keys, allocation: $allocation);
    expect($this->balances->cashBalances('israeli'))->toBe(['GEL' => 50.0, 'USD' => 0.0]);
    $this->service->undo($settlement);
    expect(PartnerFinanceTransaction::where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)->count())->toBe(1)
        ->and($this->balances->cashBalances('israeli'))->toBe(['GEL' => 250.0, 'USD' => 0.0]);
});

test('undo on a later open day preserves original closed-day cash entries', function () {
    $settlement = $this->service->settle($this->employee, $this->keys, allocation: ['actual_paid_gel' => 2500, 'clinic_cash_gel' => 1800, 'israeli_cash_gel' => 700]);
    $expense = $settlement->financeExpense;
    expect($expense->cashboxTransaction)->toBeNull();
    $this->travelTo('2026-09-08 10:00:00');
    $this->service->undo($settlement);
    expect($expense->fresh()->reversal->cashboxTransaction)->toBeNull()
        ->and($this->balances->cashBalances('clinic')['GEL'])->toBe(3000.0);
});

test('finance and employee history render the linked split as an expense and cash movement', function () {
    $settlement = $this->service->settle($this->employee, $this->keys, allocation: ['actual_paid_gel' => 2500, 'clinic_cash_gel' => 1800, 'israeli_cash_gel' => 700]);
    Livewire::test(Finance::class)->assertOk()->call('showHistory', 'cash_flow')->assertSee(__('employees.salary.cash_movement'))
        ->call('showHistory', 'expenses')->assertSee('Lab technician salary');
    Livewire::test(ViewLabTechnician::class, ['record' => $this->employee->id])
        ->mountAction('salaryHistory')->assertMountedActionModalSee('1,800.00')->assertMountedActionModalSee('700.00');
    Livewire::test(ListPartnerFinance::class)->assertOk()
        ->assertSee(__('employees.salary.cash_movement'));
});

test('salary payout form shows current source balances and live remaining allocation', function () {
    Livewire::test(ViewLabTechnician::class, ['record' => $this->employee->id])
        ->mountAction('calculateSalary')
        ->assertMountedActionModalSee(__('employees.salary.available', ['amount' => '3,000.00']))
        ->assertMountedActionModalSee('2,500.00 ₾')
        ->set('mountedActions.0.data.clinic_cash_gel', 1800)
        ->set('mountedActions.0.data.israeli_cash_gel', 700)
        ->assertMountedActionModalSee('0.00 ₾')
        ->set('mountedActions.0.data.israeli_cash_gel', 3000.01)
        ->callMountedAction()
        ->assertHasActionErrors(['israeli_cash_gel']);
});

test('unpaid technician salary carries into the next settlement exactly once', function () {
    $first = $this->service->settle($this->employee, $this->keys, allocation: [
        'clinic_cash_gel' => 1000, 'israeli_cash_gel' => 0,
    ]);
    expect($first->current_salary_gel)->toBe('2500.00')->and($first->opening_carry_gel)->toBe('0.00')
        ->and($first->closing_carry_gel)->toBe('1500.00');

    $patient = Patient::query()->first();
    $doctor = Doctor::query()->first();
    $case = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'source' => 'clinic', 'case_date' => today()]);
    $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 10]);
    $nextKeys = $this->service->pending($this->employee)->keys()->all();

    Livewire::test(ViewLabTechnician::class, ['record' => $this->employee->id])
        ->mountAction('calculateSalary')
        ->assertMountedActionModalSee(__('employees.salary.previous_unpaid').':')
        ->assertMountedActionModalSee('1,500.00 ₾')
        ->assertMountedActionModalSee('500.00 ₾')
        ->assertMountedActionModalSee('2,000.00 ₾');

    $second = $this->service->settle($this->employee, $nextKeys, allocation: [
        'clinic_cash_gel' => 1200, 'israeli_cash_gel' => 300,
    ]);
    expect($second->current_salary_gel)->toBe('500.00')->and($second->opening_carry_gel)->toBe('1500.00')
        ->and($second->total_gel)->toBe('2000.00')->and($second->actual_paid_gel)->toBe('1500.00')
        ->and($second->closing_carry_gel)->toBe('500.00')
        ->and(EmployeeSalarySettlement::query()->where('employee_id', $this->employee->id)->count())->toBe(2);

    expect(fn () => $this->service->settle($this->employee, $nextKeys, allocation: [
        'clinic_cash_gel' => 500, 'israeli_cash_gel' => 0,
    ]))->toThrow(ValidationException::class);
    expect(EmployeeSalarySettlement::query()->where('employee_id', $this->employee->id)->count())->toBe(2)
        ->and($this->service->openingCarry($this->employee))->toBe(500.0);
});

test('salary funding schema rolls back and restores the existing finance view', function () {
    $migration = require database_path('migrations/2026_09_07_180000_add_employee_salary_funding.php');
    $count = PartnerFinanceEntry::count();
    $migration->down();
    expect(PartnerFinanceEntry::count())->toBe($count);
    $migration->up();
    expect(PartnerFinanceEntry::count())->toBe($count)
        ->and(Schema::hasColumn('finance_transactions', 'employee_salary_settlement_id'))->toBeTrue();
});


test('technician salary cannot spend current drawer income when held cash is insufficient', function () {
    $manager = app(\App\Support\CashboxManager::class);
    $day = $manager->dayFor(today()->toDateString());
    $day->update(['opening_balance' => 3000]);
    app(FinanceManager::class)->create(['type' => 'income', 'category' => 'other_income', 'amount' => 3000,
        'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => now()]);
    expect(app(EmployeeSalaryFunding::class)->availableCash('clinic'))->toBe(0.0);
    expect(fn () => $this->service->settle($this->employee, $this->keys, allocation: ['clinic_cash_gel' => 2500, 'israeli_cash_gel' => 0]))
        ->toThrow(ValidationException::class);
    expect(EmployeeSalarySettlement::count())->toBe(0);
});

test('held salary expense and undo leave current drawer unchanged', function () {
    $manager = app(\App\Support\CashboxManager::class);
    $day = $manager->dayFor(today()->toDateString());
    app(FinanceManager::class)->create(['type' => 'income', 'category' => 'other_income', 'amount' => 3000,
        'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => now()]);
    $before = $manager->summary($day)['expectedByCurrency']['GEL'];
    $settlement = $this->service->settle($this->employee, $this->keys, allocation: ['clinic_cash_gel' => 2500, 'israeli_cash_gel' => 0]);
    expect($manager->summary($day->fresh())['expectedByCurrency']['GEL'])->toBe($before)
        ->and($day->transactions()->count())->toBe(1)
        ->and(app(EmployeeSalaryFunding::class)->availableCash('clinic'))->toBe(500.0);
    $this->service->undo($settlement);
    expect($manager->summary($day->fresh())['expectedByCurrency']['GEL'])->toBe($before)
        ->and($day->transactions()->count())->toBe(1)
        ->and(app(EmployeeSalaryFunding::class)->availableCash('clinic'))->toBe(3000.0);
});


test('reclassifying an existing drawer salary preserves totals and is idempotent', function () {
    $settlement = $this->service->settle($this->employee, $this->keys, allocation: ['clinic_cash_gel' => 1800, 'israeli_cash_gel' => 700]);
    $expense = $settlement->financeExpense;
    FinanceTransaction::whereKey($expense->id)->update(['cash_source' => 'current_cashier']);
    $manager = app(\App\Support\CashboxManager::class);
    $manager->syncFinanceTransaction($expense->fresh());
    $before = $this->balances->cashBalances('clinic');
    $this->artisan('salary:move-to-held-cash', ['settlements' => [(string) $settlement->id]])->assertSuccessful();
    expect($expense->fresh()->cashboxTransaction)->not->toBeNull();
    $funding = app(EmployeeSalaryFunding::class);
    $funding->moveToHeldCash($settlement->id);
    $funding->moveToHeldCash($settlement->id);
    expect($expense->fresh()->cashboxTransaction)->toBeNull()
        ->and($expense->fresh()->cash_source)->toBe('withdrawn_cash')
        ->and($this->balances->cashBalances('clinic'))->toBe($before)
        ->and(FinanceTransaction::where('type', 'expense')->count())->toBe(1)
        ->and($settlement->fresh()->actual_paid_gel)->toBe('2500.00');
});


test('Israeli USD exchange stays outside the clinic drawer and funds Israeli salary cash', function () {
    $manager = app(\App\Support\CashboxManager::class);
    $day = $manager->dayFor(today()->toDateString());
    $drawer = $manager->summary($day)['expectedByCurrency'];
    $clinic = $this->balances->cashBalances('clinic');
    $entries = \App\Models\CashboxTransaction::count();
    $this->balances->record([
        'source' => 'israeli', 'usage_type' => 'exchange_only', 'usd_amount' => 50,
        'received_gel_amount' => 135, 'exchange_rate' => 2.7, 'transacted_at' => now(),
    ]);
    expect($this->balances->cashBalances('israeli'))->toBe(['GEL' => 3135.0, 'USD' => 50.0])
        ->and($this->balances->cashBalances('clinic'))->toBe($clinic)
        ->and($manager->summary($day->fresh())['expectedByCurrency'])->toBe($drawer)
        ->and(\App\Models\CashboxTransaction::count())->toBe($entries);
    $this->service->settle($this->employee, $this->keys, allocation: ['clinic_cash_gel' => 0, 'israeli_cash_gel' => 135]);
    expect($this->balances->cashBalances('israeli')['GEL'])->toBe(3000.0)
        ->and($manager->summary($day->fresh())['expectedByCurrency'])->toBe($drawer)
        ->and(\App\Models\CashboxTransaction::count())->toBe($entries);
});


test('Finance cash expense uses accumulated cash and leaves drawer history untouched', function () {
    $manager = app(\App\Support\CashboxManager::class);
    $day = $manager->dayFor(today()->toDateString());
    $before = $manager->summary($day)['expectedByCurrency'];
    $dimensions = app(\App\Services\ExpenseDimensions::class);
    Livewire::test(Finance::class)->mountAction('add_expense')->assertSet('mountedActions.0.data.cash_source', 'withdrawn_cash')
        ->fillForm([
            'transaction_date' => now()->toDateTimeString(), 'amount' => 100, 'currency' => 'GEL',
            'payment_method' => 'cash', 'cash_source' => 'withdrawn_cash', 'description' => 'Held cash expense',
            'expense_direction_id' => $dimensions->id('direction', 'surgery'),
            'expense_type_id' => \App\Models\ExpenseCategory::forceCreate(['name' => 'Held supplies', 'classification_dimension' => 'type', 'parent_id' => $dimensions->id('direction', 'surgery'), 'active' => true])->id,
        ])->callMountedAction()->assertHasNoActionErrors();
    $expense = FinanceTransaction::where('type', 'expense')->sole();
    expect($expense->cash_source)->toBe('withdrawn_cash')->and($expense->cashboxTransaction)->toBeNull()
        ->and($manager->summary($day->fresh())['expectedByCurrency'])->toBe($before)
        ->and(app(EmployeeSalaryFunding::class)->availableCash('clinic'))->toBe(2900.0)
        ->and($this->balances->cashBalances('clinic')['GEL'])->toBe(2900.0);
});
