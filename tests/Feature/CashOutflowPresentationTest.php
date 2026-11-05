<?php

use App\Filament\Pages\Finance;
use App\Models\User;
use App\Services\BogBusinessApiService;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\CashOutflowReport;
use App\Services\Finance\LiquidityReport;
use App\Services\FinanceUsdUsageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fixtures\CashOverviewStorage;

beforeEach(function () {
    CashOverviewStorage::create();
    Http::preventStrayRequests();
    $this->travelTo('2026-11-05 12:00:00');
    app()->setLocale('ka');
    $this->actingAs(User::forceCreate(['name' => 'Synthetic cash audit', 'email' => 'outflow@example.test', 'role' => User::ROLE_OWNER, 'is_active' => true, 'locale' => 'ka']));
    $this->mock(BogBusinessApiService::class)->shouldReceive('currentBalance')->andReturn('9000.00');
    DB::table('cashbox_days')->insert(['id' => 1, 'date' => '2026-10-01', 'status' => 'closed', 'opening_balance' => 1000, 'opening_balance_usd' => 100]);
    $this->report = app(CashOutflowReport::class);
});

function outflowExchange(array $attributes = []): int
{
    return DB::table('partner_finance_transactions')->insertGetId(array_replace([
        'source' => 'israeli', 'type' => 'currency_exchange', 'transacted_at' => '2026-11-05 10:00:00',
        'from_account' => 'cash', 'to_account' => 'cash', 'from_currency' => 'USD', 'from_amount' => 10000,
        'to_currency' => 'GEL', 'to_amount' => 27000, 'exchange_rate' => '2.700000', 'notes' => 'Synthetic exchange',
    ], $attributes));
}

function outflowDeposit(array $attributes = []): int
{
    return DB::table('partner_finance_transactions')->insertGetId(array_replace([
        'source' => 'israeli', 'type' => 'transfer', 'category' => 'bank_deposit', 'from_account' => 'cash', 'to_account' => 'bank',
        'transacted_at' => '2026-11-05 10:00:00', 'currency' => 'GEL', 'amount' => 25000, 'notes' => 'Synthetic separate deposit',
    ], $attributes));
}

function outflowPage()
{
    return Livewire::test(Finance::class)->set('dateFrom', '2026-11-01')->set('dateUntil', '2026-11-05')->call('selectOverviewCard', 'cash_outflow');
}

