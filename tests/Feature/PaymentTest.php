<?php

use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createVisitForPaymentTest(array $attributes = []): Visit
{
    $patient = Patient::create([
        'first_name' => 'Test',
        'last_name' => 'Patient',
    ]);

    $doctor = Doctor::create([
        'first_name' => 'Test',
        'last_name' => 'Doctor',
        'is_active' => true,
    ]);

    return Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => now()->toDateString(),
        'total_price' => 100,
        ...$attributes,
    ]);
}

test('a visit calculates paid and remaining amounts from its payments', function () {
    $visit = createVisitForPaymentTest();

    $visit->payments()->createMany([
        [
            'amount' => 30,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'cash',
        ],
        [
            'amount' => 20,
            'payment_date' => now()->toDateString(),
            'payment_method' => 'card',
        ],
    ]);

    expect($visit->refresh()->payments)->toHaveCount(2)
        ->and($visit->paid_amount)->toBe(50.0)
        ->and($visit->remaining_amount)->toBe(50.0)
        ->and($visit->payments->first()->visit->is($visit))->toBeTrue();
});

test('split payments count the payment amount once and preserve the method breakdown', function () {
    $visit = createVisitForPaymentTest(['total_price' => 1500]);

    $first = Payment::createWithSplits([
        'visit_id' => $visit->getKey(),
        'amount' => 500,
        'payment_date' => now()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 200],
        ['payment_method' => 'card', 'amount' => 300],
    ]);
    Payment::createWithSplits([
        'visit_id' => $visit->getKey(),
        'amount' => 400,
        'payment_date' => now()->toDateString(),
    ], [
        ['payment_method' => 'card', 'amount' => 400],
    ]);

    $breakdown = PaymentSplit::query()
        ->whereHas('payment', fn ($query) => $query->where('visit_id', $visit->getKey()))
        ->selectRaw('payment_method, SUM(amount) AS total')
        ->groupBy('payment_method')->pluck('total', 'payment_method');

    expect($first->splits)->toHaveCount(2)
        ->and($visit->refresh()->paid_amount)->toBe(900.0)
        ->and($visit->remaining_amount)->toBe(600.0)
        ->and((float) $breakdown['cash'])->toBe(200.0)
        ->and((float) $breakdown['card'])->toBe(700.0);
});

test('the visit payment modal saves one payment with two splits', function () {
    $this->actingAs(User::factory()->create());
    $visit = createVisitForPaymentTest(['total_price' => 1000]);
    $treatment = TreatmentCase::create([
        'name' => 'Test treatment',
        'category' => 'therapy',
        'is_active' => true,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 1,
        'unit_price' => 1000,
    ]);

    Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()])
        ->callAction(TestAction::make('makePayment')->schemaComponent(), [
            'amount' => 500,
            'currency' => 'GEL',
            'splits' => [
                ['payment_method' => 'cash', 'amount' => 200],
                ['payment_method' => 'card', 'amount' => 300],
            ],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('გადახდა წარმატებით დაემატა.');

    $payment = $visit->payments()->with('splits')->sole();

    expect((float) $payment->amount)->toBe(500.0)
        ->and($payment->currency)->toBe('GEL')
        ->and($payment->splits)->toHaveCount(2)
        ->and((float) $payment->splits->where('payment_method', 'cash')->sole()->amount)->toBe(200.0)
        ->and((float) $payment->splits->where('payment_method', 'card')->sole()->amount)->toBe(300.0)
        ->and($visit->refresh()->paid_amount)->toBe(500.0)
        ->and($visit->remaining_amount)->toBe(500.0)
        ->and($payment->audits()->where('action', 'created')->exists())->toBeTrue();
});

test('the visit payment modal settles 2650 with 2000 cash and 650 card', function () {
    $this->actingAs(User::factory()->create());
    $visit = createVisitForPaymentTest(['total_price' => 2650]);
    $treatment = TreatmentCase::create([
        'name' => 'Full split payment treatment',
        'category' => 'therapy',
        'is_active' => true,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 1,
        'unit_price' => 2650,
    ]);

    Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()])
        ->callAction(TestAction::make('makePayment')->schemaComponent(), [
            'amount' => '2650.00',
            'currency' => 'GEL',
            'splits' => [
                ['payment_method' => 'cash', 'amount' => '2000.00'],
                ['payment_method' => 'card', 'amount' => '650.00'],
            ],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('გადახდა წარმატებით დაემატა.');

    $payment = $visit->payments()->with('splits')->sole();

    expect((float) $payment->amount)->toBe(2650.0)
        ->and($payment->splits)->toHaveCount(2)
        ->and((float) $payment->splits->sum('amount'))->toBe(2650.0)
        ->and($visit->refresh()->remaining_amount)->toBe(0.0);
});

