<?php

use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\PaymentProcessor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function multiCurrencyVisit(float $total = 650): Visit
{
    $patient = Patient::create(['first_name' => 'Multi', 'last_name' => 'Currency']);
    $doctor = Doctor::create(['first_name' => 'Currency', 'last_name' => 'Doctor', 'is_active' => true]);

    return Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => now()->toDateString(),
        'total_price' => $total,
        'currency' => 'GEL',
    ]);
}

test('same currency payment reduces the visit debt directly', function () {
    $visit = multiCurrencyVisit();

    app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(), 'amount' => 100, 'currency' => 'GEL',
        'payment_date' => now()->toDateString(),
    ], [['payment_method' => 'cash', 'amount' => 100, 'currency' => 'GEL']]);

    expect($visit->refresh()->remaining_amount)->toBe(550.0);
});

test('foreign currency payment keeps its native cashbox amount and converts only debt reduction', function () {
    $visit = multiCurrencyVisit();

    $payment = app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(), 'amount' => 270, 'currency' => 'GEL',
        'payment_date' => now()->toDateString(),
    ], [[
        'payment_method' => 'cash', 'amount' => 100, 'currency' => 'USD', 'exchange_rate' => 2.70,
    ]]);

    $split = $payment->splits()->sole();
    $cashbox = CashboxTransaction::query()->where('payment_id', $payment->getKey())->sole();

    expect($visit->refresh()->remaining_amount)->toBe(380.0)
        ->and((float) $split->amount)->toBe(100.0)
        ->and($split->currency)->toBe('USD')
        ->and((float) $split->exchange_rate)->toBe(2.7)
        ->and((float) $cashbox->amount)->toBe(100.0)
        ->and($cashbox->currency)->toBe('USD');
});

test('mixed currency rows remain independent and post native cash movements', function () {
    $visit = multiCurrencyVisit();

    $payment = app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(), 'amount' => 370, 'currency' => 'GEL',
        'payment_date' => now()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 100, 'currency' => 'GEL'],
        ['payment_method' => 'cash', 'amount' => 100, 'currency' => 'USD', 'exchange_rate' => 2.70],
    ]);

    expect($visit->refresh()->remaining_amount)->toBe(280.0)
        ->and($payment->splits()->orderBy('id')->pluck('currency')->all())->toBe(['GEL', 'USD'])
        ->and(CashboxTransaction::query()->where('payment_id', $payment->getKey())->orderBy('currency')->pluck('amount', 'currency')->all())
        ->toBe(['GEL' => '100.00', 'USD' => '100.00']);
});

test('rounded USD payment confirms against its converted GEL value', function () {
    $visit = multiCurrencyVisit(700);

    $payment = app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(), 'amount' => 700, 'currency' => 'GEL',
        'payment_date' => now()->toDateString(),
    ], [[
        'payment_method' => 'cash', 'amount' => 267.66, 'currency' => 'USD', 'exchange_rate' => 2.6153,
    ]]);

    $split = $payment->splits()->sole();
    $cashbox = CashboxTransaction::query()->where('payment_id', $payment->getKey())->sole();

    expect($visit->refresh()->remaining_amount)->toBe(0.0)
        ->and((float) $split->amount)->toBe(267.66)
        ->and($split->currency)->toBe('USD')
        ->and((float) $split->exchange_rate)->toBe(2.6153)
        ->and((float) $cashbox->amount)->toBe(267.66)
        ->and($cashbox->currency)->toBe('USD');
});

test('rounded GEL and USD split confirms using the combined converted value', function () {
    $visit = multiCurrencyVisit(700);

    $payment = app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(), 'amount' => 700, 'currency' => 'GEL',
        'payment_date' => now()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 200, 'currency' => 'GEL'],
        ['payment_method' => 'card', 'amount' => 191.18, 'currency' => 'USD', 'exchange_rate' => 2.6153],
    ]);

    expect($visit->refresh()->remaining_amount)->toBe(0.0)
        ->and($payment->splits()->orderBy('id')->pluck('amount')->map(fn ($amount): float => (float) $amount)->all())
        ->toBe([200.0, 191.18])
        ->and($payment->splits()->orderBy('id')->pluck('currency')->all())->toBe(['GEL', 'USD'])
        ->and(CashboxTransaction::query()->where('payment_id', $payment->getKey())->count())->toBe(2);
});

test('converted USD overpayment is rejected', function () {
    $visit = multiCurrencyVisit(700);

    expect(fn () => app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(), 'amount' => 700, 'currency' => 'GEL',
        'payment_date' => now()->toDateString(),
    ], [[
        'payment_method' => 'cash', 'amount' => 300, 'currency' => 'USD', 'exchange_rate' => 2.6153,
    ]]))->toThrow(ValidationException::class);

    expect($visit->payments()->count())->toBe(0)
        ->and(CashboxTransaction::query()->where('visit_id', $visit->getKey())->count())->toBe(0);
});

test('manipulation total retains its unit price currency', function () {
    $visit = multiCurrencyVisit(0);
    $service = TreatmentCase::create(['name' => 'USD service', 'category' => 'therapy', 'is_active' => true]);

    $item = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $service->getKey(),
        'quantity' => 2,
        'unit_price' => 100,
        'currency' => 'USD',
        'exchange_rate' => 2.70,
    ]);
    $visit->syncTreatmentItemsTotal();

    expect($item->manipulation_total)->toBe(200.0)
        ->and($item->currency)->toBe('USD')
        ->and((float) $visit->refresh()->total_price)->toBe(540.0);
});

test('changing one payment row currency does not change another row', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Independent', 'last_name' => 'Rows']);
    $service = TreatmentCase::create(['name' => 'Independent currency service', 'category' => 'therapy', 'is_active' => true]);

    $component = Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $patient->getKey(),
        'visit_type' => 'treatment',
        'currency' => 'GEL',
        'treatmentCaseItems' => [[
            'service_choice' => (string) $service->getKey(),
            'treatment_case_id' => $service->getKey(),
            'quantity' => 1,
            'unit_price' => 650,
            'currency' => 'GEL',
        ]],
    ])->mountAction(TestAction::make('makePayment')->schemaComponent());

    $component->set('mountedActions.0.data.splits', [
        ['payment_method' => 'cash', 'amount' => 100, 'currency' => 'GEL'],
        ['payment_method' => 'card', 'amount' => 100, 'currency' => 'GEL'],
    ])->set('mountedActions.0.data.splits.1.currency', 'USD');

    $rows = array_values($component->get('mountedActions.0.data.splits'));

    expect($rows[0]['currency'])->toBe('GEL')
        ->and($rows[1]['currency'])->toBe('USD');
});
