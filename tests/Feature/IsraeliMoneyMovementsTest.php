<?php

use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\User;
use App\Services\Finance\AccountingLedger;
use App\Services\IsraeliMoneyMovements;
use App\Services\PartnerFinanceSummary;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.timezone' => 'Asia/Tbilisi']);
    $this->travelTo(CarbonImmutable::parse('2026-10-28 12:00:00', 'Asia/Tbilisi'));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
});

function israeliMovement(array $attributes = []): PartnerFinanceTransaction
{
    return PartnerFinanceTransaction::create(array_replace([
        'source' => 'israeli', 'type' => 'transfer', 'category' => 'bank_deposit', 'transacted_at' => '2026-10-15 12:00:00',
        'from_account' => 'cash', 'to_account' => 'bank', 'amount' => 10, 'currency' => 'USD',
    ], $attributes));
}

test('period totals include every deposit withdrawal and exchange without mixing currencies or sources', function () {
    foreach ([10400, 5200, 3800] as $amount) {
        israeliMovement(['amount' => $amount]);
    }
    israeliMovement(['amount' => 25, 'currency' => 'GEL']);
    foreach ([40, 60] as $amount) {
        israeliMovement(['type' => 'owner_withdrawal', 'category' => null, 'to_account' => null, 'amount' => $amount]);
    }
    israeliMovement(['type' => 'owner_withdrawal', 'category' => null, 'to_account' => null, 'amount' => 15, 'currency' => 'GEL']);
    foreach ([[100, 270], [50, 132]] as [$from, $to]) {
        israeliMovement(['type' => 'currency_exchange', 'category' => null, 'from_currency' => 'USD', 'from_amount' => $from,
            'to_currency' => 'GEL', 'to_amount' => $to, 'exchange_rate' => 2.7]);
    }
    israeliMovement(['type' => 'currency_exchange', 'category' => null, 'from_currency' => 'GEL', 'from_amount' => 80,
        'to_currency' => 'USD', 'to_amount' => 30, 'exchange_rate' => 0.375]);
    israeliMovement(['amount' => 999, 'source' => 'clinic']);
    israeliMovement(['type' => 'expense', 'category' => 'materials', 'to_account' => null, 'amount' => 555]);
    $totals = app(IsraeliMoneyMovements::class)->totals('2026-10-01', '2026-10-28');
    expect($totals['bank_deposit']['totals'])->toBe(['GEL' => 25.0, 'USD' => 19400.0])
        ->and($totals['bank_deposit']['count'])->toBe(4)
        ->and($totals['owner_withdrawal']['totals'])->toBe(['GEL' => 15.0, 'USD' => 100.0])
        ->and($totals['exchange']['count'])->toBe(3)
        ->and($totals['exchange']['pairs'])->toBe([
            ['from_currency' => 'GEL', 'to_currency' => 'USD', 'from_amount' => 80.0, 'to_amount' => 30.0],
            ['from_currency' => 'USD', 'to_currency' => 'GEL', 'from_amount' => 150.0, 'to_amount' => 402.0],
        ]);
});

test('the existing year month and custom filters control both summaries and newest first modal details', function (string $period, string $from) {
    israeliMovement(['transacted_at' => $from.' 00:00:00', 'amount' => 100, 'notes' => 'Inclusive start']);
    $last = israeliMovement(['transacted_at' => '2026-10-28 23:59:59', 'amount' => 200, 'notes' => 'Inclusive finish']);
    israeliMovement(['transacted_at' => CarbonImmutable::parse($from)->subSecond()->format('Y-m-d H:i:s'), 'amount' => 999]);
    israeliMovement(['transacted_at' => '2026-10-29 00:00:00', 'amount' => 999]);
    $page = Livewire::test(ListPartnerFinance::class)->set('period', $period);
    if ($period === 'custom') {
        $page->set('dateFrom', $from)->set('dateUntil', '2026-10-28');
    }
    $page->assertSet('dateFrom', $from)->assertSet('dateUntil', '2026-10-28')
        ->mountAction('movementDetails', ['movement' => 'bank_deposit'])->assertActionMounted('movementDetails')
        ->assertMountedActionModalSee('Inclusive start')->assertMountedActionModalSee('Inclusive finish')->assertMountedActionModalSee('$300.00');
    $data = $page->instance()->movementDetails(['movement' => 'bank_deposit']);
    $summary = collect($page->instance()->overview()['movements'])->keyBy('key')['bank_deposit'];
    expect($data['movement'])->toBe($summary)->and($data['transactions']->count())->toBe(2)
        ->and($data['transactions']->first()->id)->toBe($last->id)->and($summary['totals']['USD'])->toBe(300.0);
})->with([
    'year' => ['1_year', '2025-10-28'],
    'month' => ['1_month', '2026-09-28'],
    'custom' => ['custom', '2026-10-10'],
]);

