<?php

use App\Enums\PaymentMethod;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\PaymentSplit;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\PaymentProcessor;
use App\Support\CashboxManager;
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

/** @param array<int, array{payment_method: string, amount: mixed}> $splits */
function stagedCreateVisitPayment(array $splits, mixed $amount = 3500): array
{
    $patient = Patient::create([
        'first_name' => 'New',
        'last_name' => 'Payment Patient',
    ]);
    $treatment = TreatmentCase::create([
        'name' => 'New visit treatment',
        'category' => 'therapy',
        'is_active' => true,
    ]);

    $component = Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => null,
            'visit_type' => 'treatment',
            'treatmentCaseItems' => [[
                'service_choice' => (string) $treatment->getKey(),
                'treatment_case_id' => $treatment->getKey(),
                'quantity' => 1,
                'unit_price' => $amount,
            ]],
        ]);
    $itemsBeforePayment = $component->instance()->form->getRawState()['treatmentCaseItems'];

    $component->call('submitPayment', [
        'amount' => $amount,
        'currency' => 'GEL',
        'splits' => $splits,
    ])
        ->assertNotified('გადახდა დამატებულია');

    expect($component->instance()->form->getRawState()['treatmentCaseItems'])
        ->toBe($itemsBeforePayment);

    return [$component, $patient, $treatment];
}

test('new visit manipulation quantity is initialized in actual form state', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(CreateVisit::class);
    $items = array_values($component->instance()->form->getRawState()['treatmentCaseItems']);

    expect($items)->toHaveCount(1)
        ->and($items[0]['quantity'])->toBe(1);
});

test('selecting a catalog service uses initialized quantity and enables payment immediately', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Default', 'last_name' => 'Quantity']);
    $treatment = TreatmentCase::create([
        'name' => 'Default quantity treatment',
        'category' => 'therapy',
        'default_price' => 750,
        'is_active' => true,
    ]);
    $component = Livewire::test(CreateVisit::class)
        ->set('data.patient_id', $patient->getKey());
    $itemKey = array_key_first($component->get('data.treatmentCaseItems'));

    $component->set("data.treatmentCaseItems.{$itemKey}.service_choice", (string) $treatment->getKey());
    $item = $component->instance()->form->getRawState()['treatmentCaseItems'][$itemKey];

    expect($item['quantity'])->toBe(1)
        ->and((float) $item['unit_price'])->toBe(750.0)
        ->and((float) $component->instance()->form->getRawState()['total_price'])->toBe(750.0)
        ->and($component->instance()->getCurrentRemainingAmount())->toBe(750.0);

    $component->call('submitPayment', [
        'amount' => 750,
        'currency' => 'GEL',
        'splits' => [['payment_method' => 'cash', 'amount' => 750]],
    ]);

    expect($component->instance()->getStagedPaidAmount())->toBe(750.0);
});

test('service selection preserves an edited quantity and each supplied row initializes independently', function () {
    $this->actingAs(User::factory()->create());
    $treatment = TreatmentCase::create([
        'name' => 'Quantity preservation treatment',
        'category' => 'therapy',
        'default_price' => 200,
        'is_active' => true,
    ]);
    $component = Livewire::test(CreateVisit::class)->fillForm([
        'treatmentCaseItems' => [
            ['quantity' => 3],
            ['quantity' => 1],
        ],
    ]);
    $keys = array_keys($component->get('data.treatmentCaseItems'));

    $component->set("data.treatmentCaseItems.{$keys[0]}.service_choice", (string) $treatment->getKey());
    $component->set("data.treatmentCaseItems.{$keys[1]}.service_choice", (string) $treatment->getKey());
    $items = $component->instance()->form->getRawState()['treatmentCaseItems'];

    expect($items[$keys[0]]['quantity'])->toBe(3)
        ->and($items[$keys[1]]['quantity'])->toBe(1)
        ->and((float) $component->instance()->form->getRawState()['total_price'])->toBe(800.0);
});

