<?php

use App\Filament\Pages\Cashbox;
use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function cashboxVisit(array $attributes = []): Visit
{
    $patient = Patient::create(['first_name' => 'გიორგი', 'last_name' => 'ბერიძე']);
    $doctor = Doctor::create(['first_name' => 'ნოდარ', 'last_name' => 'ექიმი', 'is_active' => true]);

    return Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
        'visit_date' => today(), 'visit_type' => 'treatment', 'total_price' => 2000,
        'currency' => 'GEL', ...$attributes,
    ]);
}

test('one split payment creates one cashbox transaction and separates cash and card totals', function () {
    $visit = cashboxVisit();
    $payment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 500, 'currency' => 'GEL',
        'payment_date' => today()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 200],
        ['payment_method' => 'card', 'amount' => 300],
    ]);

    $day = CashboxDay::whereDate('date', today())->sole();
    $summary = $day->summary();

    expect(CashboxTransaction::where('payment_id', $payment->getKey())->count())->toBe(1)
        ->and((float) CashboxTransaction::where('payment_id', $payment->getKey())->sole()->amount)->toBe(500.0)
        ->and($summary['cashIncome'])->toBe(200.0)
        ->and($summary['cardIncome'])->toBe(300.0)
        ->and($summary['expected'])->toBe(200.0);
});

test('cashbox table presents split payment in separate cash and card columns', function () {
    $this->actingAs(User::factory()->create());
    $visit = cashboxVisit();
    Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 200, 'currency' => 'GEL',
        'payment_date' => today()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 50],
        ['payment_method' => 'card', 'amount' => 150],
    ]);

    $page = Livewire::test(Cashbox::class)
        ->assertSuccessful()
        ->assertSee('50.00 ₾')
        ->assertSee('150.00 ₾')
        ->assertSee('200.00 ₾')
        ->assertSee($visit->patient->full_name)
        ->assertSee($visit->doctor->full_name);

    expect(array_keys($page->instance()->getTable()->getColumns()))
        ->toBe([
            'transaction_date', 'type', 'patient.full_name', 'doctor_name',
            'cash_amount', 'card_amount', 'total_amount',
        ]);
});

test('payment changes synchronize without duplication and soft delete removes linked movement', function () {
    $visit = cashboxVisit();
    $payment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 500, 'currency' => 'GEL',
        'payment_date' => today()->toDateString(),
    ], [['payment_method' => 'cash', 'amount' => 500]]);

    $payment->update(['amount' => 400]);
    $payment->replaceSplits([['payment_method' => 'card', 'amount' => 400]]);

    expect(CashboxTransaction::where('payment_id', $payment->getKey())->count())->toBe(1)
        ->and((float) CashboxTransaction::where('payment_id', $payment->getKey())->sole()->amount)->toBe(400.0)
        ->and(CashboxDay::whereDate('date', today())->sole()->summary()['cardIncome'])->toBe(400.0);

    $payment->delete();
    expect(CashboxTransaction::where('payment_id', $payment->getKey())->exists())->toBeFalse();
});

test('expenses withdrawals closing and carry forward use physical cash formula', function () {
    $manager = app(CashboxManager::class);
    $day = $manager->today();
    $day->update(['opening_balance' => 300]);
    $visit = cashboxVisit();
    Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 2000, 'currency' => 'GEL',
        'payment_date' => today()->toDateString(),
    ], [['payment_method' => 'cash', 'amount' => 2000]]);
    $day->transactions()->create([
        'type' => 'expense', 'amount' => 200, 'currency' => 'GEL', 'payment_method' => 'cash',
        'transaction_date' => now(), 'expense_category' => 'materials',
    ]);

    expect($day->summary()['expected'])->toBe(2100.0);

    $manager->close($day, 2100, 300);
    $day->refresh();

    expect($day->status)->toBe('closed')
        ->and((float) $day->cash_withdrawal_total)->toBe(1800.0)
        ->and((float) $day->carry_forward_balance)->toBe(300.0)
        ->and((float) $day->expected_closing_balance)->toBe(2100.0)
        ->and($day->summary()['difference'])->toBe(0.0)
        ->and($day->transactions()->where('type', 'expense')->sum('amount'))->toEqual('200.00')
        ->and($day->transactions()->where('type', 'cash_withdrawal')->sum('amount'))->toEqual('1800.00')
        ->and($day->summary()['expected'])->toBe(300.0);

    $next = $manager->dayFor(today()->addDay()->toDateString());
    expect((float) $next->opening_balance)->toBe(300.0);
});

test('cashbox page renders and unresolved previous days are not silently auto closed', function () {
    $this->actingAs(User::factory()->create());
    CashboxDay::create(['date' => today()->subDay(), 'opening_balance' => 100, 'status' => 'open', 'opened_at' => now()->subDay()]);

    Livewire::test(Cashbox::class)
        ->assertSuccessful()
        ->assertSee('წინა დღე არ არის დახურული')
        ->assertSee('ნაღდი შემოსავალი')
        ->assertSee('ბარათით შემოსავალი');

    expect(CashboxDay::whereDate('date', today()->subDay())->sole()->status)->toBe('open');
});