test('an open popup refreshes its range totals and rows instead of retaining a previous period', function () {
    israeliMovement(['transacted_at' => '2026-09-01', 'amount' => 50, 'notes' => 'September deposit']);
    israeliMovement(['transacted_at' => '2026-10-27', 'amount' => 20, 'notes' => 'October deposit']);
    $page = Livewire::test(ListPartnerFinance::class)->set('period', '1_year')
        ->mountAction('movementDetails', ['movement' => 'bank_deposit'])->assertMountedActionModalSee('$70.00')->assertMountedActionModalSee('September deposit');
    $page->set('period', '1_month')->assertSee('$20.00')
        ->assertMountedActionModalSee('$20.00')->assertMountedActionModalDontSee('September deposit')->assertMountedActionModalSee('28.09.2026');
    expect($page->instance()->movementDetails(['movement' => 'bank_deposit'])['movement']['totals']['USD'])->toBe(20.0);
});

test('empty periods display zeros for all three existing movement entries and their popups', function () {
    $page = Livewire::test(ListPartnerFinance::class)->assertSee('ფულის მოძრაობა')->assertSee('ანგარიშზე შეტანა')
        ->assertSee('მფლობელის გატანა')->assertSee('$0.00')->assertSee('0.00 ₾');
    foreach (['bank_deposit', 'exchange', 'owner_withdrawal'] as $key) {
        $data = $page->instance()->movementDetails(['movement' => $key]);
        expect($data['transactions']->count())->toBe(0)->and($data['movement']['totals'])->toBe(['GEL' => 0.0, 'USD' => 0.0]);
    }
    $page->mountAction('movementDetails', ['movement' => 'exchange'])->assertMountedActionModalSee('ჩანაწერები არ მოიძებნა')->assertMountedActionModalSee('სულ');
});

test('exchange popup shows both currencies and totals match the movement summary', function () {
    foreach ([100, 50] as $amount) {
        israeliMovement(['type' => 'currency_exchange', 'category' => null, 'from_currency' => 'USD', 'from_amount' => $amount,
            'to_currency' => 'GEL', 'to_amount' => $amount * 2.7, 'exchange_rate' => 2.7, 'notes' => 'Exchange reference']);
    }
    $page = Livewire::test(ListPartnerFinance::class)->set('period', '1_month')
        ->mountAction('movementDetails', ['movement' => 'exchange'])->assertMountedActionModalSee('$150.00 → 405.00 ₾')
        ->assertMountedActionModalSee('$100.00 → 270.00 ₾')->assertMountedActionModalSee('$50.00 → 135.00 ₾')->assertMountedActionModalSee('Exchange reference')->assertMountedActionModalSee('USD → GEL');
    $data = $page->instance()->movementDetails(['movement' => 'exchange']);
    expect($data['transactions']->count())->toBe(2)->and($data['movement']['count'])->toBe(2);
});

test('large detail lists paginate independently while the footer retains the complete period total', function () {
    foreach (range(1, 60) as $index) {
        israeliMovement(['notes' => 'Deposit '.$index]);
    }
    $page = Livewire::test(ListPartnerFinance::class)->set('period', '1_month')
        ->mountAction('movementDetails', ['movement' => 'bank_deposit'])->assertSee('$600.00');
    $data = $page->instance()->movementDetails(['movement' => 'bank_deposit']);
    expect($data['transactions']->count())->toBe(25)->and($data['transactions']->hasMorePages())->toBeTrue();
    $page->call('setPage', 3, 'israeliMovementPage')->assertSee('$600.00');
    expect($page->instance()->movementDetails(['movement' => 'bank_deposit'])['transactions']->count())->toBe(10);
    $page->set('dateFrom', '2026-10-16');
    expect($page->instance()->movementDetails(['movement' => 'bank_deposit'])['transactions']->currentPage())->toBe(1);
});

test('additional transfer labels remain separate and every movement belongs to only one summary', function () {
    israeliMovement(['category' => 'other_transfer', 'amount' => 25]);
    israeliMovement(['category' => null, 'amount' => 15]);
    israeliMovement(['amount' => 10]);
    $overview = Livewire::test(ListPartnerFinance::class)->set('period', '1_month')->instance()->overview();
    $rows = collect($overview['movements'])->keyBy('key');
    expect($rows)->toHaveCount(5)->and($rows['other_transfer']['label'])->toBe('სხვა გადატანა')
        ->and($rows['other_transfer']['totals']['USD'])->toBe(25.0)->and($rows['transfer']['totals']['USD'])->toBe(15.0)
        ->and($rows->sum('count'))->toBe(3);
});

