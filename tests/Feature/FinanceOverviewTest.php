<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Bank;
use App\Filament\Pages\Finance;
use App\Filament\Pages\FinanceOpeningBalances;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\CashboxDay;
use App\Models\Doctor;
use App\Models\ExpenseCategory;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use App\Services\Bank\BankIngestionService;
use App\Services\Bank\ProfitLossReport;
use App\Services\BogBusinessApiService;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\BankBalances;
use App\Services\Finance\CashOutflowReport;
use App\Services\Finance\LiquidityReport;
use App\Services\Finance\OpeningBalanceService;
use App\Services\FinanceManager;
use App\Services\FinanceUsdUsageService;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-09-11 12:00:00');
    config(['services.bog.account_number' => 'GE00OVERVIEW', 'services.bog.account_currency' => 'GEL']);
    $this->mock(BogBusinessApiService::class)->shouldReceive('currentBalance')->andReturn('9000.00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
});

function overviewOpening(string $source, float $amount, string $currency = 'GEL', string $date = '2026-09-11'): FinanceOpeningBalance
{
    return app(OpeningBalanceService::class)->create(['source' => $source, 'bank' => 'BOG', 'account_identifier' => 'GE00OVERVIEW',
        'amount' => $amount, 'currency' => $currency, 'effective_date' => $date, 'note' => 'Test opening'], auth()->user());
}

function overviewBank(array $overrides = []): BankTransaction
{
    $data = new BankTransactionData(array_replace(['transaction_date' => '2026-09-11 10:00:00', 'account_identifier' => 'GE00OVERVIEW',
        'amount' => '100.00', 'currency' => 'GEL', 'direction' => 'inflow', 'operation_type' => 'PMD', 'operation_id' => (string) Str::uuid()], $overrides));
    app(BankIngestionService::class)->ingest([$data], 'import');

    return BankTransaction::where('deduplication_key', $data->deduplicationKey())->sole();
}

function overviewPayment(float $cash = 0, float $card = 0): Payment
{
    $patient = Patient::create(['first_name' => 'Overview', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Overview', 'last_name' => 'Doctor', 'is_active' => true]);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'total_price' => 10000, 'currency' => 'GEL']);
    $splits = [];
    if ($cash) {
        $splits[] = ['payment_method' => 'cash', 'amount' => $cash];
    }
    if ($card) {
        $splits[] = ['payment_method' => 'card', 'amount' => $card];
    }

    return Payment::createWithSplits(['visit_id' => $visit->id, 'amount' => $cash + $card, 'currency' => 'GEL', 'payment_date' => today(), 'comment' => 'Canonical patient receipt'], $splits);
}

test('overview has six primary cards and no movement summaries or detail queries until clicked', function () {
    overviewPayment(100, 500);
    DB::enableQueryLog();
    $page = Livewire::test(Finance::class)->call('refreshBogBalance')->assertSuccessful()->assertSet('period', '7_days')->assertSet('dateFrom', '2026-09-05')
        ->assertViewHas('overviewDetails', null)->assertViewHas('expenseGroups', fn ($groups) => $groups->isEmpty());
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect(substr_count($page->html(), 'data-finance-card='))->toBe(6)
        ->and($page->html())->not->toContain("selectOverviewCard('inflow')", "selectOverviewCard('outflow')", 'Total Available', 'Movements include')
        ->and($page->html())->not->toContain('overview-entry-')
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'ledger') && str_contains($sql, 'limit')))->toHaveCount(0);
    $page->call('selectOverviewCard', 'revenue')->assertSee('Overview Patient')->assertSee('Canonical patient receipt')
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 2 && (float) $rows->sum('amount') === 600.0)
        ->call('selectOverviewCard', 'revenue')->assertSet('overviewCard', '')->assertViewHas('overviewDetails', null);
});

test('liquidity equals current Cashier cash plus Bank and never includes card receivables', function () {
    overviewOpening('cash', 1000);
    overviewOpening('bank', 5000);
    overviewPayment(200, 1000);
    $this->mock(BogBusinessApiService::class)->shouldReceive('currentBalance')->andReturn('5000.00');
    $liquidity = app(LiquidityReport::class)->current('all', ['bank' => 'BOG', 'account_identifier' => 'GE00OVERVIEW', 'currency' => 'GEL', 'reported_balance' => '5000.00', 'fetched_at' => now()->toDateTimeString()]);
    expect($liquidity['totals']['GEL'])->toBe(['cash' => 1200.0, 'bank' => 5000.0, 'available' => 6200.0])
        ->and(app(CashboxManager::class)->today()->summary()['expected'])->toBe(1200.0);
    Livewire::test(Finance::class)->call('refreshBogBalance')->call('selectOverviewCard', 'bank')->assertSee('5,000.00')->assertSee('GE00OVERVIEW')
        ->set('dateFrom', '2025-01-01')->set('dateUntil', '2025-01-07')->set('moneySource', 'bank')
        ->assertViewHas('figures', fn ($figures) => $figures['GEL']['available'] === 6200.0 && $figures['GEL']['revenue'] === 0.0)
        ->call('selectOverviewCard', 'cash')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && (float) $rows->sole()->amount === 200.0);
});

