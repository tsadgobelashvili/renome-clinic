<?php

use App\Filament\Resources\PartnerPatients\Pages\ViewPartnerPatient;
use App\Filament\Resources\PartnerPatients\RelationManagers\PartnerPaymentsRelationManager;
use App\Models\CashboxTransaction;
use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\User;
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

test('partner profile payment action creates history without clinic or cashbox side effects', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create([
        'first_name' => 'Modal',
        'last_name' => 'Partner',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    Livewire::test(PartnerPaymentsRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => ViewPartnerPatient::class,
    ])
        ->assertSuccessful()
        ->assertActionExists(TestAction::make('create')->table())
        ->callAction(TestAction::make('create')->table(), [
            'amount' => 225.50,
            'currency' => 'USD',
            'payment_method' => 'card',
            'paid_at' => '2026-08-27',
            'notes' => 'Profile payment',
        ])
        ->assertHasNoActionErrors()
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
