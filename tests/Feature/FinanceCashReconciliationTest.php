<?php

use App\Filament\Pages\Finance;
use App\Models\User;
use App\Services\BogBusinessApiService;
use App\Services\Finance\CashOutflowReport;
use App\Services\Finance\LiquidityReport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\Fixtures\CashOverviewStorage;

beforeEach(function () {
    CashOverviewStorage::create();
    Http::preventStrayRequests();
    config(['app.timezone' => 'Asia/Tbilisi']);
    $this->travelTo(Carbon::parse('2026-09-14 12:00:00', 'Asia/Tbilisi'));
    app()->setLocale('en');
    $this->actingAs(User::forceCreate(['name' => 'Cash audit', 'email' => 'cash@example.test', 'role' => User::ROLE_OWNER, 'is_active' => true]));
    $this->mock(BogBusinessApiService::class)->shouldReceive('currentBalance')->andReturn('0.00');
    DB::table('cashbox_days')->insert(['id' => 1, 'date' => '2026-09-01', 'status' => 'closed', 'opening_balance' => 1000, 'opening_balance_usd' => 100]);
    $this->report = app(CashOutflowReport::class);
});

function reconciliationPayment(array $splits, array $attributes = []): array
{
    $payment = DB::table('payments')->insertGetId(array_replace([
        'payment_date' => '2026-09-10', 'created_at' => '2026-09-14 12:00:00', 'is_historical' => true, 'comment' => 'Historical receipt',
    ], $attributes));
    $ids = [];
    foreach ($splits as [$currency, $amount, $method]) {
        $ids[] = DB::table('payment_splits')->insertGetId(['payment_id' => $payment, 'currency' => $currency, 'amount' => $amount, 'payment_method' => $method]);
    }

    return [$payment, $ids];
}

function reconciliationDrawer(array $attributes = []): void
{
    DB::table('cashbox_transactions')->insert(array_replace([
        'cashbox_day_id' => 1, 'type' => 'other_income', 'transaction_date' => '2026-09-14 10:00:00',
        'amount' => 10, 'currency' => 'GEL', 'payment_method' => 'cash', 'description' => 'Drawer receipt',
    ], $attributes));
}

function reconciliationPartner(array $attributes = []): void
{
    DB::table('partner_finance_transactions')->insert(array_replace([
        'source' => 'israeli', 'type' => 'transfer', 'transacted_at' => '2026-09-14 23:59:59',
        'from_account' => 'cash', 'to_account' => 'bank', 'amount' => 10, 'currency' => 'GEL',
    ], $attributes));
}

test('overview renders historical cash by business date currency and source without card or deleted splits', function () {
    reconciliationPayment([['GEL', 500, 'cash'], ['USD', 50, 'cash'], ['GEL', 900, 'card']]);
    reconciliationPayment([['GEL', 777, 'cash']], ['deleted_at' => now(), 'comment' => 'Deleted receipt']);
    reconciliationPayment([['GEL', 30, 'cash']], ['payment_date' => '2026-09-09', 'comment' => 'Outside period']);
    $page = Livewire::test(Finance::class)->set('dateFrom', '2026-09-10')->set('dateUntil', '2026-09-10')
        ->call('selectOverviewCard', 'cash')->assertSee('Historical receipt')->assertDontSee('Deleted receipt')->assertDontSee('Outside period')
        ->assertSee('Current cash is the balance through today')->assertSee('Full filtered movement totals')
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 2)
        ->assertViewHas('cashMovementTotals', fn ($rows) => (float) $rows->firstWhere('currency', 'GEL')->inflow === 500.0
            && (float) $rows->firstWhere('currency', 'USD')->inflow === 50.0);
    $figures = $page->viewData('figures');
    expect($figures['GEL']['revenue'])->toBe(1400.0)->and($figures['GEL']['cash'])->toBe(1530.0)->and($figures['USD']['cash'])->toBe(150.0);
    $page->set('overviewCurrency', 'USD')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && (float) $rows->first()->amount === 50.0)
        ->set('businessSource', 'israeli')->assertDontSee('Historical receipt')->assertViewHas('cashMovementTotals', fn ($rows) => $rows->isEmpty())
        ->set('businessSource', 'clinic')->set('cashDirection', 'outflow')->assertDontSee('Historical receipt');
});