test('patient card settlement and fee produce one revenue and the correct profit', function () {
    overviewPayment(0, 1000);
    overviewBank(['operation_type' => 'TRN', 'description' => 'POS settlement', 'amount' => '985.00']);
    overviewBank(['operation_type' => 'COM', 'direction' => 'outflow', 'amount' => '15.00']);
    $ledger = app(AccountingLedger::class);
    $total = $ledger->pnlTotals('2026-09-11', '2026-09-11')->sole();
    expect((float) $total->revenue)->toBe(1000.0)->and((float) $total->expenses)->toBe(15.0)->and((float) $total->profit)->toBe(985.0);
    $movements = $ledger->movementTotals('2026-09-11', '2026-09-11')->sole();
    expect((float) $movements->inflow)->toBe(1985.0)->and((float) $movements->outflow)->toBe(15.0);
    Livewire::test(Finance::class)->call('refreshBogBalance')->call('selectOverviewCard', 'profit')->assertSee('985.00')->assertViewHas('overviewDetails', null);
});

test('cash and bank materials share one category with source-specific detail and exact totals', function () {
    overviewOpening('cash', 1000);
    $category = ExpenseCategory::where('reporting_code', 'materials')->sole();
    app(FinanceManager::class)->create(['type' => 'expense', 'transaction_date' => now(), 'category' => 'materials', 'description' => 'Cash supplier', 'amount' => 120, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    $bank = overviewBank(['direction' => 'outflow', 'amount' => '80.00', 'description' => 'Bank supplier']);
    $bank->update(['bank_category_id' => BankCategory::where('code', 'supplier')->value('id'), 'classification_source' => 'manual']);
    $groups = app(AccountingLedger::class)->expenseGroups('2026-09-11', '2026-09-11');
    expect($groups)->toHaveCount(1)->and($groups->sole()->category_key)->toBe('expense:'.$category->id)->and((float) $groups->sole()->amount)->toBe(200.0);
    $page = Livewire::test(Finance::class)->call('refreshBogBalance')->call('selectOverviewCard', 'expenses')->assertViewHas('overviewDetails', null)
        ->call('selectExpenseCategory', 'review')->call('selectExpenseSubcategory', (string) $category->id)->assertSee('Cash supplier')->assertSee('Bank supplier')
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 2 && (float) $rows->sum('amount') === 200.0);
    $page->set('moneySource', 'bank')->assertSet('overviewCategory', '')->call('selectExpenseCategory', 'review')->call('selectExpenseSubcategory', (string) $category->id)
        ->assertDontSee('Cash supplier')->assertSee('Bank supplier')->assertViewHas('figures', fn ($f) => $f['GEL']['expenses'] === 80.0);
    $category->update(['active' => false, 'name' => 'Historical materials']);
    expect(app(AccountingLedger::class)->expenseGroups('2026-09-11', '2026-09-11')->sole()->category_name)->toBe('Historical materials');
    expect(fn () => $category->delete())->toThrow(ValidationException::class);
});

test('cash to Bank deposit changes liquidity and movement only', function () {
    overviewOpening('cash', 5000);
    overviewOpening('bank', 1000);
    $day = app(CashboxManager::class)->today();
    $day->transactions()->create(['type' => 'cash_withdrawal', 'amount' => 5000, 'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now(), 'description' => 'Cash to Bank deposit']);
    overviewBank(['operation_type' => 'PBS', 'amount' => '5000.00']);
    // Cash movements and old openings do not establish a current API balance.
    expect(app(LiquidityReport::class)->current()['totals']['GEL'])->toBe(['cash' => 0.0, 'bank' => null, 'available' => null]);
    $ledger = app(AccountingLedger::class);
    expect($ledger->pnl('2026-09-11', '2026-09-11')->count())->toBe(0);
    $movement = $ledger->movementTotals('2026-09-11', '2026-09-11')->sole();
    expect((float) $movement->inflow)->toBe(5000.0)->and((float) $movement->outflow)->toBe(5000.0);
});

test('internal transfers stay out of P&L and Bank and Cash current cards drill into their own movements', function () {
    overviewPayment(100, 100);
    $bank = overviewBank(['description' => 'Own account transfer']);
    $bank->update(['bank_category_id' => BankCategory::where('code', 'internal_transfer')->value('id')]);
    Livewire::test(Finance::class)->call('refreshBogBalance')->call('selectOverviewCard', 'cash')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && $rows->sole()->payment_method === 'cash')
        ->call('selectOverviewCard', 'bank')->assertDontSee('Own account transfer')->assertSee('Bank transaction history')->assertViewHas('overviewDetails', null);
    expect(app(AccountingLedger::class)->pnl('2026-09-11', '2026-09-11', 'bank')->count())->toBe(0);
});

test('opening balances are not revenue expense or movement and future openings do not apply early', function () {
    overviewOpening('cash', 1000, 'GEL', '2026-10-01');
    overviewOpening('bank', 2000, 'GEL', '2026-10-01');
    expect(app(LiquidityReport::class)->current()['totals']['GEL']['cash'])->toBe(0.0)->and(app(BankBalances::class)->current())->toHaveCount(0);
    $this->travelTo('2026-10-01 09:00:00');
    expect(app(LiquidityReport::class)->current()['totals']['GEL'])->toBe(['cash' => 1000.0, 'bank' => null, 'available' => null]);
    expect(app(AccountingLedger::class)->pnl(null, null)->count())->toBe(0)->and(app(AccountingLedger::class)->movements(null, null)->count())->toBe(0);
});

test('Cashier cutover starts from explicit balances without closing or rewriting legacy test days', function () {
    $old = CashboxDay::create(['date' => '2026-09-01', 'opening_balance' => 999, 'opening_balance_usd' => 77, 'status' => 'open']);
    overviewOpening('cash', 1000, 'GEL', '2026-10-01');
    overviewOpening('cash', 200, 'USD', '2026-10-01');
    $this->travelTo('2026-10-01 09:00:00');
    $day = app(CashboxManager::class)->oldestUnclosedDay();
    expect($day->date->toDateString())->toBe('2026-10-01')->and($day->summary()['expectedByCurrency'])->toBe(['GEL' => 1000.0, 'USD' => 200.0])
        ->and($old->fresh()->status)->toBe('open')->and($old->fresh()->opening_balance)->toBe('999.00');
    app(CashboxManager::class)->close($day, 1000, 1000, actualUsd: 200, carryUsd: 200);
    $this->travelTo('2026-10-02 09:00:00');
    expect(app(CashboxManager::class)->today()->summary()['expected'])->toBe(1000.0);
});

test('cash opening cannot overwrite an existing posted day or a previous date', function () {
    overviewPayment(100);
    expect(fn () => overviewOpening('cash', 500))->toThrow(ValidationException::class)
        ->and(fn () => overviewOpening('cash', 500, 'GEL', '2026-09-01'))->toThrow(ValidationException::class)
        ->and(FinanceOpeningBalance::count())->toBe(0);
});

test('a scheduled cash opening applies when the first operating day is after its date', function () {
    CashboxDay::create(['date' => '2026-09-01', 'opening_balance' => 999, 'status' => 'open']);
    overviewOpening('cash', 700, 'GEL', '2026-10-01');
    $this->travelTo('2026-10-03 09:00:00');
    expect(app(LiquidityReport::class)->current()['totals']['GEL']['cash'])->toBe(700.0)
        ->and(app(CashboxManager::class)->today()->summary()['expected'])->toBe(700.0)
        ->and(app(LiquidityReport::class)->current()['totals']['GEL']['cash'])->toBe(700.0);
});

test('duplicate opening dates are rejected and zero openings remain explicit', function () {
    overviewOpening('cash', 0);
    expect(fn () => overviewOpening('cash', 700))->toThrow(ValidationException::class)
        ->and(FinanceOpeningBalance::count())->toBe(1)
        ->and(app(CashboxManager::class)->today()->summary()['expected'])->toBe(0.0);
});

test('cash cutover funds expenses without inheriting historical receipts or withdrawals', function () {
    overviewPayment(900);
    PartnerFinanceTransaction::create(['source' => 'clinic', 'type' => 'owner_withdrawal', 'transacted_at' => now(),
        'from_account' => 'cash', 'amount' => 300, 'currency' => 'GEL', 'notes' => 'Legacy withdrawal']);
    overviewOpening('cash', 700, 'GEL', '2026-10-01');
    $this->travelTo('2026-10-01 09:00:00');
    expect(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(700.0);
    app(FinanceManager::class)->create(['type' => 'expense', 'transaction_date' => now(), 'category' => 'materials',
        'description' => 'Opening-funded supplies', 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    expect(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(600.0)
        ->and(app(LiquidityReport::class)->current()['totals']['GEL']['cash'])->toBe(600.0)
        ->and((float) app(AccountingLedger::class)->pnlTotals('2026-10-01', '2026-10-01')->sole()->expenses)->toBe(100.0);
});

test('newer statement replaces its opening anchor and balances stay separate by account and currency', function () {
    overviewOpening('bank', 1000, 'GEL', '2026-09-01');
    overviewOpening('bank', 300, 'USD', '2026-09-01');
    overviewBank(['amount' => '200.00', 'transaction_date' => '2026-09-09 10:00:00']);
    DB::table('bank_import_batches')->insert(['bank' => 'BOG', 'account_identifier' => 'GE00OVERVIEW', 'currency' => 'GEL', 'accounts' => '["GE00OVERVIEW"]',
        'currencies' => '["GEL"]', 'source_file' => 'balance.xlsx', 'file_hash' => str_repeat('a', 64), 'imported_at' => now(), 'reported_balance' => 1200,
        'balance_as_of' => '2026-09-10 23:59:59', 'balance_origin' => 'closing_balance']);
    overviewBank(['amount' => '50.00', 'direction' => 'outflow']);
    $totals = app(BankBalances::class)->current()->keyBy('currency');
    expect((float) $totals['GEL']->reported_balance)->toBe(1200.0)->and((float) $totals['USD']->reported_balance)->toBe(300.0)
        ->and($totals['GEL']->balance_origin)->toBe('closing_balance');
    overviewBank(['account_identifier' => 'GE00UNKNOWN', 'amount' => '500.00']);
    expect(app(LiquidityReport::class)->current()['totals']['GEL']['bank'])->toBeNull()
        ->and(app(LiquidityReport::class)->current()['totals']['GEL']['available'])->toBeNull();
});

test('Legacy flags exclude Bank P&L but preserve balances movements and deduplication', function () {
    overviewOpening('bank', 500);
    $fee = overviewBank(['direction' => 'outflow', 'operation_type' => 'COM', 'amount' => '15.00']);
    $raw = $fee->only(['amount', 'direction', 'operation_type', 'deduplication_key']);
    Livewire::test(Bank::class)->call('showTransaction', $fee->id)->call('markLegacy', $fee->id, true)->assertHasNoErrors();
    expect($fee->fresh()->is_legacy)->toBeTrue();
    expect(app(ProfitLossReport::class)->totals(null, null, 'bank'))->toHaveCount(0)
        ->and(app(BankBalances::class)->current()->sole()->reported_balance)->toEqual(500)
        ->and((float) app(AccountingLedger::class)->movementTotals(null, null, 'bank')->sole()->outflow)->toBe(15.0)
        ->and($fee->fresh()->only(array_keys($raw)))->toBe($raw);
});

test('common dates source and currency filters match paginated drill-down sums', function () {
    overviewPayment(300, 100);
    $current = overviewBank(['amount' => '50.00']);
    $current->update(['bank_category_id' => BankCategory::where('code', 'business_income')->value('id')]);
    $old = overviewBank(['amount' => '900.00', 'transaction_date' => '2026-08-01 12:00:00']);
    $old->update(['bank_category_id' => $current->bank_category_id]);
    FinanceTransaction::create(['type' => 'income', 'transaction_date' => '2026-08-01', 'category' => 'other_income', 'amount' => 900, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    $page = Livewire::test(Finance::class)->call('refreshBogBalance')->set('overviewCurrency', 'GEL')->call('selectOverviewCard', 'revenue')
        ->assertViewHas('figures', fn ($f) => $f['GEL']['revenue'] === 400.0)
        ->assertViewHas('overviewDetails', fn ($rows) => (float) $rows->sum('amount') === 400.0)
        ->set('moneySource', 'bank')->assertViewHas('figures', fn ($f) => $f['GEL']['revenue'] === 0.0)
        ->set('moneySource', 'cash')->assertViewHas('figures', fn ($f) => $f['GEL']['revenue'] === 400.0)
        ->set('moneySource', 'all')->set('dateFrom', '2026-08-01')->set('dateUntil', '2026-08-01')->assertSet('period', 'custom')
        ->assertViewHas('overviewDetails', fn ($rows) => (float) $rows->sum('amount') === 900.0)
        ->set('overviewCurrency', 'USD')->assertViewHas('overviewDetails', fn ($rows) => $rows->isEmpty());
});

test('opening management is Owner only and records no operational income', function () {
    Livewire::test(FinanceOpeningBalances::class)->call('mountAction', 'create')->setActionData(['source' => 'bank', 'bank' => 'BOG', 'account_identifier' => 'GE00OWNER', 'currency' => 'GEL', 'amount' => 5000, 'effective_date' => '2026-10-01', 'note' => 'Future cutover'])->callMountedAction()->assertHasNoActionErrors();
    expect(FinanceOpeningBalance::count())->toBe(1)->and(DB::table('finance_transactions')->count())->toBe(0);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    Livewire::test(Finance::class)->assertForbidden();
    Livewire::test(FinanceOpeningBalances::class)->assertForbidden();
});

test('summary and category drill-down use fixed SQL aggregates with no per-record queries', function () {
    for ($i = 0; $i < 30; $i++) {
        overviewBank(['operation_type' => 'COM', 'direction' => 'outflow', 'amount' => '1.00']);
    }
    $ledger = app(AccountingLedger::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $totals = $ledger->pnlTotals('2026-09-11', '2026-09-11');
    $groups = $ledger->expenseGroups('2026-09-11', '2026-09-11');
    $rows = $ledger->pnl('2026-09-11', '2026-09-11')->where('category_key', $groups->sole()->category_key)->orderBy('entry_key')->simplePaginate(25);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($count)->toBe(3)->and((float) $totals->sole()->expenses)->toBe(30.0)->and((float) $groups->sole()->amount)->toBe(30.0)
        ->and($rows)->toHaveCount(25)->and($rows->hasMorePages())->toBeTrue();
});

test('revenue details distinguish Clinic cash card and Israeli payments without Bank credits', function () {
    overviewPayment(200, 500);
    $patient = Patient::create(['first_name' => 'Israeli', 'last_name' => 'Receipt', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $patient->partnerPayments()->create(['amount' => 150, 'currency' => 'GEL', 'payment_method' => 'cash', 'paid_at' => today()]);
    overviewBank(['operation_type' => 'TRN', 'description' => 'POS settlement hidden from revenue', 'amount' => '490.00']);
    Livewire::test(Finance::class)->call('refreshBogBalance')->call('selectOverviewCard', 'revenue')->assertSee('Clinic')->assertSee('Israeli')->assertSee('850.00')
        ->assertDontSee('POS settlement hidden from revenue')
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 3 && $rows->where('business_source', 'clinic')->count() === 2 && $rows->where('business_source', 'israeli')->count() === 1);
});

test('legacy Cashier expenses and linked Finance expenses count once beside Bank rent', function () {
    overviewOpening('cash', 1000);
    $day = app(CashboxManager::class)->today();
    $day->transactions()->create(['type' => 'expense', 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now(), 'expense_category' => 'rent']);
    app(FinanceManager::class)->create(['type' => 'expense', 'amount' => 150, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => now(), 'category' => 'rent']);
    $bank = overviewBank(['amount' => '200.00', 'direction' => 'outflow']);
    $bank->update(['bank_category_id' => BankCategory::where('code', 'rent')->value('id')]);
    $groups = app(AccountingLedger::class)->expenseGroups('2026-09-11', '2026-09-11');
    expect($groups)->toHaveCount(1)->and((float) $groups->sole()->amount)->toBe(450.0)->and((int) $groups->sole()->entries_count)->toBe(3)
        ->and(app(LiquidityReport::class)->current()['totals']['GEL']['cash'])->toBe(750.0);
});

test('current cash survives closing with carry and no new day and stays independent of performance filters', function () {
    $manager = app(CashboxManager::class);
    overviewOpening('cash', 4000);
    overviewOpening('cash', 300, 'USD');
    overviewOpening('bank', 9000);
    $day = $manager->today();
    $manager->close($day, 4000, 4000, actualUsd: 300, carryUsd: 300);
    $this->travelTo('2026-09-12 10:00:00');
    expect(CashboxDay::count())->toBe(1);
    $assertBalances = fn ($figures) => $figures['GEL']['cash'] === 4000.0 && $figures['USD']['cash'] === 300.0 && $figures['GEL']['bank'] === 9000.0;
    $page = Livewire::test(Finance::class)->call('refreshBogBalance')->assertSet('businessSource', 'all')->assertViewHas('figures', $assertBalances);
    foreach (['clinic', 'israeli', 'all'] as $source) {
        $page->set('businessSource', $source)->set('dateFrom', '2020-01-01')->set('dateUntil', '2020-01-02')->assertViewHas('figures', fn ($f) => $f['GEL']['cash'] === ($source === 'israeli' ? 0.0 : 4000.0) && $f['USD']['cash'] === ($source === 'israeli' ? 0.0 : 300.0) && $f['GEL']['bank'] === 9000.0);
    }
    expect(CashboxDay::count())->toBe(1); // Reporting must not open today.
    $manager->today();
    expect($manager->physicalCashBalances())->toBe(['GEL' => 4000.0, 'USD' => 300.0]);
    overviewPayment(200, 700);
    app(FinanceManager::class)->create(['type' => 'expense', 'amount' => 75, 'currency' => 'GEL', 'payment_method' => 'cash',
        'cash_source' => 'current_cashier', 'transaction_date' => now(), 'category' => 'rent']);
    $page->call('$refresh')->assertViewHas('figures', fn ($f) => $f['GEL']['cash'] === 4125.0 && $f['USD']['cash'] === 300.0)
        ->call('selectOverviewCard', 'cash')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 2 && $rows->every(fn ($row) => $row->payment_method === 'cash'));
});

test('cash includes older retained ledger funds even when later cashier days are empty', function () {
    $manager = app(CashboxManager::class);
    overviewPayment(4000, 800);
    $manager->today()->transactions()->create(['type' => 'other_income', 'amount' => 300, 'currency' => 'USD', 'payment_method' => 'cash', 'transaction_date' => now()]);
    $manager->close($manager->today(), 4000, 0, actualUsd: 300, carryUsd: 0);
    $this->travelTo('2026-09-13 10:00:00');
    $manager->dayFor('2026-09-12');
    $manager->today();
    Livewire::test(Finance::class)->call('refreshBogBalance')->assertViewHas('figures', fn ($f) => $f['GEL']['cash'] === 4000.0 && $f['USD']['cash'] === 300.0);
    expect($manager->physicalCashBalances())->toBe(['GEL' => 4000.0, 'USD' => 300.0]);
});

test('legacy initial cash is counted once and internal handovers do not remove physical cash', function () {
    $manager = app(CashboxManager::class);
    $day = CashboxDay::create(['date' => today(), 'opening_balance' => 4000, 'opening_balance_usd' => 300, 'status' => 'open']);
    $day->transactions()->create(['type' => 'cash_withdrawal', 'amount' => 4000, 'currency' => 'GEL', 'payment_method' => 'cash',
        'description' => 'დღის დახურვისას სალაროდან ამოღებული ქეში', 'transaction_date' => now()]);
    $day->transactions()->create(['type' => 'cash_withdrawal', 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now()]);
    $day->transactions()->create(['type' => 'other_income', 'amount' => 900, 'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now()->addDays(2)]);
    expect($manager->physicalCashBalances())->toBe(['GEL' => 3900.0, 'USD' => 300.0]);
    Livewire::test(Finance::class)->call('refreshBogBalance')->call('selectOverviewCard', 'cash')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && (float) $rows->sole()->amount === 100.0);
});

test('business source filters canonical revenue expenses profit and every drill down without changing liquidity', function () {
    overviewPayment(200, 500);
    $patient = Patient::create(['first_name' => 'Israeli', 'last_name' => 'Filtered', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $patient->partnerPayments()->create(['amount' => 150, 'currency' => 'GEL', 'payment_method' => 'cash', 'paid_at' => today()]);
    overviewOpening('bank', 9000);
    overviewBank(['operation_type' => 'TRN', 'description' => 'POS settlement no double revenue', 'amount' => '490.00']);
    $rent = ExpenseCategory::where('reporting_code', 'rent')->sole();
    $child = $rent->subcategories()->create(['name' => 'Monthly', 'active' => true]);
    app(FinanceManager::class)->create(['type' => 'expense', 'amount' => 40, 'currency' => 'GEL', 'payment_method' => 'cash',
        'cash_source' => 'current_cashier', 'transaction_date' => now(), 'category' => 'rent', 'expense_category_id' => $rent->id, 'expense_subcategory_id' => $child->id, 'description' => 'Clinic rent']);
    PartnerFinanceTransaction::create(['source' => 'israeli', 'type' => 'expense', 'amount' => 30, 'currency' => 'GEL', 'from_account' => 'cash',
        'transacted_at' => now(), 'category' => 'rent', 'expense_category_id' => $rent->id, 'expense_subcategory_id' => $child->id, 'notes' => 'Israeli rent']);
    FinanceTransaction::create(['type' => 'expense', 'amount' => 20, 'currency' => 'GEL', 'payment_method' => 'bank_transfer', 'transaction_date' => now(),
        'category' => 'rent', 'expense_category_id' => $rent->id, 'expense_subcategory_id' => $child->id, 'description' => 'General unassigned rent']);
    $bank = overviewBank(['direction' => 'outflow', 'amount' => '10.00', 'description' => 'Shared Bank rent']);
    $bank->update(['expense_category_id' => $rent->id, 'expense_subcategory_id' => $child->id, 'bank_category_id' => BankCategory::where('code', 'rent')->value('id')]);
    $page = Livewire::test(Finance::class)->call('refreshBogBalance')->assertSeeHtml('wire:model.live="businessSource"');
    foreach (['all' => [850, 100, 3, 4], 'clinic' => [700, 40, 2, 1], 'israeli' => [150, 30, 1, 1]] as $source => [$revenue, $expenses, $receiptCount, $expenseCount]) {
        $page->set('businessSource', $source)->assertViewHas('figures', fn ($f) => $f['GEL']['revenue'] === (float) $revenue
            && $f['GEL']['expenses'] === (float) $expenses && $f['GEL']['profit'] === (float) ($revenue - $expenses)
            && $f['GEL']['cash'] === (float) ['all' => 280, 'clinic' => 160, 'israeli' => 120][$source] && $f['GEL']['bank'] === 9000.0)
            ->call('selectOverviewCard', 'revenue')->assertDontSee('POS settlement no double revenue')
            ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === $receiptCount && (float) $rows->sum('amount') === (float) $revenue)
            ->call('selectOverviewCard', 'expenses')->assertViewHas('expenseGroups', fn ($rows) => (float) $rows->sum('amount') === (float) $expenses)
            ->call('selectExpenseCategory', 'review')->assertViewHas('overviewDetails', null)
            ->assertViewHas('expenseSubgroups', fn ($rows) => (float) $rows->sum('amount') === (float) $expenses)
            ->call('selectExpenseSubcategory', (string) $rent->id)
            ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === $expenseCount && (float) $rows->sum('amount') === (float) $expenses)
            ->call('selectOverviewCard', 'profit')->assertViewHas('overviewDetails', null);
    }
    $page->set('dateFrom', '2020-01-01')->set('dateUntil', '2020-01-02')->assertViewHas('figures', fn ($f) => $f['GEL']['revenue'] === 0.0 && $f['GEL']['expenses'] === 0.0
        && $f['GEL']['cash'] === (float) ['all' => 280, 'clinic' => 160, 'israeli' => 120][$source] && $f['GEL']['bank'] === 9000.0);
});

test('mixed salary allocations and reversals use their exact source shares once', function () {
    // Existing immutable postings already store both allocations; mirror rows must not count twice.
    $id = DB::table('finance_transactions')->insertGetId(['type' => 'expense', 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash',
        'cash_source' => 'current_cashier', 'funding_source' => 'mixed', 'clinic_cash_gel' => 60, 'israeli_cash_gel' => 40,
        'category' => 'salary', 'transaction_date' => now()]);
    DB::table('partner_finance_transactions')->insert(['source' => 'israeli', 'type' => 'expense', 'amount' => 40, 'currency' => 'GEL', 'from_account' => 'cash',
        'finance_transaction_id' => $id, 'category' => 'salary', 'transacted_at' => now()]);
    $ledger = app(AccountingLedger::class);
    foreach (['all' => 100, 'clinic' => 60, 'israeli' => 40] as $source => $expense) {
        expect((float) $ledger->pnlTotals('2026-09-11', '2026-09-11', businessSource: $source)->sole()->expenses)->toBe((float) $expense);
    }
    DB::table('finance_transactions')->insert(['type' => 'income', 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash',
        'cash_source' => 'current_cashier', 'funding_source' => 'mixed', 'clinic_cash_gel' => 60, 'israeli_cash_gel' => 40,
        'category' => 'salary', 'transaction_date' => now(), 'reversal_of_finance_transaction_id' => $id]);
    foreach (['all', 'clinic', 'israeli'] as $source) {
        $total = $ledger->pnlTotals('2026-09-11', '2026-09-11', businessSource: $source)->sole();
        expect((float) $total->revenue)->toBe(0.0)->and((float) $total->expenses)->toBe(0.0)->and((float) $total->profit)->toBe(0.0);
    }
});

test('source filtering and physical cash aggregation use fixed query counts', function () {
    overviewPayment(100, 500);
    $cash = app(CashboxManager::class);
    $ledger = app(AccountingLedger::class);
    foreach ([1, 40] as $count) {
        for ($i = 0; $i < $count; $i++) {
            $cash->today()->transactions()->create(['type' => 'other_income', 'amount' => 1, 'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now()]);
        }
        DB::enableQueryLog();
        DB::flushQueryLog();
        $cash->physicalCashSnapshot();
        $ledger->pnlTotals('2026-09-11', '2026-09-11', businessSource: 'clinic');
        expect(DB::getQueryLog())->toHaveCount(4);
        DB::disableQueryLog();
    }
});

test('current cash combines Clinic and Israeli cash in each currency by selected source independently of period', function () {
    overviewPayment(4000, 700);
    $patient = Patient::create(['first_name' => 'Combined', 'last_name' => 'Cash', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    foreach (['GEL' => 1500, 'USD' => 2000] as $currency => $amount) {
        $patient->partnerPayments()->create(['amount' => $amount, 'currency' => $currency, 'payment_method' => 'cash', 'paid_at' => today()]);
    }
    $patient->partnerPayments()->create(['amount' => 800, 'currency' => 'USD', 'payment_method' => 'card', 'paid_at' => today()]);
    overviewOpening('bank', 9000);
    $page = Livewire::test(Finance::class)->call('refreshBogBalance');
    foreach (['all', 'clinic', 'israeli'] as $source) {
        $page->set('businessSource', $source)->set('dateFrom', '2020-01-01')->set('dateUntil', '2020-01-02')
            ->assertViewHas('figures', fn ($f) => $f['GEL']['cash'] === (float) ['all' => 5500, 'clinic' => 4000, 'israeli' => 1500][$source] && $f['USD']['cash'] === ($source === 'clinic' ? 0.0 : 2000.0) && $f['GEL']['bank'] === 9000.0 && ($source === 'all' || $f['GEL']['available'] === null))
            ->assertViewHas('liquidity', fn ($l) => $l['cash']['GEL']['clinic'] === 4000.0 && $l['cash']['GEL']['israeli'] === 1500.0);
    }
    app(CashboxManager::class)->today()->transactions()->create(['type' => 'other_income', 'amount' => 50, 'currency' => 'USD', 'payment_method' => 'cash', 'transaction_date' => now()]);
    $service = app(FinanceUsdUsageService::class);
    foreach (['direct_gel' => 100, 'direct_usd' => 200] as $mode => $amount) {
        $service->recordIsraeliOperation(['transacted_at' => now(), 'operation_type' => 'materials', 'payment_mode' => $mode, 'actual_amount' => $amount]);
    }
    $page->set('businessSource', 'all')->assertViewHas('figures', fn ($f) => $f['GEL']['cash'] === 5400.0 && $f['USD']['cash'] === 1850.0)
        ->call('selectOverviewCard', 'cash')->assertSee('1,400.00')->assertSee('1,800.00');
    $page->set('businessSource', 'clinic')->set('dateUntil', '2026-09-11')
        ->assertViewHas('figures', fn ($f) => $f['GEL']['cash'] === 4000.0 && $f['USD']['cash'] === 50.0 && $f['GEL']['bank'] === 9000.0);
    $page->set('businessSource', 'israeli')->set('dateFrom', '2026-09-11')
        ->assertViewHas('figures', fn ($f) => $f['GEL']['cash'] === 1400.0 && $f['USD']['cash'] === 1800.0 && $f['GEL']['bank'] === 9000.0)
        ->assertViewHas('overviewDetails', null)
        ->assertViewHas('liquidity', fn ($l) => $l['cash']['GEL']['amount'] === 1400.0 && $l['cash']['USD']['amount'] === 1800.0);
});

test('cash outflow separates spending deposits and withdrawals from profit with lazy grouped rows', function () {
    overviewOpening('cash', 10000);
    overviewOpening('bank', 9000);
    overviewPayment(0, 900);
    app(FinanceManager::class)->create(['type' => 'expense', 'amount' => 500, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => now(), 'category' => 'materials', 'description' => 'Cash materials 500']);
    PartnerFinanceTransaction::create(['source' => 'clinic', 'type' => 'owner_withdrawal', 'from_account' => 'cash', 'amount' => 2000, 'currency' => 'GEL', 'transacted_at' => now(), 'notes' => 'Owner cash 2000']);
    app(FinanceUsdUsageService::class)->transfer(['source' => 'clinic', 'amount' => 5000, 'currency' => 'GEL', 'category' => 'bank_deposit', 'transacted_at' => now(), 'notes' => 'Deposit cash 5000']);
    overviewBank(['direction' => 'outflow', 'amount' => '77.00', 'description' => 'Bank only expense'])
        ->update(['bank_category_id' => BankCategory::where('code', 'supplier')->value('id')]);
    $page = Livewire::test(Finance::class)->call('refreshBogBalance')->assertViewHas('figures', fn ($f) => $f['GEL']['cash_outflow'] === 7500.0 && $f['GEL']['expenses'] === 577.0 && $f['GEL']['profit'] === 323.0 && $f['GEL']['cash'] === 2500.0)
        ->call('selectOverviewCard', 'cash_outflow')->assertViewHas('overviewDetails', null)
        ->assertViewHas('outflowGroups', fn ($g) => $g->count() === 3 && (float) $g->sum('amount') === 7500.0)
        ->call('selectCashOutflowGroup', 'expenses')->assertSee('Cash materials 500')->assertDontSee('Bank only expense')
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && (float) $rows->sum('amount') === 500.0)
        ->call('selectCashOutflowGroup', 'bank_deposit')->assertSee('Deposit cash 5000')->assertDontSee('Owner cash 2000')
        ->call('selectCashOutflowGroup', 'owner_withdrawal')->assertSee('Owner cash 2000');
    $page->call('selectOverviewCard', 'cash')->assertViewHas('liquidity', fn ($l) => $l['cash']['GEL']['opening'] === 10000.0 && $l['cash']['GEL']['received'] === 0.0 && $l['cash']['GEL']['spent'] === 7500.0 && $l['cash']['GEL']['amount'] === 2500.0);
    $page->set('dateFrom', '2020-01-01')->set('dateUntil', '2020-01-02')->assertViewHas('figures', fn ($f) => $f['GEL']['cash_outflow'] === 0.0 && $f['GEL']['cash'] === 2500.0 && $f['GEL']['bank'] === 9000.0);
});

test('Israeli cash legs and currency exchanges stay separate by currency and source and reconcile current cash', function () {
    overviewOpening('cash', 100, 'USD');
    $patient = Patient::create(['first_name' => 'Outflow', 'last_name' => 'Israeli', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    foreach (['GEL' => 1500, 'USD' => 2000] as $currency => $amount) {
        $patient->partnerPayments()->create(['amount' => $amount, 'currency' => $currency, 'payment_method' => 'cash', 'paid_at' => today()]);
    }
    app(FinanceManager::class)->create(['type' => 'expense', 'amount' => 50, 'currency' => 'USD', 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => now(), 'category' => 'materials']);
    $service = app(FinanceUsdUsageService::class);
    $service->recordIsraeliOperation(['transacted_at' => now(), 'operation_type' => 'materials', 'payment_mode' => 'direct_usd', 'actual_amount' => 100]);
    $service->recordIsraeliOperation(['transacted_at' => now(), 'operation_type' => 'owner_withdrawal', 'payment_mode' => 'direct_gel', 'actual_amount' => 200]);
    $service->transfer(['source' => 'israeli', 'amount' => 500, 'currency' => 'GEL', 'category' => 'bank_deposit', 'transacted_at' => now()]);
    PartnerFinanceTransaction::create(['source' => 'israeli', 'type' => 'currency_exchange', 'from_account' => 'cash', 'to_account' => 'cash', 'from_currency' => 'USD', 'from_amount' => 100, 'to_currency' => 'GEL', 'to_amount' => 270, 'exchange_rate' => 2.7, 'transacted_at' => now()]);
    PartnerFinanceTransaction::create(['source' => 'israeli', 'type' => 'expense', 'from_account' => 'bank', 'amount' => 30, 'currency' => 'GEL', 'category' => 'other', 'transacted_at' => now()]);
    $page = Livewire::test(Finance::class)->call('refreshBogBalance')->call('selectOverviewCard', 'cash');
    foreach (['all' => [700, 250, 1070, 1850], 'clinic' => [0, 50, 0, 50], 'israeli' => [700, 200, 1070, 1800]] as $source => [$gelOut, $usdOut, $gelCash, $usdCash]) {
        $page->set('businessSource', $source)->assertViewHas('figures', fn ($f) => $f['GEL']['cash_outflow'] === (float) $gelOut && $f['USD']['cash_outflow'] === (float) $usdOut && $f['GEL']['cash'] === (float) $gelCash && $f['USD']['cash'] === (float) $usdCash)
            ->assertViewHas('liquidity', fn ($l) => collect($l['cash'])->every(fn ($row) => round($row['opening'] + $row['received'] - $row['spent'], 2) === $row['amount']));
    }
    $page->call('selectOverviewCard', 'cash_outflow')->call('selectCashOutflowGroup', 'currency_exchange')
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && $rows->sole()->currency === 'USD' && (float) $rows->sole()->amount === 100.0);
});

test('cash outflow avoids Finance mirrors and includes held cash expenses and unknown source only in All', function () {
    overviewOpening('cash', 1000);
    $expense = app(FinanceManager::class)->create(['type' => 'expense', 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => now(), 'category' => 'materials']);
    FinanceTransaction::create(['type' => 'expense', 'amount' => 70, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'withdrawn_cash', 'transaction_date' => now(), 'category' => 'materials']);
    // Legacy posting with no reliable cash source; keep visible in All without guessing.
    DB::table('finance_transactions')->insert(['type' => 'expense', 'amount' => 30, 'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now(), 'category' => 'materials']);
    $report = app(CashOutflowReport::class);
    expect((float) $report->totals('2026-09-11', '2026-09-11')->get('GEL')->amount)->toBe(200.0)
        ->and((float) $report->totals('2026-09-11', '2026-09-11', 'clinic')->get('GEL')->amount)->toBe(170.0)
        ->and($report->totals('2026-09-11', '2026-09-11', 'israeli'))->toBeEmpty();
    app(CashboxManager::class)->today()->transactions()->create(['type' => 'cash_withdrawal', 'amount' => 10, 'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now(), 'description' => 'Other cash out']);
    app(CashboxManager::class)->today()->transactions()->create(['type' => 'cash_withdrawal', 'amount' => 900, 'currency' => 'GEL', 'payment_method' => 'cash', 'transaction_date' => now(), 'description' => 'დღის დახურვისას სალაროდან ამოღებული ქეში']);
    expect((float) $report->groups('2026-09-11', '2026-09-11')->where('group_key', 'other')->sole()->amount)->toBe(10.0);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $report->totals('2026-09-11', '2026-09-11');
    $report->groups('2026-09-11', '2026-09-11');
    $report->entries('2026-09-11', '2026-09-11')->where('group_key', 'expenses')->simplePaginate(25);
    expect(DB::getQueryLog())->toHaveCount(3);
    DB::disableQueryLog();
});
