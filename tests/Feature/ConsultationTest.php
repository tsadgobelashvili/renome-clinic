<?php

use App\Filament\Pages\Cashbox;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('tomography catalog services are available once with their default prices', function () {
    expect(TreatmentCase::query()->where('category', 'tomography')->where('name', '3D CT')->count())->toBe(1)
        ->and((float) TreatmentCase::query()->where('name', '3D CT')->sole()->default_price)->toBe(60.0)
        ->and(TreatmentCase::query()->where('category', 'tomography')->where('name', 'პანორამა')->count())->toBe(1)
        ->and((float) TreatmentCase::query()->where('name', 'პანორამა')->sole()->default_price)->toBe(40.0)
        ->and(TreatmentCase::CATEGORIES['tomography'])->toBe('ტომოგრაფია');
});

test('consultation form renders compact source tomography and payment controls', function () {
    $this->actingAs(User::factory()->create());

    $page = Livewire::test(CreateVisit::class)
        ->fillForm(['visit_type' => 'consultation'])
        ->assertSuccessful()
        ->assertDontSee('წყარო')
        ->assertSee('+ 3D CT')
        ->assertSee('გადახდა');

    expect($page->instance()->form->getRawState()['consultation_source'] ?? null)
        ->toBe('our_patient');
});

test('compact tomography action updates consultation total and saves its relationship', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Modal', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Modal', 'last_name' => 'Doctor', 'is_active' => true]);
    $ct = TreatmentCase::query()->where('name', '3D CT')->sole();

    $page = Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_type' => 'consultation',
            'consultation_fee' => 0,
        ])
        ->callAction(TestAction::make('manageTomography')->schemaComponent(), [
            'consultation_source' => 'other_clinic',
            'tomographyItems' => [[
                'treatment_case_id' => $ct->getKey(),
                'quantity' => 2,
                'unit_price' => 60,
            ]],
        ])
        ->assertHasNoActionErrors();

    $state = $page->instance()->form->getRawState();

    expect($state['consultation_source'])->toBe('other_clinic')
        ->and((float) $state['total_price'])->toBe(120.0)
        ->and((float) collect($state['treatmentCaseItems'])->first()['quantity'])->toBe(2.0);

    $page->call('create')->assertHasNoErrors();

    $visit = Visit::query()->sole();

    expect((float) $visit->total_price)->toBe(120.0)
        ->and($visit->consultation_source)->toBe('other_clinic')
        ->and($visit->treatmentCaseItems()->count())->toBe(1)
        ->and($visit->treatmentCaseItems()->sole()->quantity)->toBe(2);
});

test('compact tomography action removes an existing service without changing consultation flow', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Remove', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Remove', 'last_name' => 'Doctor', 'is_active' => true]);
    $ct = TreatmentCase::query()->where('name', '3D CT')->sole();
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'visit_type' => 'consultation',
        'consultation_fee' => 0,
        'currency' => 'GEL',
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $ct->getKey(),
        'quantity' => 1,
        'unit_price' => 60,
    ]);
    $visit->syncTreatmentItemsTotal();

    Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()])
        ->callAction(TestAction::make('manageTomography')->schemaComponent(), [
            'consultation_source' => 'our_patient',
            'tomographyItems' => [],
        ])
        ->assertHasNoActionErrors()
        ->call('save')
        ->assertHasNoErrors();

    expect($visit->refresh()->treatmentCaseItems()->count())->toBe(0)
        ->and((float) $visit->total_price)->toBe(0.0);
});

