<?php

use App\Filament\Pages\Cashbox;
use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Models\CashTransfer;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Product;
use App\Models\User;
use App\Models\Visit;
use App\Services\ProductSaleService;
use App\Support\CashboxManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
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

test('one split payment posts cash and card as separate cashier rows', function () {
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

    expect(CashboxTransaction::where('payment_id', $payment->getKey())->count())->toBe(2)
        ->and((float) CashboxTransaction::where('payment_id', $payment->getKey())->where('payment_method', 'cash')->sole()->amount)->toBe(200.0)
        ->and((float) CashboxTransaction::where('payment_id', $payment->getKey())->where('payment_method', 'card')->sole()->amount)->toBe(300.0)
        ->and($summary['cashIncome'])->toBe(200.0)
        ->and($summary['cardIncome'])->toBe(300.0)
        ->and($summary['expected'])->toBe(200.0);
});

test('cashbox table presents each patient payment method', function () {
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
        ->assertDontSee($visit->patient->full_name)
        ->assertDontSee('ექიმი');

    expect(array_keys($page->instance()->getTable()->getColumns()))
        ->toBe([
            'transaction_date', 'type', 'category', 'description', 'payment_method', 'amount',
        ]);
});

test('closed cashbox history shows the recorded split amounts in original currencies', function () {
    $this->actingAs(User::factory()->create());
    $visit = cashboxVisit(['total_price' => 335]);
    Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 335, 'currency' => 'GEL',
        'payment_date' => today()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 200, 'currency' => 'GEL'],
        ['payment_method' => 'cash', 'amount' => 50, 'currency' => 'USD', 'exchange_rate' => 2.7],
    ]);
    $day = app(CashboxManager::class)->today();
    app(CashboxManager::class)->close($day, 200, 200, null, 50, 50);

    Livewire::test(Cashbox::class)
        ->assertSuccessful()
        ->assertSee('თანხა')
        ->assertSee('200.00 ₾ + $50.00')
        ->assertDontSee('Visit');
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
        ->and(CashboxTransaction::where('payment_id', $payment->getKey())->sole()->payment_method)->toBe('card')
        ->and(CashboxDay::whereDate('date', today())->sole()->summary()['expected'])->toBe(0.0);

    $payment->delete();
    expect(CashboxTransaction::where('payment_id', $payment->getKey())->exists())->toBeFalse();
});

test('expenses closing and carry forward use physical cash formula without an automatic withdrawal', function () {
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
        ->and((float) $day->cash_withdrawal_total)->toBe(0.0)
        ->and((float) $day->carry_forward_balance)->toBe(300.0)
        ->and((float) $day->expected_closing_balance)->toBe(2100.0)
        ->and($day->summary()['difference'])->toBe(0.0)
        ->and($day->transactions()->where('type', 'expense')->sum('amount'))->toEqual('200.00')
        ->and($day->transactions()->where('type', 'cash_withdrawal')->sum('amount'))->toEqual('0')
        ->and($day->summary()['expected'])->toBe(2100.0);

    $next = $manager->dayFor(today()->addDay()->toDateString());
    expect((float) $next->opening_balance)->toBe(300.0);
});