test('historical cash mirrors are preferred per split or legacy payment currency without losing other splits', function () {
    [$payment, $splits] = reconciliationPayment([['GEL', 100, 'cash'], ['USD', 10, 'cash']]);
    reconciliationDrawer(['payment_id' => $payment, 'payment_split_id' => $splits[0], 'amount' => 100, 'type' => 'patient_payment', 'transaction_date' => '2026-09-10 12:00:00']);
    [$legacy] = reconciliationPayment([['GEL', 200, 'cash']]);
    reconciliationDrawer(['payment_id' => $legacy, 'amount' => 200, 'type' => 'patient_payment', 'transaction_date' => '2026-09-10 12:00:00']);
    [$mixed, $mixedSplits] = reconciliationPayment([['GEL', 300, 'cash'], ['GEL', 900, 'card']]);
    reconciliationDrawer(['payment_id' => $mixed, 'payment_split_id' => $mixedSplits[1], 'payment_method' => 'card', 'amount' => 900, 'transaction_date' => '2026-09-10 12:00:00']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = $this->report->movements('2026-09-10', '2026-09-10', 'clinic', 'inflow')->get();
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
    expect($rows)->toHaveCount(4)->and($rows->where('currency', 'GEL')->sum('amount'))->toEqual(600)
        ->and($rows->where('currency', 'USD')->sum('amount'))->toEqual(10);
});

test('internal transfers remain neutral in all movements and disappear from the actual outflow card and drilldown', function () {
    foreach (['cash_transfer_out', 'cash_transfer_in'] as $type) {
        reconciliationDrawer(['cash_transfer_id' => 1, 'type' => $type, 'amount' => 500, 'description' => 'Drawer internal fixture']);
    }
    reconciliationDrawer(['type' => 'cash_transfer_out', 'amount' => 20, 'description' => 'External transfer']);
    reconciliationPartner(['source' => 'clinic', 'amount' => 30, 'notes' => 'Bank deposit']);
    reconciliationPartner(['source' => 'clinic', 'to_account' => 'cash', 'amount' => 50, 'notes' => 'Internal partner transfer']);
    $page = Livewire::test(Finance::class)->call('selectOverviewCard', 'cash')
        ->assertSee('Drawer internal fixture')->assertSee('Internal partner transfer')->assertSee('Internal cash transfer')
        ->assertViewHas('cashMovementTotals', fn ($rows) => (float) $rows->first()->outflow === 50.0 && (float) $rows->first()->inflow === 0.0);
    $cash = $page->viewData('liquidity')['cash']['GEL'];
    expect($cash['amount'])->toBe(950.0)->and($cash['received'])->toBe(0.0)->and($cash['spent'])->toBe(50.0);
    $page->set('cashDirection', 'outflow')->assertDontSee('Drawer internal fixture')->assertDontSee('Internal partner transfer')->assertSee('External transfer')
        ->call('selectOverviewCard', 'cash_outflow')->call('selectCashOutflowGroup', 'other')
        ->assertDontSee('Drawer internal fixture')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && (float) $rows->first()->amount === 20.0);
    expect($page->viewData('figures')['GEL']['cash_outflow'])->toBe(50.0)
        ->and($page->viewData('figures')['GEL']['expenses'])->toBe(0.0);
    $page->call('selectCashOutflowGroup', 'bank_deposit')->assertSee('Bank deposit');
});

