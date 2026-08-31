<?php

use App\Filament\Pages\Finance;
use App\Models\CashboxTransaction;
use App\Models\DirectExpense;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\FinanceManager;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function financeVisit(float $total = 3000): Visit
{
    $patient = Patient::create(['first_name' => 'Finance', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Finance', 'last_name' => 'Doctor', 'is_active' => true]);

    return Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
        'visit_date' => today(), 'total_price' => $total, 'currency' => 'GEL',
    ]);
}

test('patient payment is finance income while cashier keeps each method and physical cash separately', function (array $splits, float $cashExpected) {
    $visit = financeVisit();
    $payment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 500, 'currency' => 'GEL',
        'payment_date' => today()->toDateString(),
    ], $splits);

    $cashboxAmount = (float) CashboxTransaction::query()->where('payment_id', $payment->getKey())->sum('amount');
    $financeIncome = (float) Payment::query()->whereKey($payment)->sum('amount');

    expect($financeIncome)->toBe(500.0)
        ->and($cashboxAmount)->toBe(500.0)
        ->and(app(CashboxManager::class)->today()->summary()['cashIncome'])->toBe($cashExpected)
        ->and(Payment::query()->whereKey($payment)->count())->toBe(1)
        ->and(CashboxTransaction::query()->where('payment_id', $payment->getKey())->count())->toBe(count($splits));
})->with([
    'cash' => [[['payment_method' => 'cash', 'amount' => 500]], 500],
    'card' => [[['payment_method' => 'card', 'amount' => 500]], 0],
    'bank transfer' => [[['payment_method' => 'bank_transfer', 'amount' => 500]], 0],
    'split' => [[['payment_method' => 'cash', 'amount' => 200], ['payment_method' => 'card', 'amount' => 300]], 200],
]);

test('finance expenses affect current cashier only when explicitly sourced from it', function (string $method, ?string $source, float $cashExpected) {
    $manager = app(FinanceManager::class);
    $expense = $manager->create([
        'type' => 'expense', 'transaction_date' => now(), 'category' => 'materials',
        'description' => 'Supplier', 'amount' => 200, 'currency' => 'GEL',
        'payment_method' => $method, 'cash_source' => $source,
    ]);

    expect(FinanceTransaction::query()->whereKey($expense)->sum('amount'))->toEqual(200)
        ->and((float) CashboxTransaction::query()->where('finance_transaction_id', $expense->getKey())->sum('amount'))->toBe($cashExpected)
        ->and(app(CashboxManager::class)->today()->summary()['cashExpenses'])->toBe($cashExpected);
})->with([
    'current cashier' => ['cash', 'current_cashier', 200],
    'previously withdrawn cash' => ['cash', 'withdrawn_cash', 0],
    'card' => ['card', null, 0],
    'bank transfer' => ['bank_transfer', null, 0],
]);

test('linked finance expense updates and deletes without duplicate or orphan cashier movements', function () {
    $manager = app(FinanceManager::class);
    $expense = $manager->create([
        'type' => 'expense', 'transaction_date' => now(), 'category' => 'office',
        'amount' => 300, 'currency' => 'GEL', 'payment_method' => 'cash',
        'cash_source' => 'current_cashier',
    ]);
    $manager->update($expense, ['amount' => 250]);

    expect(CashboxTransaction::query()->where('finance_transaction_id', $expense->getKey())->count())->toBe(1)
        ->and((float) $expense->refresh()->cashboxTransaction->amount)->toBe(250.0);

    $manager->delete($expense);

    expect(FinanceTransaction::query()->whereKey($expense)->exists())->toBeFalse()
        ->and(CashboxTransaction::query()->where('finance_transaction_id', $expense->getKey())->exists())->toBeFalse();
});

test('cash withdrawal and carryover change physical cash but never finance expense', function () {
    $cashbox = app(CashboxManager::class);
    $day = $cashbox->today();
    $visit = financeVisit();
    Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 3000, 'currency' => 'GEL', 'payment_date' => today(),
    ], [['payment_method' => 'cash', 'amount' => 3000]]);

    $cashbox->close($day, 3000, 300);
    $next = $cashbox->dayFor(today()->addDay()->toDateString());

    expect((float) $day->fresh()->cash_withdrawal_total)->toBe(2700.0)
        ->and((float) $next->opening_balance)->toBe(300.0)
        ->and(FinanceTransaction::query()->where('type', 'expense')->sum('amount'))->toEqual(0);
});

test('visit direct expense remains separate from general finance expense', function () {
    $visit = financeVisit();
    $service = TreatmentCase::create(['name' => 'Clinical work', 'category' => 'orthopedics', 'is_active' => true]);
    $item = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $service->getKey(), 'quantity' => 1, 'unit_price' => 500,
    ]);
    $direct = $item->directExpenses()->create(['name' => 'Lab', 'amount' => 100, 'currency' => 'GEL']);

    expect($direct)->toBeInstanceOf(DirectExpense::class)
        ->and(FinanceTransaction::query()->count())->toBe(0);
});

test('finance page derives patient income and manual expenses without duplicate income rows', function () {
    $this->actingAs(User::factory()->create());
    $visit = financeVisit();
    Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 500, 'currency' => 'GEL', 'payment_date' => today()->toDateString(),
    ], [['payment_method' => 'card', 'amount' => 500]]);
    app(FinanceManager::class)->create([
        'type' => 'expense', 'transaction_date' => now(), 'category' => 'rent',
        'amount' => 200, 'currency' => 'GEL', 'payment_method' => 'bank_transfer',
    ]);

    $page = Livewire::test(Finance::class)->assertSuccessful();

    expect($page->get('dateFrom'))->toBe(today()->startOfMonth()->toDateString())
        ->and($page->get('dateUntil'))->toBe(today()->toDateString())
        ->and($page->get('currency'))->toBe('GEL')
        ->and((float) Payment::query()->whereDate('payment_date', $page->get('dateUntil'))->sum('amount'))->toBe(500.0);

    $page
        ->assertViewHas('income', 500.0)
        ->assertViewHas('expense', 200.0)
        ->assertViewHas('result', 300.0)
        ->assertViewHas('incomeByMethod', fn (array $totals): bool => $totals === [
            'cash' => 0.0,
            'card' => 500.0,
            'bank_transfer' => 0.0,
        ])
        ->assertViewHas('expenseByMethod', fn (array $totals): bool => $totals === [
            'cash' => 0.0,
            'card' => 0.0,
            'bank_transfer' => 200.0,
        ])
        ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 2
            && $entries->pluck('key')->unique()->count() === 2
            && $entries->where('key', 'payment-'.Payment::query()->sole()->getKey())->count() === 1)
        ->assertSee($visit->patient->full_name)
        ->assertSee('ქირა');

    expect(FinanceTransaction::query()->where('type', 'income')->count())->toBe(0);
});