test('compact tomography action saves split payment and cashier entry in one flow', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Split', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Split', 'last_name' => 'Doctor', 'is_active' => true]);
    $ct = TreatmentCase::query()->where('name', '3D CT')->sole();

    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_type' => 'consultation',
            'consultation_fee' => 0,
        ])
        ->callAction(TestAction::make('manageTomography')->schemaComponent(), [
            'consultation_source' => 'other_clinic',
            'tomographyItems' => [[
                'treatment_case_id' => $ct->getKey(),
                'quantity' => 2,
                'unit_price' => 60,
            ]],
            'paymentSplits' => [
                ['payment_method' => 'cash', 'amount' => 70],
                ['payment_method' => 'card', 'amount' => 50],
            ],
        ])
        ->assertHasNoActionErrors();

    $visit = Visit::query()->sole();
    $payment = $visit->payments()->with('splits', 'cashboxTransaction')->sole();

    expect((float) $visit->total_price)->toBe(120.0)
        ->and($visit->remaining_amount)->toBe(0.0)
        ->and((float) $payment->amount)->toBe(120.0)
        ->and((float) $payment->splits->where('payment_method', 'cash')->sole()->amount)->toBe(70.0)
        ->and((float) $payment->splits->where('payment_method', 'card')->sole()->amount)->toBe(50.0)
        ->and($payment->cashboxTransaction)->not->toBeNull();
});

test('tomography accepts a rounded USD payment and posts its native value to cashier', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'USD', 'last_name' => 'Tomography']);
    $ct = TreatmentCase::query()->where('name', '3D CT')->sole();

    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'visit_type' => 'consultation',
            'consultation_fee' => 0,
            'currency' => 'GEL',
        ])
        ->callAction(TestAction::make('manageTomography')->schemaComponent(), [
            'consultation_source' => 'our_patient',
            'amount' => 120,
            'currency' => 'GEL',
            'tomographyItems' => [[
                'treatment_case_id' => $ct->getKey(),
                'quantity' => 2,
                'unit_price' => 60,
            ]],
            'paymentSplits' => [[
                'payment_method' => 'cash',
                'amount' => 45.88,
                'currency' => 'USD',
                'exchange_rate' => 2.6153,
            ]],
        ])
        ->assertHasNoActionErrors();

    $visit = Visit::query()->sole();
    $payment = $visit->payments()->with('splits')->sole();
    $split = $payment->splits->sole();
    $cashier = CashboxTransaction::query()->where('payment_split_id', $split->getKey())->sole();

    expect($visit->remaining_amount)->toBe(0.0)
        ->and((float) $split->amount)->toBe(45.88)
        ->and($split->currency)->toBe('USD')
        ->and((float) $split->exchange_rate)->toBe(2.6153)
        ->and((float) $cashier->amount)->toBe(45.88)
        ->and($cashier->currency)->toBe('USD');
});

test('tomography accepts a rounded GEL and USD split payment', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Mixed', 'last_name' => 'Tomography']);
    $ct = TreatmentCase::query()->where('name', '3D CT')->sole();

    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'visit_type' => 'consultation',
            'consultation_fee' => 0,
            'currency' => 'GEL',
        ])
        ->callAction(TestAction::make('manageTomography')->schemaComponent(), [
            'consultation_source' => 'our_patient',
            'amount' => 120,
            'currency' => 'GEL',
            'tomographyItems' => [[
                'treatment_case_id' => $ct->getKey(),
                'quantity' => 2,
                'unit_price' => 60,
            ]],
            'paymentSplits' => [
                ['payment_method' => 'cash', 'amount' => 50, 'currency' => 'GEL'],
                ['payment_method' => 'card', 'amount' => 26.77, 'currency' => 'USD', 'exchange_rate' => 2.6153],
            ],
        ])
        ->assertHasNoActionErrors();

    $visit = Visit::query()->sole();
    $payment = $visit->payments()->with('splits')->sole();

    expect($visit->remaining_amount)->toBe(0.0)
        ->and($payment->splits->pluck('amount', 'currency')->map(fn ($amount): float => (float) $amount)->sortKeys()->all())
        ->toBe(['GEL' => 50.0, 'USD' => 26.77])
        ->and(CashboxTransaction::query()->where('payment_id', $payment->getKey())->count())->toBe(2);
});