test('closed cashier day details show auditable summaries and transaction metadata', function () {
    $user = User::factory()->create(['name' => 'Cashier User']);
    $this->actingAs($user);
    $manager = app(CashboxManager::class);
    $day = $manager->today();
    $day->update(['opening_balance' => 100]);
    $visit = cashboxVisit();

    Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 300, 'currency' => 'GEL',
        'payment_date' => today()->toDateString(), 'created_by' => $user->getKey(),
    ], [
        ['payment_method' => 'cash', 'amount' => 200],
        ['payment_method' => 'card', 'amount' => 100],
    ]);
    $day->transactions()->create([
        'type' => 'expense', 'amount' => 20, 'currency' => 'GEL', 'payment_method' => 'cash',
        'transaction_date' => now(), 'expense_category' => 'materials',
        'description' => 'Dental supplies', 'created_by' => $user->getKey(),
    ]);
    $product = Product::create(['name' => 'Gengigel', 'selling_price' => 40, 'is_active' => true]);
    app(ProductSaleService::class)->create([
        'items' => [['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price' => 40]],
        'payment_method' => 'cash', 'currency' => 'GEL', 'created_by' => $user->getKey(),
    ]);

    $manager->close($day, 360, 360);

    Livewire::test(Cashbox::class)
        ->assertSuccessful()
        ->assertSee('დახურული დღის დეტალები')
        ->assertSee('ბარათით შემოსავალი')
        ->assertSee('პროდუქტების გაყიდვა')
        ->assertSee('საწყისი ნაშთი / Carry')
        ->assertDontSee($visit->patient->full_name)
        ->assertDontSee('Visit')
        ->assertSee('პროდუქტის გაყიდვა')
        ->assertSee('მასალები')
        ->assertSee('Dental supplies')
        ->assertSee('ნაღდი')
        ->assertSee('ბარათი')
        ->assertSee('−20.00 ₾');

    $summary = $day->fresh()->summary();
    expect($summary['cashIncomeByCurrency']['GEL'])->toBe(280.0)
        ->and($summary['cardIncomeByCurrency']['GEL'])->toBe(100.0)
        ->and($summary['expensesByCurrency']['GEL'])->toBe(20.0)
        ->and($summary['productSalesByCurrency']['GEL'])->toBe(80.0);
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

test('cashbox warning opens the exact unresolved historical day', function () {
    $this->actingAs(User::factory()->create());
    $older = CashboxDay::create(['date' => today()->subDays(2), 'opening_balance' => 100, 'status' => 'open', 'opened_at' => now()->subDays(2)]);
    $followingDay = CashboxDay::create(['date' => today()->subDay(), 'opening_balance' => 200, 'status' => 'open', 'opened_at' => now()->subDay()]);

    Livewire::withQueryParams(['date' => $older->date->toDateString()])
        ->test(Cashbox::class)
        ->assertSet('day.id', $older->getKey())
        ->assertSee('დღის გახსნა')
        ->callAction(TestAction::make('closeDay'), [
            'actual_closing_balance' => 100,
            'actual_closing_balance_usd' => 0,
            'carry_forward_balance' => 50,
            'carry_forward_balance_usd' => 0,
        ])
        ->assertHasNoActionErrors();

    expect($older->fresh()->status)->toBe('closed')
        ->and((float) $followingDay->fresh()->opening_balance)->toBe(50.0);
});

test('closing yesterday switches to today and keeps existing and new payments visible', function () {
    $this->actingAs(User::factory()->create());
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 18:04:00', 'Asia/Tbilisi'));
    $manager = app(CashboxManager::class);
    $yesterday = $manager->dayFor('2026-08-24');
    $visit = cashboxVisit(['visit_date' => '2026-08-25', 'total_price' => 1000]);
    $existing = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 300, 'currency' => 'GEL',
        'payment_date' => '2026-08-25',
    ], [['payment_method' => 'cash', 'amount' => 300]]);
    $existingTimestamp = $existing->created_at->copy();

    $page = Livewire::test(Cashbox::class)
        ->assertSet('day.id', $yesterday->getKey())
        ->callAction(TestAction::make('closeDay'), [
            'actual_closing_balance' => 0,
            'carry_forward_balance' => 0,
        ])
        ->assertHasNoActionErrors();

    $today = CashboxDay::whereDate('date', '2026-08-25')->sole();
    $page->assertSet('day.id', $today->getKey())
        ->assertCanSeeTableRecords([$existing->cashboxTransaction]);

    $later = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 200, 'currency' => 'GEL',
        'payment_date' => '2026-08-25',
    ], [['payment_method' => 'cash', 'amount' => 200]]);

    $page->call('$refresh')->assertCanSeeTableRecords([$existing->cashboxTransaction, $later->cashboxTransaction]);

    expect($yesterday->fresh()->status)->toBe('closed')
        ->and($today->fresh()->status)->toBe('open')
        ->and($today->summary()['cashIncome'])->toBe(500.0)
        ->and($today->summary()['cardIncome'])->toBe(0.0)
        ->and($existing->fresh()->created_at->equalTo($existingTimestamp))->toBeTrue();
});

