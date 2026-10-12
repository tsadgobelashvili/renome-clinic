<?php

use App\Filament\Pages\Cashbox;
use App\Models\CashboxTransaction;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\CashOutflowReport;
use App\Support\CashboxManager;
use App\Support\CashboxMovementPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-12 12:00:00');
    $this->manager = app(CashboxManager::class);
    $this->day = $this->manager->today();
    $this->day->update(['opening_balance' => 500]);
});

test('irrigator withdrawal moves exactly 200 from physical cash to card without any P&L posting', function () {
    $ledger = app(AccountingLedger::class);
    foreach (['income' => 40, 'expense' => 10] as $type => $amount) {
        \Illuminate\Support\Facades\DB::table('finance_transactions')->insert([
            'type' => $type, 'amount' => $amount, 'currency' => 'GEL', 'category' => 'other',
            'payment_method' => 'card', 'transaction_date' => now(),
        ]);
    }
    $before = $ledger->pnlTotals(null, null)->toArray();
    $cash = $this->manager->physicalCashBalances()['GEL'];
    $summary = $this->day->summary();
    $movement = $this->manager->withdrawIrrigator($this->day, '200.00');
    $after = $this->day->summary();

    expect($after['expected'])->toBe($summary['expected'] - 200)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe($cash - 200)
        ->and($after['cardAmountByCurrency']['GEL'])->toBe($summary['cardAmountByCurrency']['GEL'] + 200)
        ->and($after['cardIncome'])->toBe($summary['cardIncome'])
        ->and($after['cashIncome'])->toBe($summary['cashIncome'])
        ->and($after['cashExpenses'])->toBe($summary['cashExpenses'])
        ->and($ledger->pnlTotals(null, null)->toArray())->toEqual($before)
        ->and(Payment::count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(2)
        ->and(PartnerFinanceTransaction::count())->toBe(0)
        ->and($movement->patient_id)->toBeNull()
        ->and($movement->visit_id)->toBeNull()
        ->and(CashboxTransaction::count())->toBe(1)
        ->and(CashboxMovementPresentation::sign($movement))->toBe('−');

    $row = app(CashOutflowReport::class)->movements('2026-10-12', '2026-10-12')->sole();
    expect($row->origin)->toBe('irrigator_withdrawal')
        ->and($row->metric)->toBe('internal_transfer')
        ->and($row->description)->toContain('ირიგატორის თანხის გატანა', 'Cash -200.00 ₾', 'Card +200.00 ₾');
    $entry = $ledger->movements(null, null)->where('origin', 'irrigator_withdrawal')->sole();
    expect((int) $entry->is_transfer)->toBe(1)->and($entry->origin)->toBe('irrigator_withdrawal');

    $this->manager->close($this->day, 300, 0);
    expect($this->manager->physicalCashBalances()['GEL'])->toBe(300.0)
        ->and($this->manager->retainedCashByCurrency()['GEL'])->toBe(300.0);
});

test('cash usage action exposes localized irrigator option and posts through the cashier', function () {
    $this->actingAs(User::factory()->create());
    app()->setLocale('ka');
    Livewire::test(Cashbox::class)->callAction('withdrawal', [
        'usage_type' => 'irrigator_withdrawal', 'amount' => 200,
    ])->assertHasNoActionErrors()->assertSee('Cash -200.00 ₾')->assertSee('Card +200.00 ₾');
    expect($this->day->summary()['expected'])->toBe(300.0)
        ->and(__('cash-usage.irrigator_withdrawal'))->toBe('ირიგატორის თანხის გატანა');
    app()->setLocale('en');
    expect(__('cash-usage.irrigator_withdrawal'))->toBe('Irrigator withdrawal');
});

test('ordinary cash withdrawals keep their existing behavior', function () {
    $this->actingAs(User::factory()->create());
    Livewire::test(Cashbox::class)->callAction('withdrawal', ['amount' => 200])->assertHasNoActionErrors();
    expect($this->day->summary()['expected'])->toBe(300.0)
        ->and($this->day->summary()['withdrawals'])->toBe(200.0)
        ->and($this->day->summary()['cardAmountByCurrency']['GEL'])->toBe(0.0)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe(300.0)
        ->and(CashboxTransaction::sole()->type)->toBe('cash_withdrawal');
});

test('irrigator withdrawals reject unavailable cash and closed days without postings', function () {
    expect(fn () => $this->manager->withdrawIrrigator($this->day, 501))->toThrow(ValidationException::class);
    $this->day->update(['status' => 'closed']);
    expect(fn () => $this->manager->withdrawIrrigator($this->day, 200))->toThrow(ValidationException::class);
    expect(CashboxTransaction::count())->toBe(0);
});


test('cash transfers after an irrigator withdrawal move only the remaining held cash', function () {
    $this->manager->withdrawIrrigator($this->day, 200);
    $this->manager->close($this->day, 300, 0);
    $this->travelTo('2026-10-13 12:00:00');
    $destination = $this->manager->today();
    $transfer = $this->manager->transferCash($this->day->refresh(), $destination, 100, 'GEL', null, (string) str()->uuid());
    expect($transfer->transactions)->toHaveCount(2)
        ->and($destination->summary()['expected'])->toBe(100.0)
        ->and($destination->summary()['cardAmountByCurrency']['GEL'])->toBe(0.0)
        ->and($this->day->summary()['cardAmountByCurrency']['GEL'])->toBe(200.0)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe(300.0)
        ->and($this->manager->retainedCashByCurrency()['GEL'])->toBe(200.0)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null))->toBeEmpty();
});


test('original Cash Usage option routes irrigator withdrawals to clinic cash only', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $page = Livewire::test(\App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance::class);
    $before = $page->instance()->overview();
    $page->callAction('useIsraeliFunds', ['operation_type' => 'irrigator_withdrawal', 'actual_amount' => 200])
        ->assertHasNoActionErrors();
    expect($page->instance()->overview())->toEqual($before)
        ->and($this->day->summary()['expected'])->toBe(300.0)
        ->and($this->day->summary()['cardAmountByCurrency']['GEL'])->toBe(200.0)
        ->and(PartnerFinanceTransaction::count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(0)
        ->and(Payment::count())->toBe(0);
});