test('unsaved visit exposes partial staged payment paid remaining and method summary', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Partial', 'last_name' => 'Stage']);
    $treatment = TreatmentCase::create(['name' => 'Partial staged work', 'category' => 'therapy', 'is_active' => true]);
    $component = Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $patient->getKey(),
        'visit_type' => 'treatment',
        'treatmentCaseItems' => [[
            'service_choice' => (string) $treatment->getKey(),
            'treatment_case_id' => $treatment->getKey(),
            'quantity' => 1,
            'unit_price' => 6000,
        ]],
    ])->call('submitPayment', [
        'amount' => 3000,
        'currency' => 'GEL',
        'splits' => [['payment_method' => 'cash', 'amount' => 3000]],
    ]);

    expect($component->instance()->getStagedPaidAmount())->toBe(3000.0)
        ->and($component->instance()->getCurrentFinalPayableAmount())->toBe(6000.0)
        ->and($component->instance()->getCurrentRemainingAmount())->toBe(3000.0)
        ->and($component->instance()->getStagedPaymentSummary())->toContain('3,000.00')
        ->and(Visit::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
    $component->assertSee('გადახდილი')
        ->assertSee('დარჩენილი')
        ->assertSeeHtml('text-success-600')
        ->assertSeeHtml('text-danger-600');
});

test('unsaved visit full split stage is visible and reopens with existing rows', function () {
    $this->actingAs(User::factory()->create());
    [$component] = stagedCreateVisitPayment([
        ['payment_method' => 'cash', 'amount' => 2000],
        ['payment_method' => 'card', 'amount' => 1500],
    ]);

    expect($component->instance()->getCurrentRemainingAmount())->toBe(0.0)
        ->and($component->instance()->getStagedPaymentSummary())->toContain('2,000.00', '1,500.00')
        ->and(Payment::query()->count())->toBe(0);

    $component
        ->mountAction(TestAction::make('makePayment')->schemaComponent())
        ->assertActionDataSet([
            'amount' => 3500.0,
            'currency' => 'GEL',
        ]);
    $reopenedSplits = array_values($component->get('mountedActions.0.data.splits'));
    expect($reopenedSplits)->toMatchArray([
        ['payment_method' => 'cash', 'amount' => 2000.0, 'currency' => 'GEL', 'exchange_rate' => null, 'amount_manually_overridden' => null],
        ['payment_method' => 'card', 'amount' => 1500.0, 'currency' => 'GEL', 'exchange_rate' => null, 'amount_manually_overridden' => null],
    ]);
    $component->assertSeeHtml('text-success-600');
});

test('single staged payment row follows top amount and edit replaces pending state', function () {
    $this->actingAs(User::factory()->create());
    [$component] = stagedCreateVisitPayment([
        ['payment_method' => 'cash', 'amount' => 3500],
    ]);

    $component
        ->mountAction(TestAction::make('makePayment')->schemaComponent())
        ->set('mountedActions.0.data.amount', 2000);

    $rows = array_values($component->get('mountedActions.0.data.splits'));
    expect($rows)->toMatchArray([['payment_method' => 'cash', 'amount' => 2000.0, 'currency' => 'GEL', 'exchange_rate' => null, 'amount_manually_overridden' => null]])
        ->and(app(PaymentProcessor::class)->distributedAmount($rows))->toBe(2000.0)
        ->and(app(PaymentProcessor::class)->remaining(2000, $rows))->toBe(0.0);

    $component->call('submitPayment', [
        'amount' => 2000,
        'currency' => 'GEL',
        'splits' => $rows,
    ]);

    expect($component->get('pendingPayment.amount'))->toBe(2000.0)
        ->and($component->instance()->getStagedPaidAmount())->toBe(2000.0)
        ->and($component->instance()->getCurrentRemainingAmount())->toBe(1500.0)
        ->and(Payment::query()->count())->toBe(0);
});