test('cashier renders transaction time in Tbilisi and keeps local business dates separate', function () {
    $this->actingAs(User::factory()->create());
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-25 18:04:00', 'Asia/Tbilisi'));
    $visit = cashboxVisit(['visit_date' => '2026-08-25']);
    $payment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 100, 'currency' => 'GEL',
        'payment_date' => '2026-08-25',
    ], [['payment_method' => 'cash', 'amount' => 100]]);

    expect($payment->cashboxTransaction->transaction_date->format('Y-m-d H:i'))->toBe('2026-08-25 18:04')
        ->and($payment->cashboxTransaction->day->date->toDateString())->toBe('2026-08-25');

    Livewire::test(Cashbox::class)
        ->assertSee('18:04');
});

test('a UTC timestamp around midnight maps to the correct Tbilisi cashier date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-24 20:30:00', 'UTC'));
    $visit = cashboxVisit(['visit_date' => '2026-08-25']);
    $payment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 100, 'currency' => 'GEL',
        'payment_date' => now('Asia/Tbilisi')->toDateString(),
    ], [['payment_method' => 'cash', 'amount' => 100]]);

    expect($payment->cashboxTransaction->day->date->toDateString())->toBe('2026-08-25')
        ->and($payment->cashboxTransaction->transaction_date->timezone('Asia/Tbilisi')->format('Y-m-d H:i'))
        ->toBe('2026-08-25 00:30');
});

test('a backdated payment cannot change a closed cashier day', function () {
    $manager = app(CashboxManager::class);
    $day = $manager->dayFor('2026-08-24');
    $manager->close($day, 0, 0);
    $visit = cashboxVisit(['visit_date' => '2026-08-24']);

    expect(fn () => Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 100, 'currency' => 'GEL',
        'payment_date' => '2026-08-24',
    ], [['payment_method' => 'cash', 'amount' => 100]]))->toThrow(ValidationException::class)
        ->and($day->transactions()->count())->toBe(0);
});

test('cash and card summaries preserve GEL and USD without conversion', function () {
    $visit = cashboxVisit();
    Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 1070, 'currency' => 'GEL',
        'payment_date' => today()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 300, 'currency' => 'GEL'],
        ['payment_method' => 'cash', 'amount' => 100, 'currency' => 'USD', 'exchange_rate' => 2.70],
        ['payment_method' => 'card', 'amount' => 400, 'currency' => 'GEL'],
        ['payment_method' => 'card', 'amount' => 100, 'currency' => 'USD', 'exchange_rate' => 1.00],
    ]);

    $summary = app(CashboxManager::class)->today()->summary();

    expect($summary['cashIncomeByCurrency'])->toBe(['GEL' => 300.0, 'USD' => 100.0])
        ->and($summary['cardIncomeByCurrency'])->toBe(['GEL' => 400.0, 'USD' => 100.0])
        ->and($summary['expectedByCurrency'])->toBe(['GEL' => 300.0, 'USD' => 100.0])
        ->and(CashboxTransaction::query()->where('type', 'patient_payment')->count())->toBe(4);
});

