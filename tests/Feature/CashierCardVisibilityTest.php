<?php

use App\Filament\Pages\Cashbox;
use App\Filament\Pages\Dashboard;
use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use App\Support\CashboxManager;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00'));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
});

function cashierMixedPayment(): Payment
{
    $patient = Patient::create(['first_name' => 'Card', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Card', 'last_name' => 'Doctor', 'is_active' => true]);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id,
        'visit_date' => today(), 'total_price' => 1000, 'currency' => 'GEL']);

    return Payment::createWithSplits(['visit_id' => $visit->id, 'amount' => 500,
        'currency' => 'GEL', 'payment_date' => today()], [
            ['payment_method' => 'cash', 'amount' => 200], ['payment_method' => 'card', 'amount' => 300],
        ]);
}

test('dashboard shows todays card receipts and method rows without creating duplicate income', function () {
    app(CashboxManager::class)->dayFor('2026-09-09'); // Today remains visible even with an unresolved earlier day.
    $payment = cashierMixedPayment();
    Livewire::test(Dashboard::class)
        ->assertViewHas('cashBalances', ['GEL' => 200.0, 'USD' => 0.0])
        ->assertViewHas('cardReceipts', ['GEL' => 300.0, 'USD' => 0.0])
        ->assertSeeHtml('data-cashier-card-total')
        ->mountAction('cashboxOverview')
        ->assertMountedActionModalSee(['სალარო 10.09.2026', 'ბარათი', 'ნაღდი', '300.00 ₾', '200.00 ₾']);
    expect(Payment::count())->toBe(1);
    expect(FinanceTransaction::count())->toBe(0);
    expect(CashboxTransaction::where('payment_id', $payment->id)->count())->toBe(2);
});

test('closed history keeps card receipts while only physical cash carries to the next day', function () {
    cashierMixedPayment();
    $manager = app(CashboxManager::class);
    $day = $manager->today();
    $manager->close($day, 200, 200, null);
    expect((float) $day->expected_closing_balance)->toBe(200.0);
    expect((float) $day->carry_forward_balance)->toBe(200.0);
    expect($day->summary()['cardIncome'])->toBe(300.0);
    $this->travelTo(CarbonImmutable::parse('2026-09-11 12:00:00'));
    $next = $manager->today();
    expect($next->summary()['expected'])->toBe(200.0);
    expect($next->summary()['cardIncome'])->toBe(0.0);
    Livewire::test(Cashbox::class)->assertSee('ბარათით შემოსავალი')
        ->assertViewHas('history', function ($history) use ($day) {
            $row = $history->first(fn ($row) => $row['day']->id === $day->id);

            return $row['summary']['cardIncome'] === 300.0
                && $row['transactions']->pluck('transaction.payment_method')->sort()->values()->all() === ['card', 'cash'];
        });
    expect(Payment::count())->toBe(1);
});

test('card totals use original payment splits and exclude deleted payments', function () {
    $payment = cashierMixedPayment();
    $day = app(CashboxManager::class)->today();
    // An absent derived ledger row must not hide a genuine patient card receipt.
    CashboxTransaction::where('payment_id', $payment->id)->where('payment_method', 'card')->delete();
    expect($day->summary()['cardIncome'])->toBe(300.0);
    expect($day->summary()['expected'])->toBe(200.0);
    $payment->delete();
    expect($day->summary()['cardIncome'])->toBe(0.0);
});

test('day summaries use one SQL aggregate query and history totals are batched', function () {
    cashierMixedPayment();
    $manager = app(CashboxManager::class);
    $day = $manager->today();
    foreach (range(1, 13) as $offset) {
        $manager->dayFor(today()->subDays($offset)->toDateString());
    }
    $days = CashboxDay::all();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $totals = $manager->summaryTotals($days);
    foreach ($days as $historyDay) {
        $manager->summary($historyDay, $totals->get($historyDay->id, collect()));
    }
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1);
    expect(strtolower($queries[0]['query']))->toContain('sum(')->toContain('group by')->toContain('payment_splits');
    expect($manager->summary($day)['cardIncome'])->toBe(300.0);
});

test('card receipts do not increase the withdrawal limit', function () {
    cashierMixedPayment();
    Livewire::test(Cashbox::class)->mountAction('withdrawal')
        ->fillForm(['amount' => 201])->callMountedAction()->assertHasErrors(['amount']);
    expect(CashboxTransaction::where('type', 'cash_withdrawal')->count())->toBe(0);
    expect(app(CashboxManager::class)->today()->summary()['expected'])->toBe(200.0);
});