test('tomography USD row recalculates from the remaining GEL balance without changing the GEL row', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Reactive', 'last_name' => 'Tomography']);

    $component = Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'visit_type' => 'consultation',
            'currency' => 'GEL',
        ])
        ->mountAction(TestAction::make('manageTomography')->schemaComponent());

    $component
        ->set('mountedActions.0.data.amount', 120)
        ->set('mountedActions.0.data.currency', 'GEL')
        ->set('mountedActions.0.data.paymentSplits', [
            ['payment_method' => 'cash', 'amount' => 50, 'currency' => 'GEL'],
            [
                'payment_method' => 'card', 'amount' => null, 'currency' => 'USD',
                'exchange_rate' => null, 'amount_manually_overridden' => false,
            ],
        ])
        ->set('mountedActions.0.data.paymentSplits.1.exchange_rate', 2.6153);

    expect((float) $component->get('mountedActions.0.data.paymentSplits.0.amount'))->toBe(50.0)
        ->and($component->get('mountedActions.0.data.paymentSplits.0.currency'))->toBe('GEL')
        ->and((float) $component->get('mountedActions.0.data.paymentSplits.1.amount'))->toBe(26.77)
        ->and($component->get('mountedActions.0.data.paymentSplits.1.currency'))->toBe('USD');
});

test('tomography payment shows a visible error when patient is missing', function () {
    $this->actingAs(User::factory()->create());
    $ct = TreatmentCase::query()->where('name', '3D CT')->sole();

    Livewire::test(CreateVisit::class)
        ->fillForm(['visit_type' => 'consultation'])
        ->callAction(TestAction::make('manageTomography')->schemaComponent(), [
            'consultation_source' => 'our_patient',
            'tomographyItems' => [[
                'treatment_case_id' => $ct->getKey(),
                'quantity' => 2,
                'unit_price' => 60,
            ]],
            'paymentSplits' => [
                ['payment_method' => 'cash', 'amount' => 120],
            ],
        ])
        ->assertNotified('გადახდა ვერ დასრულდა');

    expect(Visit::query()->count())->toBe(0)
        ->and(Payment::query()->count())->toBe(0);
});

test('consultation and payment can be created without a doctor', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Optional', 'last_name' => 'Doctor']);
    $ct = TreatmentCase::query()->where('name', '3D CT')->sole();

    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => null,
            'visit_type' => 'consultation',
        ])
        ->callAction(TestAction::make('manageTomography')->schemaComponent(), [
            'consultation_source' => 'our_patient',
            'tomographyItems' => [[
                'treatment_case_id' => $ct->getKey(),
                'quantity' => 1,
                'unit_price' => 60,
            ]],
            'paymentSplits' => [
                ['payment_method' => 'cash', 'amount' => 60],
            ],
        ])
        ->assertHasNoActionErrors();

    $visit = Visit::query()->sole();

    expect($visit->doctor_id)->toBeNull()
        ->and((float) $visit->payments()->sole()->amount)->toBe(60.0)
        ->and($visit->payments()->sole()->cashboxTransaction()->exists())->toBeTrue();

    Livewire::test(Cashbox::class)->assertSuccessful();
});

test('consultation payment modal submits when distributed amount exactly matches amount due', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Payment', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Payment', 'last_name' => 'Doctor', 'is_active' => true]);
    $ct = TreatmentCase::query()->where('name', '3D CT')->sole();
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'visit_type' => 'consultation',
        'currency' => 'GEL',
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $ct->getKey(),
        'quantity' => 2,
        'unit_price' => 60,
    ]);
    $visit->syncTreatmentItemsTotal();

    Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()])
        ->callAction(TestAction::make('makePayment')->schemaComponent(), [
            'amount' => '120.00',
            'currency' => 'GEL',
            'splits' => [
                ['payment_method' => 'cash', 'amount' => '70.00'],
                ['payment_method' => 'card', 'amount' => '50.00'],
            ],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('გადახდა წარმატებით დაემატა.');

    expect((float) $visit->payments()->sole()->amount)->toBe(120.0)
        ->and($visit->refresh()->remaining_amount)->toBe(0.0);
});