test('opening additions consume the retained pool cumulatively by currency', function () {
    $manager = app(CashboxManager::class);
    $previous = $manager->dayFor(today()->subDay()->toDateString());
    $previous->update(['opening_balance' => 2200, 'opening_balance_usd' => 50]);
    $manager->close($previous, 2200, 0, null, 50, 0);
    $today = $manager->today();

    $manager->addOpeningBalance($today, 200, 25);
    $manager->addOpeningBalance($today, 100, 25);

    expect((float) $today->fresh()->opening_balance)->toBe(300.0)
        ->and((float) $today->fresh()->opening_balance_usd)->toBe(50.0)
        ->and($manager->availableCashForOpening($today->fresh()))->toBe(['GEL' => 1900.0, 'USD' => 0.0])
        ->and((float) $previous->fresh()->carry_forward_balance)->toBe(0.0)
        ->and((float) $previous->fresh()->carry_forward_balance_usd)->toBe(0.0)
        ->and((float) $previous->fresh()->cash_withdrawal_total)->toBe(0.0)
        ->and((float) $previous->fresh()->cash_withdrawal_total_usd)->toBe(0.0);
});

test('retained cash accumulates across closed days and is only allocated once', function () {
    $manager = app(CashboxManager::class);
    $first = $manager->dayFor(today()->subDays(3)->toDateString());
    foreach ([['GEL', 300], ['USD', 40]] as [$currency, $amount]) {
        $first->transactions()->create([
            'type' => 'other_income', 'amount' => $amount, 'currency' => $currency,
            'payment_method' => 'cash', 'transaction_date' => $first->date->copy()->setTime(10, 0),
        ]);
    }
    $manager->close($first, 300, 0, null, 40, 0);
    $second = $manager->dayFor(today()->subDays(2)->toDateString());
    foreach ([['GEL', 500], ['USD', 60]] as [$currency, $amount]) {
        $second->transactions()->create([
            'type' => 'other_income', 'amount' => $amount, 'currency' => $currency,
            'payment_method' => 'cash', 'transaction_date' => $second->date->copy()->setTime(10, 0),
        ]);
    }
    $manager->close($second, 500, 0, null, 60, 0);
    $today = $manager->today();

    expect($manager->availableCashForOpening($today))->toBe(['GEL' => 800.0, 'USD' => 100.0]);

    $manager->addOpeningBalance($today, 150, 25);

    expect($manager->availableCashForOpening($today->fresh()))->toBe(['GEL' => 650.0, 'USD' => 75.0])
        ->and($today->summary()['cashIncomeByCurrency'])->toBe(['GEL' => 0.0, 'USD' => 0.0]);
});

test('opening balance uses older available cash when previous closed day is zero', function () {
    $manager = app(CashboxManager::class);
    $older = $manager->dayFor(today()->subWeek()->toDateString());
    $older->transactions()->create([
        'type' => 'other_income', 'amount' => 500, 'currency' => 'GEL',
        'payment_method' => 'cash', 'transaction_date' => today()->subWeek()->setTime(10, 0),
    ]);
    $manager->close($older, 0, 0);
    while ($manager->oldestUnclosedDay()->date->lt(today())) {
        $manager->close($manager->oldestUnclosedDay(), 0, 0);
    }
    $yesterday = CashboxDay::query()->whereDate('date', today()->subDay())->sole();
    $today = $manager->today();

    expect($manager->availableCashForOpening($today))->toBe(['GEL' => 500.0, 'USD' => 0.0]);

    $manager->addOpeningBalance($today, 300);

    expect((float) $today->fresh()->opening_balance)->toBe(300.0)
        ->and($manager->availableCashForOpening($today->fresh()))->toBe(['GEL' => 200.0, 'USD' => 0.0])
        ->and((float) $yesterday->fresh()->carry_forward_balance)->toBe(0.0);
});

