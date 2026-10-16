<?php

use App\Filament\Pages\FinanceOpeningBalances;
use App\Models\CashboxDay;
use App\Models\FinanceOpeningBalance;
use App\Models\User;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\LiquidityReport;
use App\Services\Finance\OpeningBalanceService;
use App\Services\FinanceUsdUsageService;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-16 12:00:00');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->service = app(OpeningBalanceService::class);
    $this->cash = app(CashboxManager::class);
    $this->data = ['source' => 'cash', 'currency' => 'GEL', 'amount' => 10000,
        'effective_date' => '2026-09-01', 'note' => 'Physical cash held before ERP launch'];
});

test('Owner confirms historical physical cash and modal closes with refreshed auditable history', function () {
    $day = $this->cash->dayFor('2026-09-01');
    $page = Livewire::test(FinanceOpeningBalances::class)->mountAction('create')
        ->assertActionDataSet(['effective_date' => '2026-09-01'])
        ->setActionData($this->data)->callMountedAction()->assertHasNoActionErrors()
        ->assertActionNotMounted('create')->assertNotified(__('finance-overview.opening_saved'))
        ->assertSee('10,000.00')->assertSee('Physical cash held before ERP launch');
    $opening = FinanceOpeningBalance::sole();
    expect($opening->created_by)->toBe($this->owner->id)
        ->and($opening->effective_date->toDateString())->toBe('2026-09-01')
        ->and($opening->source)->toBe('cash')->and($opening->account_identifier)->toBe('cashier')
        ->and($day->fresh()->summary()['opening']['GEL'])->toBe(10000.0)
        ->and($day->fresh()->summary()['cashIncome'])->toBe(0.0)
        ->and($this->cash->physicalCashBalances()['GEL'])->toBe(10000.0);
});