test('changing top amount never redistributes existing split rows and validates over distribution', function () {
    $this->actingAs(User::factory()->create());
    [$component] = stagedCreateVisitPayment([
        ['payment_method' => 'cash', 'amount' => 2000],
        ['payment_method' => 'card', 'amount' => 1500],
    ]);

    $component
        ->mountAction(TestAction::make('makePayment')->schemaComponent())
        ->set('mountedActions.0.data.amount', 4000);
    $underDistributedRows = array_values($component->get('mountedActions.0.data.splits'));
    expect($underDistributedRows)->toMatchArray([
        ['payment_method' => 'cash', 'amount' => 2000, 'currency' => 'GEL', 'exchange_rate' => null, 'amount_manually_overridden' => null],
        ['payment_method' => 'card', 'amount' => 1500, 'currency' => 'GEL', 'exchange_rate' => null, 'amount_manually_overridden' => null],
    ])->and(app(PaymentProcessor::class)->remaining(4000, $underDistributedRows))->toBe(500.0);

    $component
        ->set('mountedActions.0.data.amount', 3000)
        ->callMountedAction()
        ->assertHasActionErrors(['splits']);

    $overDistributedRows = array_values($component->get('mountedActions.0.data.splits'));
    expect($overDistributedRows)->toBe($underDistributedRows)
        ->and(app(PaymentProcessor::class)->distributedAmount($overDistributedRows))->toBe(3500.0)
        ->and((float) $component->get('pendingPayment.amount'))->toBe(3500.0)
        ->and(Payment::query()->count())->toBe(0);
});

test('unsaved visit recalculates staged remaining and blocks overpayment after total changes', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Reactive', 'last_name' => 'Stage']);
    $treatment = TreatmentCase::create(['name' => 'Reactive staged work', 'category' => 'therapy', 'is_active' => true]);
    $component = Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $patient->getKey(),
        'visit_type' => 'treatment',
        'treatmentCaseItems' => [[
            'service_choice' => (string) $treatment->getKey(),
            'treatment_case_id' => $treatment->getKey(),
            'quantity' => 1,
            'unit_price' => 6000,
        ]],
    ])->call('submitPayment', [
        'amount' => 3000,
        'currency' => 'GEL',
        'splits' => [['payment_method' => 'cash', 'amount' => 3000]],
    ]);

    $component->set('data.treatmentCaseItems.0.unit_price', 4000);
    expect($component->instance()->getCurrentRemainingAmount())->toBe(1000.0);

    $component->set('data.discount_value', 1000);
    expect($component->instance()->getCurrentRemainingAmount())->toBe(0.0);

    $component->set('data.discount_value', 2500);
    expect($component->instance()->stagedPaymentExceedsPayable())->toBeTrue();

    $component->call('create')->assertHasErrors(['pendingPayment']);
    expect(Visit::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});

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