test('opening balance allocates gel and usd independently from available cash', function () {
    $manager = app(CashboxManager::class);
    $older = $manager->dayFor(today()->subDays(2)->toDateString());
    foreach ([['GEL', 500], ['USD', 100]] as [$currency, $amount]) {
        $older->transactions()->create([
            'type' => 'patient_payment', 'amount' => $amount, 'currency' => $currency,
            'payment_method' => 'cash', 'transaction_date' => today()->subDays(2)->setTime(10, 0),
        ]);
    }
    $manager->close($older, 0, 0, null, 0, 0);
    $today = $manager->today();

    $manager->addOpeningBalance($today, 300, 50);

    expect($today->fresh()->summary()['opening'])->toBe(['GEL' => 300.0, 'USD' => 50.0])
        ->and($today->summary()['cashIncomeByCurrency'])->toBe(['GEL' => 0.0, 'USD' => 0.0])
        ->and($manager->availableCashForOpening($today->fresh()))->toBe(['GEL' => 200.0, 'USD' => 50.0]);
});

test('opening balance rejects an amount above available cash per currency', function () {
    $manager = app(CashboxManager::class);
    $older = $manager->dayFor(today()->subDay()->toDateString());
    $older->transactions()->create([
        'type' => 'other_income', 'amount' => 500, 'currency' => 'GEL',
        'payment_method' => 'cash', 'transaction_date' => today()->subDay()->setTime(10, 0),
    ]);
    $manager->close($older, 0, 0);
    $today = $manager->today();

    expect(fn () => $manager->addOpeningBalance($today, 600))->toThrow(ValidationException::class)
        ->and((float) $today->fresh()->opening_balance)->toBe(0.0);
});

test('usd opening uses older cash reserve when previous day closed with zero usd', function () {
    $manager = app(CashboxManager::class);
    $older = $manager->dayFor(today()->subDays(3)->toDateString());
    $older->transactions()->create([
        'type' => 'patient_payment', 'amount' => 100, 'currency' => 'USD',
        'payment_method' => 'cash', 'transaction_date' => today()->subDays(3)->setTime(10, 0),
    ]);
    $manager->close($older, 100, 0, null, 100, 0);
    while ($manager->oldestUnclosedDay()->date->lt(today())) {
        $manager->close($manager->oldestUnclosedDay(), 0, 0, null, 0, 0);
    }
    $yesterday = CashboxDay::query()->whereDate('date', today()->subDay())->sole();
    $today = $manager->today();

    expect($manager->availableCashForOpening($today)['USD'])->toBe(100.0)
        ->and((float) $yesterday->actual_closing_balance_usd)->toBe(0.0);
});

test('usd opening accepts a partial historical cash allocation', function () {
    $manager = app(CashboxManager::class);
    $older = $manager->dayFor(today()->subDay()->toDateString());
    $older->transactions()->create([
        'type' => 'other_income', 'amount' => 100, 'currency' => 'USD',
        'payment_method' => 'cash', 'transaction_date' => today()->subDay()->setTime(10, 0),
    ]);
    $manager->close($older, 100, 0, null, 100, 0);
    $today = $manager->today();

    $manager->addOpeningBalance($today, 0, 50);

    expect((float) $today->fresh()->opening_balance_usd)->toBe(50.0)
        ->and($manager->availableCashForOpening($today->fresh())['USD'])->toBe(50.0)
        ->and($today->summary()['cashIncomeByCurrency']['USD'])->toBe(0.0);
});

test('usd opening rejects more than the available historical usd cash', function () {
    $manager = app(CashboxManager::class);
    $older = $manager->dayFor(today()->subDay()->toDateString());
    $older->transactions()->create([
        'type' => 'other_income', 'amount' => 100, 'currency' => 'USD',
        'payment_method' => 'cash', 'transaction_date' => today()->subDay()->setTime(10, 0),
    ]);
    $manager->close($older, 100, 0, null, 100, 0);
    $today = $manager->today();

    expect(fn () => $manager->addOpeningBalance($today, 0, 101))->toThrow(ValidationException::class)
        ->and((float) $today->fresh()->opening_balance_usd)->toBe(0.0);
});

