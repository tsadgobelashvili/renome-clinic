<?php

use App\Filament\Pages\Finance;
use App\Models\CashboxTransaction;
use App\Models\Employee;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use App\Services\EmployeeAdvanceService;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\CashOutflowReport;
use App\Services\Finance\EntryDetails;
use App\Services\FinanceManager;
use App\Services\FinanceUsdUsageService;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->travelTo('2026-10-12 12:00:00');
    $this->manager = app(CashboxManager::class);
    $this->previous = $this->manager->today();
    $this->previous->update(['opening_balance' => 1000]);
    $this->manager->close($this->previous, 1000, 0);
    $this->travelTo('2026-10-13 12:00:00');
    $this->day = $this->manager->today();
    $this->ledger = app(AccountingLedger::class);
});

test('irrigator modal requires one of the two existing Clinic cash sources', function (string $locale) {
    app()->setLocale($locale);
    $page = Livewire::test(Finance::class)->mountAction('add_expense')->fillForm(['transaction_type' => 'irrigator_withdrawal'])
        ->assertFormFieldExists('source', fn ($field) => $field->isRequired() && $field->getOptions() === CashboxTransaction::cashUsageSources());
    expect(CashboxTransaction::cashUsageSources())->toHaveKeys(['current_cashier', 'withdrawn_cash']);
    $page->fillForm(['source' => null, 'amount' => 600])->callMountedAction()->assertHasActionErrors(['source']);
    $page->fillForm(['source' => 'israeli', 'amount' => 600])->callMountedAction()->assertHasActionErrors(['source']);
    expect(CashboxTransaction::where('type', 'irrigator_withdrawal')->count())->toBe(0);
})->with(['ka', 'en']);

test('Owner launches irrigator only inside Add Expense with the neutral fields visible', function (string $locale) {
    app()->setLocale($locale);
    $page = Livewire::test(Finance::class)->assertActionDoesNotExist('irrigatorWithdrawal')
        ->mountAction('add_expense')
        ->assertFormFieldExists('transaction_type', fn ($field) => $field->getOptions() === [
            'expense' => __('cash-usage.expense'), 'irrigator_withdrawal' => __('cash-usage.irrigator_withdrawal'),
        ])
        ->assertFormFieldIsHidden('source')->assertFormFieldIsVisible('expense_direction_id')
        ->fillForm(['transaction_type' => 'irrigator_withdrawal'])
        ->assertFormFieldIsVisible('amount')->assertFormFieldIsVisible('source')->assertFormFieldIsVisible('note');
    foreach (['transaction_date', 'expense_direction_id', 'expense_type_id', 'description', 'currency', 'payment_method', 'cash_source'] as $field) {
        $page->assertFormFieldIsHidden($field);
    }
    $page->fillForm(['transaction_type' => 'expense'])->assertFormFieldIsVisible('expense_direction_id')->assertFormFieldIsHidden('source');
})->with(['ka', 'en']);

