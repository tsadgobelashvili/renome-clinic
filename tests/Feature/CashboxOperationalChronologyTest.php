<?php

use App\Filament\Pages\Cashbox;
use App\Filament\Pages\Dashboard;
use App\Models\CashboxDay;
use App\Models\FinanceOpeningBalance;
use App\Models\User;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\OpeningBalanceService;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake([config('services.nbg.rates_url') => Http::response('<Envelope><CurrencyRate><Code>USD</Code><Quantity>1</Quantity><Rate>2.700000</Rate></CurrencyRate></Envelope>')]);
    $this->travelTo('2026-10-19 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->manager = app(CashboxManager::class);
});

function chronologyOpening(string $date = '2026-01-03', int $amount = 1000): FinanceOpeningBalance
{
    return app(OpeningBalanceService::class)->create(['source' => 'cash', 'currency' => 'GEL',
        'amount' => $amount, 'effective_date' => $date, 'note' => 'Pre-ERP physical cash'], auth()->user());
}

function chronologyActivity(CashboxDay $day, string $type = 'other_income', int $amount = 200, array $attributes = []): void
{
    $day->transactions()->create([...['type' => $type, 'amount' => $amount, 'currency' => 'GEL',
        'payment_method' => 'cash', 'transaction_date' => $day->date->copy()->setTime(12, 0)], ...$attributes]);
}

test('January opening is cumulative cash and audit history but never creates January closings', function () {
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toJson();
    $opening = chronologyOpening();
    expect(CashboxDay::count())->toBe(0);
    $this->manager->ensureCalendarDaysThroughToday();
    expect($this->manager->unresolvedPreviousDay())->toBeNull()
        ->and($this->manager->oldestUnclosedDay()->date->toDateString())->toBe('2026-10-19')
        ->and(CashboxDay::count())->toBe(1)
        ->and(CashboxDay::operational()->count())->toBe(0)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe(1000.0)
        ->and($opening->fresh()->effective_date->toDateString())->toBe('2026-01-03')
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toJson())->toBe($pnl)
        ->and(DB::table('finance_transactions')->count())->toBe(0);
});

test('existing generated January rows are ignored and first October activity includes opening cash', function () {
    chronologyOpening();
    foreach (['2026-01-03', '2026-01-04', '2026-01-05', '2026-09-20'] as $date) {
        CashboxDay::create(['date' => $date, 'status' => 'open', 'opening_balance' => 0]);
    }
    $closed = CashboxDay::create(['date' => '2026-01-02', 'status' => 'closed', 'actual_closing_balance' => 0]);
    $closedBefore = $closed->fresh()->getAttributes();
    $october = CashboxDay::create(['date' => '2026-10-05', 'status' => 'open', 'opening_balance' => 0]);
    chronologyActivity($october);
    $before = DB::table('cashbox_days')->count();
    expect($this->manager->oldestUnclosedDay()->id)->toBe($october->id)
        ->and($this->manager->unresolvedPreviousDay()->id)->toBe($october->id)
        ->and($october->summary()['opening']['GEL'])->toBe(1000.0)
        ->and($october->summary()['expected'])->toBe(1200.0)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe(1200.0)
        ->and(DB::table('cashbox_days')->count())->toBe($before)
        ->and($closed->fresh()->getAttributes())->toBe($closedBefore);
    $this->manager->close($october, 1200, 1200);
    expect($closed->fresh()->getAttributes())->toBe($closedBefore)
        ->and(CashboxDay::whereDate('date', '2026-01-03')->sole()->status)->toBe('open');
});

test('closing skips empty gaps and carries once to the next real activity day', function () {
    chronologyOpening();
    $first = $this->manager->dayFor('2026-10-05');
    chronologyActivity($first);
    $empty = $this->manager->dayFor('2026-10-06');
    $next = $this->manager->dayFor('2026-10-15');
    chronologyActivity($next, 'other_income', 300);
    expect(fn () => $this->manager->close($next, 1500, 1500))->toThrow(ValidationException::class);
    $this->manager->close($first, 1200, 1200);
    expect($this->manager->oldestUnclosedDay()->id)->toBe($next->id)
        ->and($next->fresh()->summary()['expected'])->toBe(1500.0)
        ->and($empty->fresh()->status)->toBe('open');
    $firstSnapshot = $first->fresh()->getAttributes();
    $this->manager->close($next, 1500, 1500);
    expect($this->manager->unresolvedPreviousDay())->toBeNull()
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe(1500.0)
        ->and($first->fresh()->getAttributes())->toBe($firstSnapshot);
});