test('closing carry becomes next day opening once and never counts as revenue', function () {
    $manager = app(CashboxManager::class);
    $previous = $manager->dayFor(today()->subDay()->toDateString());
    $previous->update(['opening_balance' => 500, 'opening_balance_usd' => 80]);

    $manager->close($previous, 500, 200, null, 80, 30);
    $next = $manager->today();
    $sameNext = $manager->today();
    $summary = $next->summary();

    expect($sameNext->is($next))->toBeTrue()
        ->and((float) $next->opening_balance)->toBe(200.0)
        ->and((float) $next->opening_balance_usd)->toBe(30.0)
        ->and($summary['cashIncomeByCurrency'])->toBe(['GEL' => 0.0, 'USD' => 0.0])
        ->and($summary['expectedByCurrency'])->toBe(['GEL' => 200.0, 'USD' => 30.0])
        ->and($next->transactions()->whereIn('type', ['cash_transfer_in', 'cash_transfer_out'])->count())->toBe(0)
        ->and(CashboxDay::whereDate('date', today())->count())->toBe(1);
});

test('cashier exposes opening balance but no separate cash carryover action', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(Cashbox::class)
        ->assertSuccessful()
        ->assertActionExists('openingBalance')
        ->assertActionDoesNotExist('cashTransfer');
});

test('retained cash transfers into the current drawer without becoming revenue or expense', function () {
    $manager = app(CashboxManager::class);
    $source = $manager->dayFor(today()->subDay()->toDateString());
    $source->update(['opening_balance' => 500, 'opening_balance_usd' => 120]);
    $manager->close($source, 500, 0, null, 120, 0);
    $destination = $manager->today();

    $transfer = $manager->transferCash($source, $destination, 100, 'GEL', 'Change fund', (string) str()->uuid());

    expect($transfer->transactions)->toHaveCount(2)
        ->and($source->summary()['retainedCashByCurrency'])->toBe(['GEL' => 400.0, 'USD' => 120.0])
        ->and($destination->summary()['transferInByCurrency'])->toBe(['GEL' => 100.0, 'USD' => 0.0])
        ->and($destination->summary()['expectedByCurrency'])->toBe(['GEL' => 100.0, 'USD' => 0.0])
        ->and($destination->summary()['cashIncomeByCurrency'])->toBe(['GEL' => 0.0, 'USD' => 0.0])
        ->and($destination->summary()['cashExpensesByCurrency'])->toBe(['GEL' => 0.0, 'USD' => 0.0]);
});

test('cash transfers preserve currencies and allow multiple auditable transfers', function () {
    $manager = app(CashboxManager::class);
    $source = $manager->dayFor(today()->subDay()->toDateString());
    $source->update(['opening_balance' => 300, 'opening_balance_usd' => 80]);
    $manager->close($source, 300, 0, null, 80, 0);
    $destination = $manager->today();

    $manager->transferCash($source, $destination, 50, 'GEL', null, (string) str()->uuid());
    $manager->transferCash($source, $destination, 30, 'GEL', null, (string) str()->uuid());
    $manager->transferCash($source, $destination, 25, 'USD', null, (string) str()->uuid());

    expect(CashTransfer::count())->toBe(3)
        ->and(CashboxTransaction::whereIn('type', ['cash_transfer_in', 'cash_transfer_out'])->count())->toBe(6)
        ->and($destination->summary()['expectedByCurrency'])->toBe(['GEL' => 80.0, 'USD' => 25.0])
        ->and($source->summary()['retainedCashByCurrency'])->toBe(['GEL' => 220.0, 'USD' => 55.0]);
});

test('cash transfer rejects insufficient or cross-currency retained balance', function () {
    $manager = app(CashboxManager::class);
    $source = $manager->dayFor(today()->subDay()->toDateString());
    $source->update(['opening_balance' => 100]);
    $manager->close($source, 100, 0);
    $destination = $manager->today();

    expect(fn () => $manager->transferCash($source, $destination, 101, 'GEL', null, (string) str()->uuid()))
        ->toThrow(ValidationException::class)
        ->and(fn () => $manager->transferCash($source, $destination, 1, 'USD', null, (string) str()->uuid()))
        ->toThrow(ValidationException::class)
        ->and(CashTransfer::count())->toBe(0);
});

