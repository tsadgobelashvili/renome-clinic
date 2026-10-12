<?php

use App\Filament\Pages\Cashbox;
use App\Filament\Pages\Finance;
use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\CashOutflowReport;
use App\Services\Finance\LiquidityReport;
use App\Services\FinanceManager;
use App\Services\FinanceUsdUsageService;
use App\Support\CashboxManager;
use App\Support\CashboxMovementPresentation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        DB::table('finance_transactions')->insert([
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
        ->and($row->description)->toContain('ირიგატორის თანხის აღება', 'Cash -200.00 ₾', 'Card +200.00 ₾');
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
        ->and(__('cash-usage.irrigator_withdrawal'))->toBe('ირიგატორის თანხის აღება');
    app()->setLocale('en');
    expect(__('cash-usage.irrigator_withdrawal'))->toBe('Irrigator money withdrawal');
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

test('main Finance action changes Clinic cash and card amounts while Israeli history stays untouched', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $israeliPatient = Patient::create(['first_name' => 'Israel', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    foreach ([['GEL', 'cash', 100], ['USD', 'cash', 50], ['GEL', 'card', 30], ['USD', 'card', 20]] as [$currency, $method, $amount]) {
        PartnerPatientPayment::create(['patient_id' => $israeliPatient->id, 'amount' => $amount, 'currency' => $currency, 'payment_method' => $method, 'paid_at' => now()]);
    }
    $service = app(FinanceUsdUsageService::class);
    $israeliBalance = $service->cashBalances('israeli');
    $israeliRows = DB::table('partner_finance_transactions')->get()->toArray();
    $israeliPayments = DB::table('partner_patient_payments')->get()->toArray();
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toArray();
    $israeliPage = Livewire::test(ListPartnerFinance::class);
    $overview = $israeliPage->instance()->overview();
    $page = Livewire::test(Finance::class)->callAction('irrigatorWithdrawal', ['amount' => 200])
        ->assertHasNoActionErrors()
        ->assertViewHas('figures', fn ($figures) => $figures['GEL']['card'] === 200.0)
        ->call('selectOverviewCard', 'card')->assertSee('Card +200.00 ₾')->assertSee('Net 0.00 ₾')
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && $rows->first()->origin === 'irrigator_withdrawal');
    $page->call('selectOverviewCard', 'cash')->assertSee('ირიგატორის თანხის აღება');
    expect($this->manager->physicalCashBalances()['GEL'])->toBe(300.0)
        ->and(app(LiquidityReport::class)->current('clinic')['totals']['GEL']['cash'])->toBe(300.0)
        ->and($service->cashBalances('israeli'))->toEqual($israeliBalance)
        ->and($israeliPage->instance()->overview())->toEqual($overview)
        ->and(DB::table('partner_finance_transactions')->get()->toArray())->toEqual($israeliRows)
        ->and(DB::table('partner_patient_payments')->get()->toArray())->toEqual($israeliPayments)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toArray())->toEqual($pnl)
        ->and(app(AccountingLedger::class)->cardAmounts(null, null, 'israeli')->count())->toBe(0)
        ->and(Payment::count())->toBe(0)->and(Visit::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0);
});

test('Israeli Finance has no irrigator option and rejects a forged operation type', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $page = Livewire::test(ListPartnerFinance::class)->mountAction('useIsraeliFunds')
        ->assertFormFieldExists('operation_type', fn ($field) => ! array_key_exists('irrigator_withdrawal', $field->getOptions()));
    expect(fn () => app(FinanceUsdUsageService::class)->recordIsraeliOperation(['operation_type' => 'irrigator_withdrawal', 'payment_mode' => 'direct_gel', 'actual_amount' => 200]))
        ->toThrow(ValidationException::class);
    expect(CashboxTransaction::count())->toBe(0)->and(PartnerFinanceTransaction::count())->toBe(0);
});

test('normal Clinic card payments and expenses keep their accounting beside neutral irrigator amounts', function () {
    $patient = Patient::create(['first_name' => 'Normal', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Normal', 'last_name' => 'Doctor', 'is_active' => true]);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 500]);
    Payment::createWithSplits(['visit_id' => $visit->id, 'amount' => 300, 'currency' => 'GEL', 'payment_date' => now()], [
        ['payment_method' => 'cash', 'amount' => 100], ['payment_method' => 'card', 'amount' => 200],
    ]);
    app(FinanceManager::class)->create(['type' => 'expense', 'amount' => 25, 'currency' => 'GEL',
        'payment_method' => 'card', 'category' => 'other', 'transaction_date' => now()]);
    $ledger = app(AccountingLedger::class);
    $before = $ledger->pnlTotals(null, null)->sole();
    expect((float) $before->revenue)->toBe(300.0)->and((float) $before->expenses)->toBe(25.0)->and((float) $before->profit)->toBe(275.0)
        ->and((float) $ledger->cardAmounts(null, null)->sum('amount'))->toBe(200.0);
    $this->manager->withdrawIrrigator($this->day, 200);
    expect($ledger->pnlTotals(null, null)->sole())->toEqual($before)
        ->and((float) $ledger->cardAmounts(null, null)->sum('amount'))->toBe(400.0)
        ->and($ledger->cardAmounts(null, null)->count())->toBe(2)
        ->and($ledger->cardAmounts('2026-10-01', '2026-10-11')->count())->toBe(0)
        ->and($ledger->cardAmounts('2026-10-12', '2026-10-12', 'clinic')->count())->toBe(2)
        ->and($this->day->summary()['cardIncome'])->toBe(200.0)
        ->and($this->day->summary()['cardAmountByCurrency']['GEL'])->toBe(400.0)
        ->and(Payment::count())->toBe(1)->and(Visit::count())->toBe(1)->and(FinanceTransaction::count())->toBe(1);
});