test('new visit stages and atomically persists supported payment combinations', function (array $splits) {
    $this->actingAs(User::factory()->create());
    [$component, $patient, $treatment] = stagedCreateVisitPayment($splits);

    expect(Visit::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and($component->get('pendingPayment'))->not->toBeNull();

    $component->call('create')->assertHasNoErrors();

    $visit = Visit::query()->with('treatmentCaseItems', 'payments.splits', 'payments.cashboxTransactions')->sole();
    $payment = $visit->payments->sole();
    $cashAmount = collect($splits)
        ->where('payment_method', PaymentMethod::Cash->value)
        ->sum(fn (array $split): float => (float) $split['amount']);

    expect($visit->patient_id)->toBe($patient->getKey())
        ->and($visit->treatmentCaseItems)->toHaveCount(1)
        ->and($visit->treatmentCaseItems->sole()->treatment_case_id)->toBe($treatment->getKey())
        ->and((float) $visit->total_price)->toBe(3500.0)
        ->and($visit->remaining_amount)->toBe(0.0)
        ->and((float) $payment->amount)->toBe(3500.0)
        ->and($payment->visit_id)->toBe($visit->getKey())
        ->and((float) $payment->splits->sum('amount'))->toBe(3500.0)
        ->and($visit->payments()->count())->toBe(1)
        ->and($payment->cashboxTransactions)->toHaveCount(count($splits))
        ->and((float) $payment->cashboxTransactions->where('payment_method', 'cash')->sum('amount'))->toBe((float) $cashAmount)
        ->and($payment->cashboxTransactions->every(fn ($transaction): bool => $transaction->visit_id === $visit->getKey()))->toBeTrue()
        ->and(Visit::query()->count())->toBe(1);
})->with([
    'cash' => [[['payment_method' => 'cash', 'amount' => 3500]]],
    'card' => [[['payment_method' => 'card', 'amount' => 3500]]],
    'bank transfer' => [[['payment_method' => 'bank_transfer', 'amount' => 3500]]],
    'cash and card' => [[
        ['payment_method' => 'cash', 'amount' => 1500],
        ['payment_method' => 'card', 'amount' => 2000],
    ]],
    'cash and bank transfer' => [[
        ['payment_method' => 'cash', 'amount' => 1500],
        ['payment_method' => 'bank_transfer', 'amount' => 2000],
    ]],
]);

test('new visit validation failure does not persist staged payment or a duplicate visit', function () {
    $this->actingAs(User::factory()->create());
    [$component, $patient] = stagedCreateVisitPayment([
        ['payment_method' => 'cash', 'amount' => 3500],
    ]);
    $patient->delete();

    $component->call('create')->assertHasErrors();

    expect(Visit::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(PaymentSplit::query()->count())->toBe(0);
});

test('new visit and payment roll back together when cashier posting fails', function () {
    $this->actingAs(User::factory()->create());
    [$component] = stagedCreateVisitPayment([
        ['payment_method' => 'cash', 'amount' => 3500],
    ]);
    $cashbox = Mockery::mock(CashboxManager::class);
    $cashbox->shouldReceive('syncPayment')->andThrow(new RuntimeException('Cashier unavailable'));
    app()->instance(CashboxManager::class, $cashbox);

    expect(fn () => $component->call('create'))->toThrow(RuntimeException::class)
        ->and(Visit::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0)
        ->and(PaymentSplit::query()->count())->toBe(0);
});

test('the shared processor supports every payment method and a three way split', function () {
    $visit = createVisitForPaymentTest(['total_price' => 3250]);

    $payment = app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(),
        'amount' => 3250,
        'currency' => 'GEL',
        'payment_date' => now()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 1000],
        ['payment_method' => 'card', 'amount' => 1000],
        ['payment_method' => 'bank_transfer', 'amount' => 1250],
    ]);

    expect($payment->splits)->toHaveCount(3)
        ->and($payment->splits->pluck('payment_method')->sort()->values()->all())->toBe(['bank_transfer', 'card', 'cash'])
        ->and((float) $payment->splits->sum('amount'))->toBe(3250.0)
        ->and($payment->cashboxTransaction)->not->toBeNull()
        ->and($visit->refresh()->paid_amount)->toBe(3250.0)
        ->and($visit->remaining_amount)->toBe(0.0);
});

test('payment processor owns shared form calculations and canonical method options', function () {
    $processor = app(PaymentProcessor::class);
    $rows = [
        ['payment_method' => 'cash', 'amount' => '10.10'],
        ['payment_method' => 'bank_transfer', 'amount' => '20.20'],
    ];

    expect(PaymentMethod::options())->toHaveKeys(['cash', 'card', 'bank_transfer'])
        ->and($processor->distributedMinorUnits($rows))->toBe(3030)
        ->and($processor->distributedAmount($rows))->toBe(30.3)
        ->and($processor->remaining('50.30', $rows))->toBe(20.0)
        ->and($processor->amountDue('100.10', '49.80'))->toBe(50.3)
        ->and((new ReflectionClass(Payment::class))->hasConstant('METHODS'))->toBeFalse()
        ->and((new ReflectionClass(Payment::class))->hasConstant('METHOD_LABELS'))->toBeFalse();
});

test('processor synchronizes cashier once after the complete split state is saved', function () {
    $visit = createVisitForPaymentTest(['total_price' => 500]);
    $cashbox = Mockery::mock(CashboxManager::class);
    $cashbox->shouldReceive('syncPayment')->once()->with(Mockery::type(Payment::class));
    app()->instance(CashboxManager::class, $cashbox);

    $payment = app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(), 'amount' => 500, 'payment_date' => today()->toDateString(),
    ], [
        ['payment_method' => 'cash', 'amount' => 200],
        ['payment_method' => 'card', 'amount' => 300],
    ]);

    expect($payment->splits)->toHaveCount(2);
});