test('non Owners cannot see or invoke the Add Expense irrigator branch', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $page = new Finance;
    expect($page->expenseTransactionTypes())->toBe(['expense' => __('cash-usage.expense')]);
    Livewire::test(Finance::class)->assertForbidden();
    $action = (new ReflectionMethod(Finance::class, 'transactionAction'))->invoke($page, 'expense', 'Add Expense', 'danger');
    $handler = $action->getActionFunction();
    expect(fn () => $handler(['transaction_type' => 'irrigator_withdrawal', 'amount' => 600,
        'source' => 'withdrawn_cash', 'request_key' => (string) Str::uuid()], app(FinanceManager::class)))
        ->toThrow(HttpException::class);
    expect(CashboxTransaction::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0);
})->with([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('ordinary Add Expense retains its required classification and accounting', function () {
    $dimensions = app(ExpenseDimensions::class);
    $direction = $dimensions->id('direction', 'general');
    Livewire::test(Finance::class)->mountAction('add_expense')->fillForm(['amount' => 50])
        ->callMountedAction()->assertHasActionErrors(['expense_direction_id', 'expense_type_id'])
        ->fillForm(['expense_direction_id' => $direction, 'expense_type_id' => $dimensions->id('type', 'other', $direction),
            'amount' => 50, 'description' => 'Ordinary expense', 'note' => 'Original workflow'])
        ->callMountedAction()->assertHasNoActionErrors();
    $expense = FinanceTransaction::sole();
    expect($expense->type)->toBe('expense')->and((float) $expense->amount)->toBe(50.0)
        ->and($expense->cash_source)->toBe('withdrawn_cash')
        ->and($this->manager->availableCashForOpening($this->day)['GEL'])->toBe(950.0)
        ->and($this->ledger->cardAmounts(null, null)->count())->toBe(0)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe(50.0)
        ->and(CashboxTransaction::where('type', 'irrigator_withdrawal')->count())->toBe(0);
});

test('Add Expense irrigator submissions retain their retry identity', function () {
    $key = (string) Str::uuid();
    $data = ['transaction_type' => 'irrigator_withdrawal', 'request_key' => $key, 'source' => 'withdrawn_cash', 'amount' => 600, 'note' => 'Retry'];
    $page = Livewire::test(Finance::class)->callAction('add_expense', $data)->assertHasNoActionErrors();
    $page->callAction('add_expense', $data)->assertHasNoActionErrors();
    expect(CashboxTransaction::where('type', 'irrigator_withdrawal')->count())->toBe(1)
        ->and($this->manager->availableCashForOpening($this->day)['GEL'])->toBe(400.0)
        ->and((float) $this->ledger->cardAmounts(null, null)->sum('amount'))->toBe(600.0)
        ->and(FinanceTransaction::count())->toBe(0);
});

test('600 from current cash reduces only the drawer and adds neutral card money', function () {
    $this->manager->addOpeningBalance($this->day, 800);
    $this->day->refresh();
    $beforePnl = $this->ledger->pnlTotals(null, null)->toArray();
    $held = $this->manager->availableCashForOpening($this->day)['GEL'];
    Livewire::test(Finance::class)->callAction('add_expense', ['transaction_type' => 'irrigator_withdrawal', 'source' => 'current_cashier', 'amount' => 600])
        ->assertHasNoActionErrors()->assertActionNotMounted('add_expense');
    expect($this->day->summary()['expected'])->toBe(200.0)
        ->and($this->manager->availableCashForOpening($this->day)['GEL'])->toBe($held)
        ->and((float) $this->ledger->cardAmounts(null, null, 'clinic')->sum('amount'))->toBe(600.0)
        ->and($this->ledger->pnlTotals(null, null)->toArray())->toEqual($beforePnl);
    $row = app(CashOutflowReport::class)->movements('2026-10-13', '2026-10-13')->sole();
    expect($row->cash_source)->toBe('current_cashbox')->and($row->description)->toContain('წყარო: მიმდინარე სალარო', 'Cash -600.00 ₾', 'Card +600.00 ₾');
});

test('600 from accumulated cash preserves todays drawer and all noncash accounting', function () {
    $this->manager->addOpeningBalance($this->day, 200);
    $this->day->refresh();
    $before = $this->day->summary();
    $pnl = $this->ledger->pnlTotals(null, null)->toArray();
    $israeli = app(FinanceUsdUsageService::class)->cashBalances('israeli');
    $partners = DB::table('partner_finance_transactions')->get()->toArray();
    Livewire::test(Finance::class)->callAction('add_expense', ['transaction_type' => 'irrigator_withdrawal', 'source' => 'withdrawn_cash', 'amount' => 600])
        ->assertHasNoActionErrors()->assertNotified(__('cash-usage.saved'))->assertActionNotMounted('add_expense')
        ->call('selectOverviewCard', 'cash')->assertSee('წყარო: ადრე გატანილი თანხა')->assertSee('Accumulated cash -600.00 ₾');
    $after = $this->day->summary();
    expect($after['expected'])->toBe($before['expected'])->and($after['expected'])->toBe(200.0)
        ->and($after['cashIncome'])->toBe($before['cashIncome'])->and($after['cardIncome'])->toBe($before['cardIncome'])
        ->and($after['cashExpenses'])->toBe($before['cashExpenses'])->and($after['cardExpenses'])->toBe($before['cardExpenses'])
        ->and($this->manager->availableCashForOpening($this->day)['GEL'])->toBe(200.0)
        ->and($this->manager->retainedCashByCurrency()['GEL'])->toBe(200.0)
        ->and($after['cardAmountByCurrency']['GEL'])->toBe(0.0)
        ->and((float) $this->ledger->cardAmounts(null, null, 'clinic')->sum('amount'))->toBe(600.0)
        ->and($this->ledger->pnlTotals(null, null)->toArray())->toEqual($pnl)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli'))->toEqual($israeli)
        ->and(DB::table('partner_finance_transactions')->get()->toArray())->toEqual($partners)
        ->and($this->ledger->cardAmounts(null, null, 'israeli')->count())->toBe(0)
        ->and(Payment::count())->toBe(0)->and(Visit::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0);
    $row = app(CashOutflowReport::class)->movements('2026-10-13', '2026-10-13')->sole();
    expect($row->cash_source)->toBe('accumulated_cash')->and($row->metric)->toBe('internal_transfer')
        ->and($row->description)->toContain('წყარო: ადრე გატანილი თანხა', 'Accumulated cash -600.00 ₾', 'Card +600.00 ₾', 'Net 0.00 ₾')
        ->and($row->description)->not->toContain(' · Cash -');
    $detail = app(EntryDetails::class)->query($this->ledger->cardAmounts(null, null, 'clinic'))->sole();
    expect($detail->detail_account)->toBe('withdrawn_cash');
    $this->manager->close($this->day, 200, 0);
    expect($this->manager->retainedCashByCurrency()['GEL'])->toBe(400.0)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe(400.0);
});

test('selected source cannot borrow the other sources cash', function (string $source, int $opening) {
    $this->manager->addOpeningBalance($this->day, $opening);
    $this->day->refresh();
    $summary = $this->day->summary();
    $cash = $this->manager->physicalCashBalances();
    $held = $this->manager->availableCashForOpening($this->day);
    Livewire::test(Finance::class)->callAction('add_expense', ['transaction_type' => 'irrigator_withdrawal', 'source' => $source, 'amount' => 600])->assertHasActionErrors(['amount'])
        ->assertMountedActionModalSee(__('cash-usage.insufficient_source', ['source' => CashboxTransaction::cashUsageSources()[$source], 'available' => '200.00 ₾']));
    expect(CashboxTransaction::where('type', 'irrigator_withdrawal')->count())->toBe(0)
        ->and($this->day->summary())->toEqual($summary)->and($this->manager->physicalCashBalances())->toEqual($cash)
        ->and($this->manager->availableCashForOpening($this->day))->toEqual($held)
        ->and($this->ledger->cardAmounts(null, null)->count())->toBe(0);
})->with([['current_cashier', 200], ['withdrawn_cash', 800]]);

test('source is part of the retry identity and accumulated cash is deducted only once', function () {
    $key = (string) Str::uuid();
    $first = $this->manager->reconcileIrrigator(600, 'Note', $key, 'withdrawn_cash');
    $this->day->update(['status' => 'closed']);
    expect($this->manager->reconcileIrrigator(600, 'Note', $key, 'withdrawn_cash')->id)->toBe($first->id);
    expect(fn () => $this->manager->reconcileIrrigator(600, 'Note', $key, 'current_cashier'))->toThrow(ValidationException::class);
    expect(CashboxTransaction::where('type', 'irrigator_withdrawal')->count())->toBe(1)
        ->and((float) $this->ledger->cardAmounts(null, null)->sum('amount'))->toBe(600.0)
        ->and($this->manager->availableCashForOpening($this->day)['GEL'])->toBe(400.0);
});

test('source deduction and card reconciliation roll back together', function (string $source, string $event) {
    $this->manager->addOpeningBalance($this->day, $source === 'current_cashier' ? 800 : 200);
    $this->day->refresh();
    $cash = $this->manager->physicalCashBalances();
    $summary = $this->day->summary();
    $pool = $this->manager->availableCashForOpening($this->day);
    Event::listen('eloquent.'.$event.': '.CashboxTransaction::class, function ($record) {
        if ($record->type === 'irrigator_withdrawal') {
            throw new RuntimeException('Atomic posting failure');
        }
    });
    expect(fn () => $this->manager->reconcileIrrigator(600, null, (string) Str::uuid(), $source))->toThrow(RuntimeException::class);
    expect($this->manager->physicalCashBalances())->toEqual($cash)->and($this->day->summary())->toEqual($summary)
        ->and($this->manager->availableCashForOpening($this->day))->toEqual($pool)
        ->and(CashboxTransaction::where('type', 'irrigator_withdrawal')->count())->toBe(0)
        ->and($this->ledger->cardAmounts(null, null)->count())->toBe(0);
})->with([['current_cashier', 'creating'], ['current_cashier', 'created'], ['withdrawn_cash', 'creating'], ['withdrawn_cash', 'created']]);

test('old 600 record remains unchanged and retains current source behavior including retry', function () {
    $this->manager->addOpeningBalance($this->day, 800);
    $this->day->refresh();
    $key = (string) Str::uuid();
    $id = DB::table('cashbox_transactions')->insertGetId(['cashbox_day_id' => $this->day->id, 'cash_usage_key' => $key,
        'type' => 'irrigator_withdrawal', 'amount' => 600, 'currency' => 'GEL', 'payment_method' => 'cash',
        'transaction_date' => now(), 'created_by' => auth()->id(),
        'description' => 'ირიგატორის თანხის აღება — 600.00 ₾ · Cash -600.00 ₾ · Card +600.00 ₾ · Net 0.00 ₾']);
    $original = DB::table('cashbox_transactions')->where('id', $id)->first();
    expect($this->manager->reconcileIrrigator(600, null, $key)->id)->toBe($id);
    $this->manager->reconcileIrrigator(100, null, (string) Str::uuid(), 'withdrawn_cash');
    expect(DB::table('cashbox_transactions')->where('id', $id)->first())->toEqual($original)
        ->and($this->day->summary()['expected'])->toBe(200.0)
        ->and($this->manager->availableCashForOpening($this->day)['GEL'])->toBe(100.0);
});

test('accumulated irrigator usage reduces the same pool available for advances and opening transfers', function () {
    $this->manager->reconcileIrrigator(600, null, (string) Str::uuid(), 'withdrawn_cash');
    expect(fn () => $this->manager->addOpeningBalance($this->day, 500))->toThrow(ValidationException::class);
    expect(fn () => $this->manager->transferCash($this->previous->fresh(), $this->day, 500, 'GEL', null, (string) Str::uuid()))->toThrow(ValidationException::class);
    $employee = Employee::create(['first_name' => 'Test', 'last_name' => 'Advance']);
    expect(fn () => app(EmployeeAdvanceService::class)->issue(['posting_key' => (string) Str::uuid(), 'employee_id' => $employee->id,
        'amount' => 500, 'date' => today()->toDateString(), 'source' => 'accumulated_cash'], auth()->user()))->toThrow(ValidationException::class);
    expect($this->manager->availableCashForOpening($this->day)['GEL'])->toBe(400.0)
        ->and(PartnerFinanceTransaction::count())->toBe(0);
});
