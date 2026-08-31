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
        ->assertSee($visit->patient->full_name)
        ->assertSee('#'.$visit->getKey());

    expect(array_keys($page->instance()->getTable()->getColumns()))
        ->toBe([
            'transaction_date', 'type', 'patient.full_name', 'payment_method',
            'amount', 'currency', 'visit_id',
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
        ->and(CashboxTransaction::where('payment_id', $payment->getKey())->sole()->payment_method)->toBe('card')
        ->and(CashboxDay::whereDate('date', today())->sole()->summary()['expected'])->toBe(0.0);

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
        ->assertSee($visit->patient->full_name)
        ->assertSee('#'.$visit->getKey())
        ->assertSee('Gengigel ×2')
        ->assertSee('მასალები · Dental supplies')
        ->assertSee('ნაღდი')
        ->assertSee('ბარათი')
        ->assertSee('შექმნა: Cashier User');

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
        ->assertSee('25.08.26 18:04');
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

test('opening additions update previous carry and handover cumulatively by currency', function () {
    $manager = app(CashboxManager::class);
    $previous = $manager->dayFor(today()->subDay()->toDateString());
    $previous->update(['opening_balance' => 2200, 'opening_balance_usd' => 50]);
    $manager->close($previous, 2200, 0, null, 50, 0);
    $today = $manager->today();

    $manager->addOpeningBalance($today, 200, 25);
    $manager->addOpeningBalance($today, 100, 25);

    expect((float) $today->fresh()->opening_balance)->toBe(300.0)
        ->and((float) $today->fresh()->opening_balance_usd)->toBe(50.0)
        ->and((float) $previous->fresh()->carry_forward_balance)->toBe(300.0)
        ->and((float) $previous->fresh()->carry_forward_balance_usd)->toBe(50.0)
        ->and((float) $previous->fresh()->cash_withdrawal_total)->toBe(1900.0)
        ->and((float) $previous->fresh()->cash_withdrawal_total_usd)->toBe(0.0);
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