test('opening adds exactly 10000 to cumulative cash with no business accounting or payment changes', function () {
    $day = $this->cash->dayFor('2026-09-01');
    $day->transactions()->create(['type' => 'cash_withdrawal', 'amount' => 12000, 'currency' => 'GEL',
        'payment_method' => 'cash', 'transaction_date' => '2026-09-02 12:00:00', 'description' => 'Existing withdrawal']);
    $tables = ['finance_transactions', 'cashbox_transactions', 'payments', 'payment_splits', 'visits', 'patients',
        'partner_finance_transactions', 'partner_patient_payments', 'bank_transactions'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $snapshot();
    $ledger = app(AccountingLedger::class);
    $pnl = $ledger->pnlTotals(null, null)->toJson();
    $israeli = app(FinanceUsdUsageService::class)->cashBalances('israeli');
    $cash = $this->cash->physicalCashBalances()['GEL'];
    $this->service->create($this->data, $this->owner);
    expect($this->cash->physicalCashBalances()['GEL'])->toBe($cash + 10000.0)
        ->and(app(LiquidityReport::class)->current()['totals']['GEL']['cash'])->toBe(-2000.0)
        ->and($ledger->pnlTotals(null, null)->toJson())->toBe($pnl)
        ->and($ledger->pnl(null, null)->count())->toBe(0)
        ->and($snapshot())->toBe($before)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli'))->toBe($israeli)
        ->and($day->fresh()->opening_balance)->toBe('0.00')
        ->and($day->fresh()->summary()['opening']['GEL'])->toBe(10000.0);
});

test('later withdrawals consume historical opening cash without creating income', function () {
    $day = $this->cash->dayFor('2026-09-01');
    $this->service->create($this->data, $this->owner);
    $day->transactions()->create(['type' => 'cash_withdrawal', 'amount' => 4000, 'currency' => 'GEL',
        'payment_method' => 'cash', 'transaction_date' => '2026-09-01 12:00:00']);
    expect($day->fresh()->summary()['expected'])->toBe(6000.0)
        ->and($this->cash->physicalCashBalances()['GEL'])->toBe(6000.0)
        ->and($day->fresh()->summary()['cashIncome'])->toBe(0.0);
});

test('a missing starting balance can be recorded on a posted first day without rewriting activity', function () {
    $day = $this->cash->today();
    $day->transactions()->create(['type' => 'cash_withdrawal', 'amount' => 1000, 'currency' => 'GEL',
        'payment_method' => 'cash', 'transaction_date' => now()]);
    $original = $day->fresh()->getAttributes();
    $this->service->create([...$this->data, 'effective_date' => today()->toDateString()], $this->owner);
    expect($day->fresh()->getAttributes())->toBe($original)
        ->and($day->fresh()->summary()['expected'])->toBe(9000.0)
        ->and($this->cash->physicalCashBalances()['GEL'])->toBe(9000.0);
});

test('historical opening leaves closed days and withdrawal identities unchanged', function () {
    $day = CashboxDay::create(['date' => '2026-09-01', 'status' => 'closed', 'opening_balance' => 0,
        'actual_closing_balance' => 8000, 'expected_closing_balance' => -2000, 'carry_forward_balance' => 0]);
    $day->transactions()->create(['type' => 'cash_withdrawal', 'amount' => 2000, 'currency' => 'GEL',
        'payment_method' => 'cash', 'transaction_date' => '2026-09-01 12:00:00']);
    $before = $day->fresh()->getAttributes();
    $this->service->create($this->data, $this->owner);
    expect($day->fresh()->getAttributes())->toBe($before)
        ->and($this->cash->physicalCashBalances()['GEL'])->toBe(8000.0);
});

test('exact retries do not duplicate an opening and conflicting duplicates have visible modal validation', function () {
    $first = $this->service->create($this->data, $this->owner);
    $again = $this->service->create($this->data, $this->owner);
    expect($again->id)->toBe($first->id)->and(FinanceOpeningBalance::count())->toBe(1);
    $page = Livewire::test(FinanceOpeningBalances::class)->mountAction('create')
        ->setActionData([...$this->data, 'amount' => 999])->callMountedAction()
        ->assertHasActionErrors(['effective_date'])->assertActionMounted('create');
    expect($page->instance()->getErrorBag()->all())->toContain(__('finance-overview.opening_exists'));
    expect(FinanceOpeningBalance::count())->toBe(1)->and($this->cash->physicalCashBalances()['GEL'])->toBe(10000.0);
});

test('mid-history backdating is blocked visibly without dropping earlier cash activity', function () {
    $this->cash->dayFor('2026-09-01');
    $page = Livewire::test(FinanceOpeningBalances::class)->mountAction('create')
        ->setActionData([...$this->data, 'effective_date' => '2026-09-10'])->callMountedAction()
        ->assertHasActionErrors(['effective_date'])->assertActionMounted('create');
    expect($page->instance()->getErrorBag()->all())->toContain(__('finance-overview.historical_opening_guard', ['date' => '2026-09-01']));
    expect(FinanceOpeningBalance::count())->toBe(0);
});

test('opening date is blank without accounting history and nonnegative cash validation targets amount', function () {
    Livewire::test(FinanceOpeningBalances::class)->mountAction('create')->assertActionDataSet(['effective_date' => null])
        ->setActionData([...$this->data, 'amount' => -1])->callMountedAction()->assertHasActionErrors(['amount']);
    expect(FinanceOpeningBalance::count())->toBe(0);
});

test('bank source and independent currencies preserve existing rules', function () {
    $this->service->create($this->data, $this->owner);
    $this->service->create([...$this->data, 'currency' => 'USD', 'amount' => 200], $this->owner);
    $this->service->create([...$this->data, 'source' => 'bank', 'bank' => 'BOG', 'account_identifier' => 'GE00OPENING', 'amount' => 500], $this->owner);
    expect($this->cash->physicalCashBalances())->toBe(['GEL' => 10000.0, 'USD' => 200.0])
        ->and(FinanceOpeningBalance::count())->toBe(3);
});

test('nonowners cannot open the page or invoke the opening service', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    $this->actingAs($admin);
    Livewire::test(FinanceOpeningBalances::class)->assertForbidden();
    try {
        $this->service->create($this->data, $admin);
        $this->fail('Non-owner should be rejected');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(403);
    }
    expect(FinanceOpeningBalance::count())->toBe(0);
});