test('consultation source tomography quantities fee payment and cashbox stay connected', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'CT', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'CT', 'last_name' => 'Doctor', 'is_active' => true]);
    $ct = TreatmentCase::query()->where('name', '3D CT')->sole();

    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'visit_type' => 'consultation',
        'consultation_source' => 'other_clinic',
        'consultation_fee' => 50,
        'currency' => 'GEL',
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $ct->getKey(),
        'quantity' => 2,
        'unit_price' => 60,
    ]);
    $visit->syncTreatmentItemsTotal();

    $payment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(),
        'amount' => 170,
        'currency' => 'GEL',
        'payment_date' => today(),
    ], [
        ['payment_method' => 'cash', 'amount' => 70],
        ['payment_method' => 'card', 'amount' => 100],
    ]);

    expect((float) $visit->fresh()->total_price)->toBe(170.0)
        ->and($visit->consultation_source)->toBe('other_clinic')
        ->and($visit->treatmentCaseItems()->sum('quantity'))->toBe(2)
        ->and((float) $payment->splits()->where('payment_method', 'cash')->sum('amount'))->toBe(70.0)
        ->and((float) $payment->splits()->where('payment_method', 'card')->sum('amount'))->toBe(100.0)
        ->and($visit->refresh()->remaining_amount)->toBe(0.0);

    Livewire::test(Cashbox::class)
        ->assertSuccessful()
        ->assertSee('კონსულტაცია / ტომოგრაფია')
        ->assertSee('70.00 ₾')
        ->assertSee('100.00 ₾')
        ->assertDontSee('170.00 ₾');
});

test('consultations default to our patient source and reject unsupported sources', function () {
    $patient = Patient::create(['first_name' => 'Source', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Source', 'last_name' => 'Doctor', 'is_active' => true]);

    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'visit_type' => 'consultation',
    ]);

    expect($visit->consultation_source)->toBe('our_patient')
        ->and(fn () => $visit->update(['consultation_source' => 'unsupported']))
        ->toThrow(ValidationException::class);
});

test('consultation and treatment visit CT use one catalog identity statistics and payment flow', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Unified', 'last_name' => 'CT']);
    $ct = TreatmentCase::query()->where('category', 'tomography')->where('name', '3D CT')->sole();

    $consultationPage = Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'visit_type' => 'consultation',
            'consultation_fee' => 0,
        ])
        ->callAction(TestAction::make('manageTomography')->schemaComponent(), [
            'consultation_source' => 'our_patient',
            'tomographyItems' => [[
                'treatment_case_id' => $ct->getKey(),
                'quantity' => 2,
                'unit_price' => 60,
            ]],
        ])
        ->assertHasNoActionErrors();
    $consultationPage->call('create')->assertHasNoErrors();

    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'visit_type' => 'treatment',
            'currency' => 'GEL',
            'treatmentCaseItems' => [[
                'service_choice' => (string) $ct->getKey(),
                'treatment_case_id' => $ct->getKey(),
                'quantity' => 3,
                'unit_price' => 60,
            ]],
        ])
        ->callAction(TestAction::make('makePayment')->schemaComponent(), [
            'amount' => 180,
            'currency' => 'GEL',
            'splits' => [
                ['payment_method' => 'cash', 'amount' => 80],
                ['payment_method' => 'card', 'amount' => 100],
            ],
        ])
        ->assertHasNoActionErrors()
        ->call('create')
        ->assertHasNoErrors();

    $visits = Visit::query()->orderBy('id')->get();
    $treatmentVisit = $visits->firstWhere('visit_type', 'treatment');

    expect($visits)->toHaveCount(2)
        ->and($visits->pluck('patient_id')->unique()->all())->toBe([$patient->getKey()])
        ->and($ct->visitItems()->count())->toBe(2)
        ->and((int) $ct->visitItems()->sum('quantity'))->toBe(5)
        ->and((float) $treatmentVisit->total_price)->toBe(180.0)
        ->and($treatmentVisit->remaining_amount)->toBe(0.0)
        ->and($treatmentVisit->payments()->count())->toBe(1)
        ->and($treatmentVisit->payments()->sole()->splits()->count())->toBe(2)
        ->and(CashboxTransaction::query()->where('visit_id', $treatmentVisit->getKey())->count())->toBe(2)
        ->and((float) CashboxTransaction::query()->where('visit_id', $treatmentVisit->getKey())
            ->where('payment_method', 'cash')->sum('amount'))->toBe(80.0)
        ->and((float) CashboxTransaction::query()->where('visit_id', $treatmentVisit->getKey())
            ->where('payment_method', 'card')->sum('amount'))->toBe(100.0);
});