function outflowSnapshot(): array
{
    return collect(['partner_finance_transactions', 'cashbox_transactions', 'finance_transactions', 'bank_transactions',
        'payments', 'payment_splits', 'finance_opening_balances', 'cashbox_days'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

test('independent outflow includes expenses withdrawals and separate deposits but excludes internal transfers', function () {
    DB::table('cashbox_transactions')->insert(['cashbox_day_id' => 1, 'type' => 'expense', 'transaction_date' => '2026-11-05',
        'currency' => 'GEL', 'amount' => 100, 'payment_method' => 'cash', 'description' => 'Independent expense']);
    outflowDeposit(['amount' => 300]);
    outflowDeposit(['type' => 'owner_withdrawal', 'to_account' => null, 'amount' => 50]);
    outflowDeposit(['to_account' => 'cash', 'amount' => 999]);
    DB::table('cashbox_transactions')->insert(['cashbox_day_id' => 1, 'type' => 'cash_transfer_out', 'cash_transfer_id' => 123,
        'transaction_date' => '2026-11-05', 'currency' => 'GEL', 'amount' => 999, 'payment_method' => 'cash']);
    $groups = $this->report->independentGroups('2026-11-01', '2026-11-05')->keyBy('group_key');
    expect((float) $groups['expenses']->amount)->toBe(100.0)->and((float) $groups['bank_deposit']->amount)->toBe(300.0)
        ->and((float) $groups['owner_withdrawal']->amount)->toBe(50.0)
        ->and((float) $this->report->independentTotals('2026-11-01', '2026-11-05')['GEL']->amount)->toBe(450.0);
    outflowPage()->call('selectCashOutflowGroup', 'bank_deposit')->assertSee('Synthetic separate deposit')
        ->assertSee(__('finance-overview.independent_outflow_help'))
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && (float) $rows->sole()->amount === 300.0);
});

test('a bank destination on an exchange is one explicit combined operation with no independent debit', function () {
    $id = outflowExchange(['to_account' => 'bank']);
    $snapshot = outflowSnapshot();
    expect($this->report->independentTotals('2026-11-01', '2026-11-05'))->toBeEmpty();
    $row = $this->report->exchanges('2026-11-01', '2026-11-05')->sole();
    expect((float) $row->bank_amount)->toBe(27000.0)->and((float) $row->retained_amount)->toBe(0.0)
        ->and((float) $row->from_amount)->toBe(10000.0)->and((float) $row->to_amount)->toBe(27000.0);
    $page = outflowPage()->assertSee(__('finance-overview.exchange_operations'))->assertSee('27,000.00 GEL');
    expect(substr_count($page->html(), 'wire:key="cash-exchange-'.$id.'"'))->toBe(1)
        ->and($page->viewData('outflowGroups'))->toBeEmpty()->and(outflowSnapshot())->toBe($snapshot);
});

test('the 27000 received and 25000 deposit example is not guessed into a 2000 retained allocation', function () {
    outflowExchange();
    outflowDeposit(['notes' => 'Synthetic exchange']); // Even identical free text/time is not a relationship.
    $snapshot = outflowSnapshot();
    $row = $this->report->exchanges('2026-11-01', '2026-11-05')->sole();
    expect($row->bank_amount)->toBeNull()->and($row->retained_amount)->toBeNull()->and((float) $row->cash_received)->toBe(27000.0)
        ->and((float) $this->report->independentTotals('2026-11-01', '2026-11-05')['GEL']->amount)->toBe(25000.0);
    outflowPage()->assertSee(__('finance-overview.exchange_link_limit'))->assertSee(__('finance-overview.exchange_unknown'))
        ->assertViewHas('exchangeOperations', fn ($rows) => $rows->count() === 1)
        ->call('selectCashOutflowGroup', 'bank_deposit')
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && (float) $rows->sole()->amount === 25000.0);
    expect(outflowSnapshot())->toBe($snapshot);
});

test('an exchange without a recorded deposit still exposes both currency legs and leaves retention unproven', function () {
    outflowExchange(['from_amount' => '11600.00', 'to_amount' => '30111.15', 'exchange_rate' => '2.595789']);
    expect($this->report->independentEntries('2026-11-01', '2026-11-05')->count())->toBe(0);
    $row = $this->report->exchanges('2026-11-01', '2026-11-05')->sole();
    expect((float) $row->from_amount)->toBe(11600.0)->and((float) $row->to_amount)->toBe(30111.15)
        ->and((float) $row->exchange_rate)->toBe(2.595789)->and($row->bank_amount)->toBeNull()->and($row->retained_amount)->toBeNull();
    outflowPage()->assertSee('2.595789')->assertSee('30,111.15 GEL')->assertSee(__('finance-overview.exchange_cash_received'));
});

test('unlinked deposits stay independent even when their amount date and notes match exchange proceeds', function () {
    outflowExchange();
    outflowDeposit(['amount' => 27000, 'notes' => 'Synthetic exchange']);
    expect($this->report->independentEntries('2026-11-01', '2026-11-05')->count())->toBe(1)
        ->and((float) $this->report->independentTotals('2026-11-01', '2026-11-05')['GEL']->amount)->toBe(27000.0)
        ->and($this->report->exchanges('2026-11-01', '2026-11-05')->sole()->bank_amount)->toBeNull();
});

test('multiple exchanges obey date source and either-leg currency filters without dropping their counterpart', function () {
    $first = outflowExchange(['transacted_at' => '2026-11-01 00:00:00']);
    $last = outflowExchange(['transacted_at' => '2026-11-05 23:59:59', 'source' => 'clinic', 'from_currency' => 'GEL', 'from_amount' => 270, 'to_currency' => 'USD', 'to_amount' => 100]);
    outflowExchange(['transacted_at' => '2026-10-31 23:59:59']);
    outflowExchange(['transacted_at' => '2026-11-06 00:00:00']);
    outflowExchange(['from_account' => 'bank', 'to_account' => 'bank']);
    $rows = $this->report->exchanges('2026-11-01', '2026-11-05')->get();
    expect($rows->pluck('id')->all())->toBe([$first, $last])
        ->and($this->report->exchanges('2026-11-01', '2026-11-05', 'clinic', 'USD')->sole()->id)->toBe($last)
        ->and($this->report->exchanges('2026-11-01', '2026-11-05', 'israeli', 'GEL')->sole()->id)->toBe($first)
        ->and($this->report->exchanges('2026-11-01', '2026-11-05', currency: 'EUR')->count())->toBe(0);
    $page = outflowPage()->assertViewHas('exchangeOperations', fn ($rows) => $rows->count() === 2);
    $page->set('overviewCurrency', 'USD')->assertViewHas('exchangeOperations', fn ($rows) => $rows->count() === 2)
        ->set('businessSource', 'clinic')->assertViewHas('exchangeOperations', fn ($rows) => $rows->count() === 1)
        ->set('dateUntil', '2026-11-04')->assertViewHas('exchangeOperations', fn ($rows) => $rows->isEmpty());
});

test('native cash movements reconcile exactly while balances PnL and records remain unchanged', function () {
    outflowExchange(['from_amount' => '100.01', 'to_amount' => '270.03']);
    outflowDeposit(['amount' => '250.02']);
    $report = $this->report;
    $ledger = app(AccountingLedger::class);
    $cash = app(FinanceUsdUsageService::class)->cashBalances('israeli');
    $bankSnapshot = ['currency' => 'GEL', 'reported_balance' => 9000];
    $liquidity = app(LiquidityReport::class)->current('all', $bankSnapshot);
    $pnl = $ledger->pnlTotals(null, null)->toJson();
    $movements = $ledger->movementTotals(null, null, 'cash')->toJson();
    $snapshot = outflowSnapshot();
    $usd = $report->movements('2026-11-01', '2026-11-05', 'israeli')->where('currency', 'USD')->get();
    $gel = $report->movements('2026-11-01', '2026-11-05', 'israeli')->where('currency', 'GEL')->get();
    expect($usd->sum(fn ($r) => (int) round($r->amount * 100)))->toBe(10001)
        ->and($gel->where('metric', 'inflow')->sum(fn ($r) => (int) round($r->amount * 100)))->toBe(27003)
        ->and($gel->where('metric', 'outflow')->sum(fn ($r) => (int) round($r->amount * 100)))->toBe(25002)
        ->and((float) $report->totals('2026-11-01', '2026-11-05')['USD']->amount)->toBe(100.01)
        ->and($report->independentTotals('2026-11-01', '2026-11-05')->has('USD'))->toBeFalse();
    $page = outflowPage()->call('selectCashOutflowGroup', 'bank_deposit')->call('selectOverviewCard', 'cash')
        ->assertSee(__('finance-overview.physical_cash_outflow'));
    expect(app(FinanceUsdUsageService::class)->cashBalances('israeli'))->toBe($cash)
        ->and(app(LiquidityReport::class)->current('all', $bankSnapshot))->toEqual($liquidity)
        ->and($ledger->pnlTotals(null, null)->toJson())->toBe($pnl)
        ->and($ledger->movementTotals(null, null, 'cash')->toJson())->toBe($movements)->and(outflowSnapshot())->toBe($snapshot);
});

test('exchange pagination does not change independent totals and resets with the reporting filters', function () {
    foreach (range(1, 26) as $i) {
        outflowExchange(['notes' => 'Exchange '.$i]);
    }
    outflowDeposit(['amount' => 50]);
    $page = outflowPage()->assertViewHas('exchangeOperations', fn ($rows) => $rows->count() === 25 && $rows->hasMorePages());
    $figures = $page->viewData('figures');
    $page->call('setPage', 2, 'exchangePage')->assertViewHas('exchangeOperations', fn ($rows) => $rows->count() === 1)
        ->assertViewHas('figures', $figures)
        ->set('dateFrom', '2026-11-05')->assertViewHas('exchangeOperations', fn ($rows) => $rows->currentPage() === 1 && $rows->count() === 25);
});

test('exchange detail search and type filters do not alter the independent subtotal', function () {
    outflowExchange(['notes' => 'Unique exchange note']);
    outflowDeposit(['amount' => 50]);
    $page = outflowPage();
    $figures = $page->viewData('figures');
    $page->set('overviewSearch', 'UNIQUE EXCHANGE')->assertViewHas('exchangeOperations', fn ($rows) => $rows->count() === 1)
        ->set('overviewSearch', 'Missing note')->assertViewHas('exchangeOperations', fn ($rows) => $rows->isEmpty())
        ->set('overviewSearch', '')->set('overviewType', 'currency_exchange')->assertViewHas('exchangeOperations', fn ($rows) => $rows->count() === 1)
        ->set('overviewType', 'expense')->assertViewHas('exchangeOperations', fn ($rows) => $rows->isEmpty())
        ->set('overviewType', '')->set('overviewClassification', 'Materials')->assertViewHas('exchangeOperations', fn ($rows) => $rows->isEmpty())
        ->assertViewHas('figures', $figures);
});
