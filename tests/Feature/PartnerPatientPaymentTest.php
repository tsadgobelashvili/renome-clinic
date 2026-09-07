<?php

use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Filament\Resources\PartnerPatients\Pages\ViewPartnerPatient;
use App\Filament\Resources\PartnerPatients\RelationManagers\PartnerPaymentsRelationManager;
use App\Models\CashboxTransaction;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\User;
use App\Services\IsraeliPatientPaymentService;
use App\Services\PartnerFinanceSummary;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('partner payment records isolated gel and usd totals', function () {
    $patient = Patient::create([
        'first_name' => 'Paid',
        'last_name' => 'Partner',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    $gelPayment = $patient->partnerPayments()->create([
        'amount' => 150,
        'currency' => 'GEL',
        'payment_method' => 'cash',
        'paid_at' => '2026-08-27',
        'notes' => 'First payment',
    ]);
    $usdPayment = $patient->partnerPayments()->create([
        'amount' => 75,
        'currency' => 'USD',
        'payment_method' => 'bank_transfer',
        'paid_at' => '2026-08-27',
    ]);

    expect($patient->getPartnerPaymentTotals())->toBe(['GEL' => 150.0, 'USD' => 75.0])
        ->and($gelPayment->patient->is($patient))->toBeTrue()
        ->and($usdPayment->payment_method_label)->toBe('გადარიცხვა')
        ->and(Payment::query()->count())->toBe(0)
        ->and(CashboxTransaction::query()->count())->toBe(0);
});

test('clinic patient cannot receive a partner payment', function () {
    $patient = Patient::create([
        'first_name' => 'Clinic',
        'last_name' => 'Only',
        'phone' => '555111222',
    ]);

    expect(fn () => $patient->partnerPayments()->create([
        'amount' => 100,
        'currency' => 'GEL',
        'payment_method' => 'card',
        'paid_at' => today(),
    ]))->toThrow(ValidationException::class)
        ->and(PartnerPatientPayment::query()->count())->toBe(0);
});

test('partner profile shows payment history without a duplicate payment action', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create([
        'first_name' => 'Modal',
        'last_name' => 'Partner',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    app(IsraeliPatientPaymentService::class)->create([
        'patient_id' => $patient->getKey(),
        'amount' => 225.50,
        'currency' => 'USD',
        'payment_method' => 'card',
        'paid_at' => '2026-08-27',
        'notes' => 'Profile payment',
    ]);

    Livewire::test(PartnerPaymentsRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => ViewPartnerPatient::class,
    ])
        ->assertSuccessful()
        ->assertActionDoesNotExist(TestAction::make('create')->table())
        ->assertCanSeeTableRecords($patient->partnerPayments()->get())
        ->assertSee('$225.50')
        ->assertSee('ბარათი')
        ->assertSee('Profile payment');

    Livewire::test(ViewPartnerPatient::class, ['record' => $patient->getRouteKey()])
        ->assertSee('$225.50');

    expect(PartnerPatientPayment::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(0)
        ->and(CashboxTransaction::query()->count())->toBe(0)
        ->and($patient->visits()->count())->toBe(0);
});

test('quick Israeli payment uses a selected existing patient without creating another patient', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create([
        'first_name' => 'Existing',
        'last_name' => 'Israeli',
        'birth_date' => '1990-05-12',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    Livewire::test(ListPartnerFinance::class)
        ->assertActionExists(TestAction::make('addPatientPayment'))
        ->callAction(TestAction::make('addPatientPayment'), [
            'patient_id' => $patient->getKey(),
            'amount' => 250,
            'currency' => 'USD',
            'payment_method' => 'cash',
            'paid_at' => '2026-09-04',
        ])
        ->assertHasNoActionErrors();

    expect(Patient::query()->count())->toBe(1)
        ->and($patient->partnerPayments()->sole()->amount)->toBe('250.00');
});

test('quick Israeli payment creates a patient automatically and safely reuses an exact name and birth date match', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $component = Livewire::test(ListPartnerFinance::class);
    $payment = [
        'first_name' => '  New ',
        'last_name' => ' Patient  ',
        'birth_date' => '1988-07-09',
        'amount' => 100,
        'currency' => 'GEL',
        'payment_method' => 'card',
        'paid_at' => '2026-09-04',
    ];

    $component->callAction(TestAction::make('addPatientPayment'), $payment)->assertHasNoActionErrors();
    $component->callAction(TestAction::make('addPatientPayment'), [
        ...$payment,
        'first_name' => 'new',
        'last_name' => 'patient',
        'amount' => 75,
    ])->assertHasNoActionErrors();

    $patient = Patient::query()->sole();
    expect($patient->first_name)->toBe('New')
        ->and($patient->last_name)->toBe('Patient')
        ->and($patient->isIsraelPartner())->toBeTrue()
        ->and($patient->partnerPayments()->count())->toBe(2)
        ->and((float) $patient->partnerPayments()->sum('amount'))->toBe(175.0);
});

test('quick Israeli payment allows the same name with a different birth date', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    Patient::create([
        'first_name' => 'Same',
        'last_name' => 'Name',
        'birth_date' => '1980-01-01',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    Livewire::test(ListPartnerFinance::class)
        ->callAction(TestAction::make('addPatientPayment'), [
            'first_name' => 'Same',
            'last_name' => 'Name',
            'birth_date' => '1981-01-01',
            'amount' => 50,
            'currency' => 'USD',
            'payment_method' => 'bank_transfer',
            'paid_at' => '2026-09-04',
        ])
        ->assertHasNoActionErrors();

    expect(Patient::query()->count())->toBe(2)
        ->and(Patient::query()->whereDate('birth_date', '1981-01-01')->sole()->partnerPayments()->count())->toBe(1);
});

test('Israeli patient autocomplete searches first and last names without including clinic patients', function () {
    $israeli = Patient::create([
        'first_name' => 'Maya', 'last_name' => 'SurnameTarget', 'birth_date' => '1985-09-04',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    Patient::create([
        'first_name' => 'Clinic', 'last_name' => 'SurnameTarget', 'phone' => '555111222',
    ]);
    $service = app(IsraeliPatientPaymentService::class);

    expect($service->suggestions('Maya'))->toHaveCount(1)
        ->and($service->suggestions('SurnameTarget'))->toHaveCount(1)
        ->and($service->suggestions('SurnameTarget')[0])->toContain('Maya Surnametarget')
        ->toContain('04.09.1985')
        ->toContain('[ID:'.$israeli->getKey().']');
});

test('manual birth date is optional and valid text is normalized', function () {
    $service = app(IsraeliPatientPaymentService::class);

    expect($service->parseBirthDate(null))->toBeNull()
        ->and($service->parseBirthDate(''))->toBeNull()
        ->and($service->parseBirthDate('04.09.1985'))->toBe('1985-09-04');
});

test('new Israeli patient and payment can be created without a birth date', function () {
    $payment = app(IsraeliPatientPaymentService::class)->create([
        'first_name' => 'Optional', 'last_name' => 'Birthdate', 'birth_date' => '',
        'amount' => 80, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => today(),
    ]);

    expect($payment->patient->birth_date)->toBeNull()
        ->and($payment->patient->isIsraelPartner())->toBeTrue()
        ->and(PartnerPatientPayment::query()->count())->toBe(1);
});

test('invalid manual birth date returns a validation error', function () {
    expect(fn () => app(IsraeliPatientPaymentService::class)->parseBirthDate('31.02.1985'))
        ->toThrow(ValidationException::class);
});

test('blank birth date with an existing same-name Israeli patient requires explicit selection', function () {
    Patient::create([
        'first_name' => 'Existing', 'last_name' => 'NoBirthDate',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    expect(fn () => app(IsraeliPatientPaymentService::class)->create([
        'first_name' => 'existing', 'last_name' => 'nobirthdate', 'birth_date' => '',
        'amount' => 50, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => today(),
    ]))->toThrow(ValidationException::class)
        ->and(Patient::query()->count())->toBe(1)
        ->and(PartnerPatientPayment::query()->count())->toBe(0);
});

test('Israeli Finance payment posts once and updates finance totals once', function () {
    $patient = Patient::create([
        'first_name' => 'Finance', 'last_name' => 'Payment',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    app(IsraeliPatientPaymentService::class)->create([
        'patient_id' => $patient->getKey(), 'amount' => 125, 'currency' => 'USD',
        'payment_method' => 'cash', 'paid_at' => today(), 'notes' => 'Single posting',
    ]);

    expect(PartnerPatientPayment::query()->count())->toBe(1)
        ->and(PartnerFinanceEntry::query()->where('transaction_type', 'payment')->count())->toBe(1)
        ->and($patient->partnerPayments()->count())->toBe(1)
        ->and(app(PartnerFinanceSummary::class)->receivedTotals())->toBe(['GEL' => 0.0, 'USD' => 125.0])
        ->and(app(PartnerFinanceSummary::class)->currentCashTotals())->toBe(['GEL' => 0.0, 'USD' => 125.0]);
});

test('new Israeli patient creation rolls back when payment creation fails', function () {
    expect(fn () => app(IsraeliPatientPaymentService::class)->create([
        'first_name' => 'Rollback', 'last_name' => 'Patient', 'birth_date' => '04.09.1985',
        'amount' => 100, 'currency' => 'USD', 'payment_method' => 'invalid', 'paid_at' => today(),
    ]))->toThrow(ValidationException::class)
        ->and(Patient::query()->count())->toBe(0)
        ->and(PartnerPatientPayment::query()->count())->toBe(0);
});