test('split replacement uses processor validation and synchronizes cashier once', function () {
    $visit = createVisitForPaymentTest(['total_price' => 500]);
    $payment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 500, 'payment_date' => today()->toDateString(),
    ], [['payment_method' => 'cash', 'amount' => 500]]);
    $cashbox = Mockery::mock(CashboxManager::class);
    $cashbox->shouldReceive('syncPayment')->once()->with(Mockery::type(Payment::class));
    app()->instance(CashboxManager::class, $cashbox);

    $payment->replaceSplits([
        ['payment_method' => 'cash', 'amount' => 200],
        ['payment_method' => 'bank_transfer', 'amount' => 300],
    ]);

    expect($payment->refresh()->splits()->pluck('payment_method')->sort()->values()->all())
        ->toBe(['bank_transfer', 'cash'])
        ->and(fn () => $payment->replaceSplits([['payment_method' => 'cash', 'amount' => 499]]))
        ->toThrow(ValidationException::class);
});

test('the shared processor allows partial payment and rejects overpayment', function () {
    $visit = createVisitForPaymentTest(['total_price' => 1000]);

    app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(),
        'amount' => 400,
        'payment_date' => now()->toDateString(),
    ], [['payment_method' => 'cash', 'amount' => 400]]);

    expect($visit->refresh()->remaining_amount)->toBe(600.0)
        ->and(fn () => app(PaymentProcessor::class)->process([
            'visit_id' => $visit->getKey(),
            'amount' => 601,
            'payment_date' => now()->toDateString(),
        ], [['payment_method' => 'card', 'amount' => 601]]))
        ->toThrow(ValidationException::class)
        ->and($visit->payments()->count())->toBe(1);
});

test('the shared processor accepts payment when visit has no doctor', function () {
    $visit = createVisitForPaymentTest(['doctor_id' => null, 'total_price' => 100]);

    $payment = app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(),
        'amount' => 100,
        'payment_date' => now()->toDateString(),
    ], [['payment_method' => 'bank_transfer', 'amount' => 100]]);

    expect($payment->cashboxTransaction)->not->toBeNull()
        ->and($payment->cashboxTransaction->payment_method)->toBe('bank_transfer')
        ->and($visit->refresh()->remaining_amount)->toBe(0.0);
});

test('the shared processor rejects an unsupported method without partial records', function () {
    $visit = createVisitForPaymentTest();

    expect(fn () => app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(),
        'amount' => 100,
        'payment_date' => now()->toDateString(),
    ], [['payment_method' => 'crypto', 'amount' => 100]]))->toThrow(ValidationException::class)
        ->and($visit->payments()->count())->toBe(0)
        ->and(PaymentSplit::query()->count())->toBe(0);
});

test('the shared processor rolls payment back when cashier posting fails', function () {
    $visit = createVisitForPaymentTest();
    $cashbox = Mockery::mock(CashboxManager::class);
    $cashbox->shouldReceive('syncPayment')->andThrow(new RuntimeException('Cashier unavailable'));
    app()->instance(CashboxManager::class, $cashbox);

    expect(fn () => app(PaymentProcessor::class)->process([
        'visit_id' => $visit->getKey(),
        'amount' => 100,
        'payment_date' => now()->toDateString(),
    ], [['payment_method' => 'cash', 'amount' => 100]]))->toThrow(RuntimeException::class)
        ->and($visit->payments()->count())->toBe(0)
        ->and(PaymentSplit::query()->count())->toBe(0);
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

test('the visit payment modal confirms cash and bank transfer split', function () {
    $this->actingAs(User::factory()->create());
    $visit = createVisitForPaymentTest(['total_price' => 3000]);
    $treatment = TreatmentCase::create([
        'name' => 'Bank transfer split treatment',
        'category' => 'therapy',
        'is_active' => true,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 1,
        'unit_price' => 3000,
    ]);

    Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()])
        ->callAction(TestAction::make('makePayment')->schemaComponent(), [
            'amount' => '3000.00',
            'currency' => 'GEL',
            'splits' => [
                ['payment_method' => 'cash', 'amount' => '1500.00'],
                ['payment_method' => 'bank_transfer', 'amount' => '1500.00'],
            ],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('გადახდა წარმატებით დაემატა.');

    $payment = $visit->payments()->with('splits', 'cashboxTransaction')->sole();

    expect((float) $payment->amount)->toBe(3000.0)
        ->and((float) $payment->splits->where('payment_method', 'cash')->sole()->amount)->toBe(1500.0)
        ->and((float) $payment->splits->where('payment_method', 'bank_transfer')->sole()->amount)->toBe(1500.0)
        ->and($payment->cashboxTransaction)->not->toBeNull()
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
        'discount_reason' => 'management',
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