test('cash transfer idempotency prevents duplicate movements', function () {
    $manager = app(CashboxManager::class);
    $source = $manager->dayFor(today()->subDay()->toDateString());
    $source->update(['opening_balance' => 100]);
    $manager->close($source, 100, 0);
    $destination = $manager->today();
    $key = (string) str()->uuid();

    $first = $manager->transferCash($source, $destination, 40, 'GEL', 'Once', $key);
    $second = $manager->transferCash($source, $destination, 40, 'GEL', 'Once', $key);

    expect($second->is($first))->toBeTrue()
        ->and(CashTransfer::count())->toBe(1)
        ->and(CashboxTransaction::where('cash_transfer_id', $first->getKey())->count())->toBe(2)
        ->and($destination->summary()['expected'])->toBe(40.0);
});

test('carry cannot consume retained cash that has already been transferred', function () {
    $manager = app(CashboxManager::class);
    $source = $manager->dayFor(today()->subDay()->toDateString());
    $source->update(['opening_balance' => 100]);
    $manager->close($source, 100, 0);
    $destination = $manager->today();
    $manager->transferCash($source, $destination, 80, 'GEL', null, (string) str()->uuid());

    expect(fn () => $manager->addOpeningBalance($destination, 30, 0))->toThrow(ValidationException::class)
        ->and((float) $destination->fresh()->opening_balance)->toBe(0.0)
        ->and($source->summary()['retainedCashByCurrency']['GEL'])->toBe(20.0);
});

test('closing a historical cashbox day never touches current day cash or creates a withdrawal', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Tbilisi'));
    $manager = app(CashboxManager::class);
    $historical = $manager->dayFor('2026-08-31');
    $historical->transactions()->create([
        'type' => 'patient_payment', 'amount' => 100, 'currency' => 'GEL',
        'payment_method' => 'cash', 'transaction_date' => '2026-08-31 10:00:00',
    ]);
    $today = $manager->today();
    foreach ([['GEL', 300], ['USD', 50]] as [$currency, $amount]) {
        $today->transactions()->create([
            'type' => 'patient_payment', 'amount' => $amount, 'currency' => $currency,
            'payment_method' => 'cash', 'transaction_date' => now(),
        ]);
    }

    $before = $today->summary()['expectedByCurrency'];
    $manager->close($historical, 100, 0);

    expect($today->fresh()->summary()['expectedByCurrency'])->toBe($before)
        ->and($today->transactions()->where('type', 'cash_withdrawal')->count())->toBe(0)
        ->and($historical->transactions()->where('type', 'cash_withdrawal')->count())->toBe(0);
});

test('closing multiple historical days is isolated to each selected date', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-02 12:00:00', 'Asia/Tbilisi'));
    $manager = app(CashboxManager::class);
    $august = $manager->dayFor('2026-08-31');
    $september = $manager->dayFor('2026-09-01');
    $today = $manager->today();
    foreach ([[$august, 100], [$september, 200], [$today, 300]] as [$day, $amount]) {
        $day->transactions()->create([
            'type' => 'other_income', 'amount' => $amount, 'currency' => 'GEL',
            'payment_method' => 'cash', 'transaction_date' => $day->date->copy()->setTime(10, 0),
        ]);
    }

    $manager->close($august, 100, 0);
    $manager->close($september, 200, 0);

    expect((float) $august->fresh()->expected_closing_balance)->toBe(100.0)
        ->and((float) $september->fresh()->expected_closing_balance)->toBe(200.0)
        ->and($today->fresh()->summary()['expectedByCurrency']['GEL'])->toBe(300.0)
        ->and(CashboxTransaction::where('type', 'cash_withdrawal')->count())->toBe(0);
});