test('already closed empty January dates do not absorb the historical opening projection', function () {
    chronologyOpening();
    CashboxDay::create(['date' => '2026-01-03', 'status' => 'closed', 'opening_balance' => 1000,
        'actual_closing_balance' => 1000, 'carry_forward_balance' => 1000]);
    $day = CashboxDay::create(['date' => '2026-10-05', 'status' => 'open', 'opening_balance' => 0]);
    chronologyActivity($day);
    expect($day->summary()['expected'])->toBe(1200.0)
        ->and($this->manager->oldestUnclosedDay()->id)->toBe($day->id);
});

test('multiple historical opening anchors create no mandatory operational days', function () {
    chronologyOpening('2026-01-03', 900);
    chronologyOpening('2026-01-04', 1000);
    $day = $this->manager->dayFor('2026-10-05');
    chronologyActivity($day);
    expect(FinanceOpeningBalance::count())->toBe(2)
        ->and(CashboxDay::count())->toBe(1)
        ->and($this->manager->oldestUnclosedDay()->id)->toBe($day->id)
        ->and($day->summary()['expected'])->toBe(1200.0);
});

test('held cash reconciliation and neutral corrections do not block current-cash activity', function () {
    chronologyOpening();
    $held = $this->manager->dayFor('2026-02-01');
    chronologyActivity($held, 'irrigator_withdrawal', 100, ['cash_usage_source' => 'withdrawn_cash']);
    $correction = $this->manager->dayFor('2026-03-01');
    chronologyActivity($correction, 'manual_adjustment', 1);
    $drawer = $this->manager->dayFor('2026-10-05');
    chronologyActivity($drawer, 'irrigator_withdrawal', 100, ['cash_usage_source' => 'current_cashier']);
    expect($this->manager->oldestUnclosedDay()->id)->toBe($drawer->id)
        ->and(CashboxDay::operational()->count())->toBe(1);
});

test('ordinary cash expense is an operational day and opening does not hide older activity', function () {
    $day = $this->manager->dayFor('2026-10-05');
    chronologyActivity($day, 'expense', 100);
    // Existing later cutovers are balance anchors, not closing-cursor filters.
    FinanceOpeningBalance::create(['source' => 'cash', 'bank' => '', 'account_identifier' => 'cashier',
        'currency' => 'GEL', 'effective_date' => '2026-10-10', 'amount' => 1000]);
    expect($this->manager->oldestUnclosedDay()->id)->toBe($day->id);
});

test('a later recorded zero carry is preserved rather than reviving an older nonzero carry', function () {
    CashboxDay::create(['date' => '2026-10-01', 'status' => 'closed', 'carry_forward_balance' => 4000]);
    $zero = CashboxDay::create(['date' => '2026-10-05', 'status' => 'closed', 'carry_forward_balance' => 0]);
    $before = $zero->fresh()->getAttributes();
    $day = $this->manager->dayFor('2026-10-15');
    chronologyActivity($day);
    expect((float) $day->opening_balance)->toBe(0.0)
        ->and($day->summary()['expected'])->toBe(200.0)
        ->and($zero->fresh()->getAttributes())->toBe($before);
});

test('future days cannot be closed when no earlier operational day is pending', function () {
    $tomorrow = $this->manager->dayFor(today()->addDay()->toDateString());
    expect(fn () => $this->manager->close($tomorrow, 0, 0))->toThrow(ValidationException::class)
        ->and($tomorrow->fresh()->status)->toBe('open');
});

test('Cashier page and dashboard Day Closing target October automatically and retain January audit history', function () {
    chronologyOpening();
    $january = $this->manager->dayFor('2026-01-03');
    $day = $this->manager->dayFor('2026-10-05');
    chronologyActivity($day);
    $cashier = Livewire::test(Cashbox::class)->assertSet('day.id', $day->id)->mountAction('closeDay')->assertActionMounted('closeDay');
    expect((string) $cashier->instance()->getMountedAction()->getModalHeading())->toContain('05.10.2026');
    $dashboard = Livewire::test(Dashboard::class)->mountAction('cashboxOverview')->mountAction('dashboardCloseCashboxDay')->assertActionMounted('dashboardCloseCashboxDay');
    expect((string) $dashboard->instance()->getMountedAction()->getModalHeading())->toContain('05.10.2026');
    expect($january->fresh()->status)->toBe('open')
        ->and(FinanceOpeningBalance::sole()->effective_date->toDateString())->toBe('2026-01-03');
});