test('the visit payment modal shows a split total validation error without saving partial data', function () {
    $this->actingAs(User::factory()->create());
    $visit = createVisitForPaymentTest(['total_price' => 1000]);
    $treatment = TreatmentCase::create([
        'name' => 'Validation treatment',
        'category' => 'therapy',
        'is_active' => true,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 1,
        'unit_price' => 1000,
    ]);

    Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()])
        ->callAction(TestAction::make('makePayment')->schemaComponent(), [
            'amount' => 500,
            'currency' => 'GEL',
            'splits' => [
                ['payment_method' => 'cash', 'amount' => 200],
                ['payment_method' => 'card', 'amount' => 250],
            ],
        ])
        ->assertHasActionErrors(['splits']);

    expect($visit->payments()->count())->toBe(0)
        ->and(PaymentSplit::query()->count())->toBe(0);
});

test('payments and splits preserve currency without mixing visit balances', function () {
    $visit = createVisitForPaymentTest(['total_price' => 1500, 'currency' => 'GEL']);

    $gelPayment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(),
        'amount' => 500,
        'currency' => 'GEL',
        'payment_date' => now()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 200],
        ['payment_method' => 'card', 'amount' => 300],
    ]);

    $usdPayment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(),
        'amount' => 300,
        'currency' => 'USD',
        'payment_date' => now()->toDateString(),
    ], [
        ['payment_method' => 'transfer', 'amount' => 300],
    ]);

    expect($gelPayment->splits->pluck('currency')->unique()->all())->toBe(['GEL'])
        ->and($usdPayment->splits->pluck('currency')->unique()->all())->toBe(['USD'])
        ->and($visit->refresh()->paid_amount)->toBe(500.0)
        ->and($visit->remaining_amount)->toBe(1000.0)
        ->and((float) $visit->payments()->where('currency', 'USD')->sum('amount'))->toBe(300.0);
});

test('a payment split cannot use a different currency from its payment', function () {
    $visit = createVisitForPaymentTest(['currency' => 'USD']);
    $payment = $visit->payments()->create([
        'amount' => 50,
        'currency' => 'USD',
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ]);

    expect(fn () => $payment->splits()->create([
        'payment_method' => 'card',
        'amount' => 10,
        'currency' => 'GEL',
    ]))->toThrow(ValidationException::class);
});

test('payment split amounts must exactly equal the payment amount', function () {
    $visit = createVisitForPaymentTest(['total_price' => 1500]);

    expect(fn () => Payment::createWithSplits([
        'visit_id' => $visit->getKey(),
        'amount' => 500,
        'payment_date' => now()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 200],
        ['payment_method' => 'card', 'amount' => 250],
    ]))->toThrow(ValidationException::class)
        ->and($visit->payments()->count())->toBe(0);
});

test('formatted payment amounts compare exactly in cents', function () {
    $visit = createVisitForPaymentTest(['total_price' => 2500]);

    $payment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(),
        'amount' => '2,500.00',
        'payment_date' => now()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => '1,500.00'],
        ['payment_method' => 'card', 'amount' => '1,000.00'],
    ]);

    expect((float) $payment->amount)->toBe(2500.0)
        ->and((float) $payment->splits()->sum('amount'))->toBe(2500.0)
        ->and($visit->refresh()->remaining_amount)->toBe(0.0);
});

test('payment and split changes are written to audit history with the current user', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $visit = createVisitForPaymentTest(['total_price' => 500]);

    $payment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(),
        'amount' => 500,
        'payment_date' => now()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 200],
        ['payment_method' => 'card', 'amount' => 300],
    ]);
    $payment->replaceSplits([
        ['payment_method' => 'cash', 'amount' => 150],
        ['payment_method' => 'card', 'amount' => 350],
    ]);

    expect($payment->created_by)->toBe($user->getKey())
        ->and($payment->audits()->where('user_id', $user->getKey())->where('action', 'created')->exists())->toBeTrue()
        ->and($payment->audits()->where('action', 'splits_updated')->exists())->toBeTrue()
        ->and($payment->splits()->sum('amount'))->toEqual(500);
});

test('payments cannot exceed the visit total price', function () {
    $visit = createVisitForPaymentTest();

    $visit->payments()->create([
        'amount' => 80,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'transfer',
    ]);

    expect(fn () => $visit->payments()->create([
        'amount' => 21,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ]))->toThrow(ValidationException::class);
});