test('closing with zero carry creates no withdrawal', function () {
    $manager = app(CashboxManager::class);
    $day = $manager->dayFor(today()->subDay()->toDateString());
    $day->update(['opening_balance' => 100]);

    $manager->close($day, 100, 0);

    expect($day->fresh()->status)->toBe('closed')
        ->and((float) $day->cash_withdrawal_total)->toBe(0.0)
        ->and($day->transactions()->where('type', 'cash_withdrawal')->count())->toBe(0);
});

test('closing with carry passes it forward without creating a withdrawal', function () {
    $manager = app(CashboxManager::class);
    $day = $manager->dayFor(today()->subDay()->toDateString());
    $day->update(['opening_balance' => 100, 'opening_balance_usd' => 40]);
    $next = $manager->today();

    $manager->close($day, 100, 60, null, 40, 25);

    expect((float) $day->fresh()->carry_forward_balance)->toBe(60.0)
        ->and((float) $day->fresh()->carry_forward_balance_usd)->toBe(25.0)
        ->and($day->transactions()->where('type', 'cash_withdrawal')->count())->toBe(0)
        ->and($next->fresh()->summary()['opening'])->toBe(['GEL' => 60.0, 'USD' => 25.0]);
});

test('closing preserves a legitimate manual cash withdrawal without creating another one', function () {
    $manager = app(CashboxManager::class);
    $day = $manager->today();
    $day->update(['opening_balance' => 100]);
    $manual = $day->transactions()->create([
        'type' => 'cash_withdrawal', 'amount' => 30, 'currency' => 'GEL',
        'payment_method' => 'cash', 'transaction_date' => now(), 'description' => 'Manual withdrawal',
    ]);

    $manager->close($day, 70, 0);

    expect($day->transactions()->where('type', 'cash_withdrawal')->count())->toBe(1)
        ->and($day->transactions()->whereKey($manual->getKey())->exists())->toBeTrue()
        ->and((float) $day->fresh()->cash_withdrawal_total)->toBe(30.0);
});

test('cashbox closes the oldest unclosed calendar day including empty dates', function () {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 12:00:00', 'Asia/Tbilisi'));
    $manager = app(CashboxManager::class);
    CashboxDay::create([
        'date' => '2026-09-04', 'status' => 'closed', 'opened_at' => now()->subDays(2),
        'closed_at' => now()->subDays(2), 'actual_closing_balance' => 0,
    ]);
    $newer = $manager->dayFor('2026-09-06');

    $oldest = $manager->oldestUnclosedDay();

    expect($oldest->date->toDateString())->toBe('2026-09-05')
        ->and($oldest->transactions()->count())->toBe(0)
        ->and($oldest->summary()['expectedByCurrency'])->toBe(['GEL' => 0.0, 'USD' => 0.0]);

    expect(fn () => $manager->close($newer, 0, 0, null, 0, 0))
        ->toThrow(ValidationException::class, '05.09.2026');

    $manager->close($oldest, 0, 0, null, 0, 0);

    expect($oldest->fresh()->status)->toBe('closed')
        ->and($manager->oldestUnclosedDay()->date->toDateString())->toBe('2026-09-06');

    CarbonImmutable::setTestNow();
});

test('main cashbox shows empty calendar days and the dated close action', function () {
    $this->actingAs(User::factory()->create());
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-06 12:00:00', 'Asia/Tbilisi'));
    CashboxDay::create([
        'date' => '2026-09-04', 'status' => 'closed', 'opened_at' => now()->subDays(2),
        'closed_at' => now()->subDays(2), 'actual_closing_balance' => 0,
    ]);

    Livewire::test(Cashbox::class)
        ->assertSet('day.date', fn ($date): bool => $date->toDateString() === '2026-09-05')
        ->assertSee('დღის დახურვა 05.09.2026')
        ->assertSee('05.09.26')
        ->mountAction('closeDay')
        ->assertMountedActionModalSee(['დღის დახურვა 05.09.2026', '05.09.2026']);

    CarbonImmutable::setTestNow();
});