test('summary calculations use grouped SQL and never hydrate the movement history', function () {
    foreach (range(1, 40) as $index) {
        israeliMovement(['amount' => 0.01]);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $totals = app(IsraeliMoneyMovements::class)->totals('2026-10-01', '2026-10-28');
        $queries = collect(DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }
    expect($totals['bank_deposit']['totals']['USD'])->toBe(0.4)->and($queries)->toHaveCount(2);
    foreach ($queries as $query) {
        expect(strtolower($query['query']))->toContain('sum(')->toContain('group by')->not->toContain('select *');
    }
});

test('the expense card keeps its existing timestamp and creator funding matches', function () {
    $creator = auth()->id();
    $other = User::factory()->create(['role' => User::ROLE_OWNER]);
    $exchange = ['type' => 'currency_exchange', 'category' => null, 'from_currency' => 'USD', 'from_amount' => 100,
        'to_currency' => 'GEL', 'to_amount' => 270, 'exchange_rate' => 2.7];
    israeliMovement($exchange);
    israeliMovement(['type' => 'expense', 'category' => 'materials', 'currency' => 'GEL', 'amount' => 200, 'to_account' => null]);
    israeliMovement(['type' => 'expense', 'category' => 'materials', 'currency' => 'GEL', 'amount' => 20, 'to_account' => null]);
    // Same timestamp, different creator is a separate operation.
    israeliMovement(['type' => 'expense', 'category' => 'materials', 'currency' => 'GEL', 'amount' => 30,
        'to_account' => null, 'created_by' => $other->id]);
    israeliMovement(array_replace($exchange, ['transacted_at' => '2026-10-16 12:00:00']));
    israeliMovement(['type' => 'expense', 'category' => 'materials', 'currency' => 'GEL', 'amount' => 40,
        'to_account' => null, 'source' => 'clinic']);
    $expenses = app(PartnerFinanceSummary::class)->expenseTotals('2026-10-01', '2026-10-28');
    $funding = app(IsraeliMoneyMovements::class)->expenseFunding('2026-10-01', '2026-10-28', $expenses);
    expect($funding)->toBe(['direct_gel' => 30.0, 'exchanged_usd' => 100.0, 'funded_gel' => 220.0]);
    expect(auth()->id())->toBe($creator);
});

test('reporting and opening details write nothing and preserve Israeli and Main Finance figures', function () {
    israeliMovement(['amount' => 100]);
    israeliMovement(['type' => 'owner_withdrawal', 'category' => null, 'to_account' => null, 'amount' => 20]);
    FinanceTransaction::create(['type' => 'income', 'transaction_date' => today(), 'category' => 'other',
        'amount' => 150, 'currency' => 'GEL', 'payment_method' => 'bank_transfer']);
    $tables = ['partner_finance_transactions', 'partner_patient_payments', 'finance_transactions', 'payments',
        'bank_transactions', 'cashbox_transactions', 'employee_advances', 'employee_advance_entries'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $balances = app(PartnerFinanceSummary::class)->accountBalances();
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toArray();
    $deposits = app(PartnerFinanceSummary::class)->bankDepositedTotals();
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $page = Livewire::test(ListPartnerFinance::class)->set('period', '1_year')
            ->mountAction('movementDetails', ['movement' => 'bank_deposit']);
        $page->instance()->movementDetails(['movement' => 'owner_withdrawal']);
        $writes = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^\s*(insert|update|delete|replace|alter|create|drop|truncate)\b/i', $query['query']));
    } finally {
        DB::disableQueryLog();
    }
    expect($writes)->toBeEmpty()->and($snapshot())->toBe($before)
        ->and(app(PartnerFinanceSummary::class)->accountBalances())->toBe($balances)
        ->and(app(PartnerFinanceSummary::class)->bankDepositedTotals())->toBe($deposits)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toArray())->toEqual($pnl);
});

test('the details endpoint rejects non Owners and unknown movement keys', function () {
    $page = Livewire::test(ListPartnerFinance::class)->instance();
    expect(fn () => $page->movementDetails(['movement' => 'salary_cash']))->toThrow(HttpException::class);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    expect(fn () => $page->movementDetails(['movement' => 'bank_deposit']))->toThrow(HttpException::class);
});