test('a visit calculates discounted net paid and remaining amounts', function () {
    $visit = createVisitForPaymentTest([
        'total_price' => 100,
        'discount_type' => 'amount',
        'discount_value' => 20,
    ]);

    $visit->payments()->create([
        'amount' => 50,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'card',
    ]);

    $visit->refresh();

    expect($visit->gross_amount)->toBe(100.0)
        ->and((float) $visit->discount_amount)->toBe(20.0)
        ->and($visit->net_amount)->toBe(80.0)
        ->and($visit->paid_amount)->toBe(50.0)
        ->and($visit->remaining_amount)->toBe(30.0);
});

test('a visit calculates a percentage discount as an amount', function () {
    $visit = createVisitForPaymentTest([
        'total_price' => 1000,
        'discount_type' => 'percent',
        'discount_value' => 20,
    ]);

    $visit->payments()->create([
        'amount' => 500,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ]);

    $visit->refresh();

    expect((float) $visit->discount_amount)->toBe(200.0)
        ->and($visit->net_amount)->toBe(800.0)
        ->and($visit->paid_amount)->toBe(500.0)
        ->and($visit->remaining_amount)->toBe(300.0)
        ->and($visit->discount_display)->toBe('20.00% (200.00 ₾)');
});

test('payments cannot exceed the discounted net amount', function () {
    $visit = createVisitForPaymentTest([
        'total_price' => 100,
        'discount_amount' => 30,
    ]);

    $visit->payments()->create([
        'amount' => 70,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'transfer',
    ]);

    expect(fn () => $visit->payments()->create([
        'amount' => 1,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ]))->toThrow(ValidationException::class);
});

test('a second payment can settle the discounted remaining amount', function () {
    $visit = createVisitForPaymentTest([
        'total_price' => 1000,
        'discount_type' => 'percent',
        'discount_value' => 20,
    ]);

    $visit->payments()->create([
        'amount' => 500,
        'payment_date' => now()->subDay()->toDateString(),
        'payment_method' => 'cash',
    ]);
    $visit->payments()->create([
        'amount' => 300,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'card',
    ]);

    $visit->refresh();

    expect($visit->payments)->toHaveCount(2)
        ->and($visit->paid_amount)->toBe(800.0)
        ->and($visit->remaining_amount)->toBe(0.0)
        ->and($visit->payment_status)->toBe('paid');
});

test('a fully discounted visit needs no payment', function () {
    $visit = createVisitForPaymentTest([
        'total_price' => 500,
        'discount_type' => 'percent',
        'discount_value' => 100,
    ]);

    expect($visit->gross_amount)->toBe(500.0)
        ->and($visit->net_amount)->toBe(0.0)
        ->and($visit->paid_amount)->toBe(0.0)
        ->and($visit->remaining_amount)->toBe(0.0)
        ->and($visit->payment_status)->toBe('free')
        ->and($visit->payments()->count())->toBe(0);
});

test('a zero discount keeps the whole visit price payable', function () {
    $visit = createVisitForPaymentTest([
        'total_price' => 1000,
        'discount_type' => 'amount',
        'discount_value' => 0,
    ]);

    expect((float) $visit->discount_amount)->toBe(0.0)
        ->and($visit->net_amount)->toBe(1000.0)
        ->and($visit->remaining_amount)->toBe(1000.0);
});

test('a percentage discount cannot exceed one hundred percent', function () {
    expect(fn () => createVisitForPaymentTest([
        'total_price' => 1000,
        'discount_type' => 'percent',
        'discount_value' => 101,
    ]))->toThrow(ValidationException::class);
});

test('discount cannot exceed the total price', function () {
    expect(fn () => createVisitForPaymentTest([
        'total_price' => 100,
        'discount_amount' => 101,
    ]))->toThrow(ValidationException::class);
});

test('deleting a payment does not delete its visit', function () {
    $visit = createVisitForPaymentTest();

    $payment = $visit->payments()->create([
        'amount' => 25,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ]);

    $payment->delete();

    expect(Visit::query()->whereKey($visit->getKey())->exists())->toBeTrue()
        ->and(Payment::query()->whereKey($payment->getKey())->exists())->toBeFalse();
});

test('existing style visits default to treatment and consultation can be free', function () {
    $treatment = createVisitForPaymentTest(['total_price' => 100]);
    $consultation = createVisitForPaymentTest([
        'visit_type' => 'consultation',
        'total_price' => null,
    ]);

    expect($treatment->visit_type)->toBe('treatment')
        ->and($consultation->visit_type)->toBe('consultation')
        ->and($consultation->net_amount)->toBeNull()
        ->and($consultation->payments()->count())->toBe(0);
});