test('current cash and its equation stop at application midnight for Israeli receipts and partner movement types', function () {
    // At this UTC instant Tbilisi is already September 15.
    $this->travelTo(Carbon::parse('2026-09-14 21:30:00', 'UTC'));
    foreach ([['2026-09-15 00:00:00', 100, 'cash', null], ['2026-09-15 23:59:59', 100, 'cash', null],
        ['2026-09-16 00:00:00', 999, 'cash', null], ['2026-09-15 12:00:00', 999, 'card', null],
        ['2026-09-15 12:00:00', 999, 'cash', '2026-09-15 12:00:00']] as [$date, $amount, $method, $deleted]) {
        DB::table('partner_patient_payments')->insert(['paid_at' => $date, 'amount' => $amount, 'currency' => 'GEL', 'payment_method' => $method, 'deleted_at' => $deleted]);
    }
    foreach (['clinic', 'israeli'] as $source) {
        foreach (['transfer', 'expense', 'owner_withdrawal', 'employee_advance', 'salary_cash'] as $type) {
            reconciliationPartner(['source' => $source, 'type' => $type, 'transacted_at' => '2026-09-16 00:00:00', 'amount' => 999]);
        }
        reconciliationPartner(['source' => $source, 'type' => 'currency_exchange', 'transacted_at' => '2026-09-16 00:00:00',
            'from_currency' => 'USD', 'from_amount' => 999, 'to_currency' => 'GEL', 'to_amount' => 2700, 'to_account' => 'cash']);
    }
    reconciliationPartner(['type' => 'salary_cash', 'to_account' => null, 'amount' => 20, 'transacted_at' => '2026-09-15 23:59:59']);
    reconciliationPartner(['type' => 'employee_advance', 'from_account' => null, 'to_account' => 'cash', 'amount' => 5, 'transacted_at' => '2026-09-15 00:00:00']);
    $liquidity = app(LiquidityReport::class)->current('israeli');
    $equation = $this->report->equation($liquidity['cash'], 'israeli');
    expect($liquidity['totals']['GEL']['cash'])->toBe(185.0)->and($liquidity['totals']['USD']['cash'])->toBe(0.0)
        ->and($equation['GEL']['received'])->toBe(205.0)->and($equation['GEL']['spent'])->toBe(20.0)
        ->and(app(LiquidityReport::class)->current('clinic')['totals']['GEL']['cash'])->toBe(1000.0);
    expect($this->report->movements('2026-09-15', '2026-09-15', 'israeli', 'inflow')->sum('amount'))->toEqual(205);
});

test('full filtered totals stay complete across pages and filters reset pagination in the rendered overview', function () {
    for ($i = 0; $i < 30; $i++) {
        reconciliationDrawer(['description' => 'Receipt '.$i]);
    }
    reconciliationDrawer(['currency' => 'USD', 'amount' => 7, 'transaction_date' => '2026-09-14 00:00:00']);
    reconciliationDrawer(['type' => 'expense', 'amount' => 5, 'description' => 'Cash expense']);
    $page = Livewire::test(Finance::class)->call('selectOverviewCard', 'cash')
        ->assertSee('Page 1')->assertSee('of 32')->assertSee('Totals above include all pages.')
        ->assertSee('300.00')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 25 && $rows->hasMorePages())
        ->assertViewHas('cashMovementTotals', fn ($rows) => (float) $rows->firstWhere('currency', 'GEL')->inflow === 300.0
            && (float) $rows->firstWhere('currency', 'USD')->inflow === 7.0 && $rows->sum('row_count') === 32);
    $totals = $page->viewData('cashMovementTotals')->toJson();
    $page->call('nextPage', 'overviewPage')->assertSee('Page 2')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 7)
        ->assertViewHas('cashMovementTotals', fn ($rows) => $rows->toJson() === $totals)
        ->set('cashDirection', 'outflow')->assertSee('Page 1')->assertSee('of 1')->assertSee('Cash expense')
        ->assertViewHas('cashMovementTotals', fn ($rows) => (float) $rows->first()->outflow === 5.0 && (float) $rows->first()->inflow === 0.0)
        ->set('cashDirection', 'all')->set('overviewCurrency', 'USD')->assertSee('of 1')
        ->assertViewHas('cashMovementTotals', fn ($rows) => $rows->count() === 1 && $rows->first()->currency === 'USD');
});
