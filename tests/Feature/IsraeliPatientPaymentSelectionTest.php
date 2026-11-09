<?php

use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\IsraeliPatientPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->patient = new Patient([
        'first_name' => 'avraham', 'last_name' => 'cohen',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    // Reproduce the reported ID in the isolated test database only.
    $this->patient->id = 53;
    $this->patient->save();
    $this->patient->refresh();
});

test('selecting an existing patient through the live search allows repeated same day payments', function (?string $birthDate) {
    $this->patient->update(['birth_date' => $birthDate]);
    $this->patient->refresh();
    $service = app(IsraeliPatientPaymentService::class);
    $historical = $this->patient->partnerPayments()->create([
        'amount' => 80, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => '2026-01-01',
    ]);
    $originalPayment = $historical->fresh()->getRawOriginal();
    $originalPatient = $this->patient->getRawOriginal();
    $page = Livewire::test(ListPartnerFinance::class)->call('enableSchemaStateUpdateHooksForTesting');
    foreach ([100, 100, 150] as $amount) {
        $page->mountAction('addPatientPayment')
            ->set('mountedActions.0.data.patient_search', 'avraham')
            ->set('mountedActions.0.data.patient_search', $service->patientLabel($this->patient))
            ->assertSet('mountedActions.0.data.patient_id', $this->patient->id)
            ->set('mountedActions.0.data.amount', $amount)
            ->callMountedAction()->assertHasNoActionErrors();
    }
    expect(Patient::count())->toBe(1)
        ->and(PartnerPatientPayment::count())->toBe(4)
        ->and($this->patient->partnerPayments()->whereDate('paid_at', today())->count())->toBe(3)
        ->and($this->patient->partnerPayments()->sum('amount'))->toEqual(430)
        ->and($historical->fresh()->getRawOriginal())->toBe($originalPayment)
        ->and($this->patient->fresh()->getRawOriginal())->toBe($originalPatient);
})->with([null, '1980-05-12']);

test('the live modal creates a new patient then accepts an existing patient selection', function () {
    $page = Livewire::test(ListPartnerFinance::class)->call('enableSchemaStateUpdateHooksForTesting');
    $page->mountAction('addPatientPayment')
        ->set('mountedActions.0.data.patient_search', 'New Israeli')
        ->set('mountedActions.0.data.first_name', 'New')
        ->set('mountedActions.0.data.last_name', 'Israeli')
        ->set('mountedActions.0.data.amount', 75)
        ->callMountedAction()->assertHasNoActionErrors();
    $new = Patient::where('first_name', 'New')->sole();
    $page->mountAction('addPatientPayment')
        ->set('mountedActions.0.data.patient_search', app(IsraeliPatientPaymentService::class)->patientLabel($this->patient))
        ->assertSet('mountedActions.0.data.patient_id', 53)
        ->set('mountedActions.0.data.amount', 125)
        ->callMountedAction()->assertHasNoActionErrors();
    expect(Patient::count())->toBe(2)
        ->and($new->partnerPayments()->sole()->amount)->toBe('75.00')
        ->and($this->patient->partnerPayments()->sole()->amount)->toBe('125.00');
});

test('changing an existing selection clears its ID before creating a new patient', function () {
    Livewire::test(ListPartnerFinance::class)->call('enableSchemaStateUpdateHooksForTesting')
        ->mountAction('addPatientPayment')
        ->set('mountedActions.0.data.patient_search', app(IsraeliPatientPaymentService::class)->patientLabel($this->patient))
        ->assertSet('mountedActions.0.data.patient_id', 53)
        ->set('mountedActions.0.data.patient_search', 'Another Israeli')
        ->assertSet('mountedActions.0.data.patient_id', null)
        ->set('mountedActions.0.data.first_name', 'Another')
        ->set('mountedActions.0.data.last_name', 'Israeli')
        ->set('mountedActions.0.data.amount', 90)
        ->callMountedAction()->assertHasNoActionErrors();
    expect(Patient::count())->toBe(2)
        ->and($this->patient->partnerPayments()->count())->toBe(0)
        ->and(Patient::where('first_name', 'Another')->sole()->partnerPayments()->count())->toBe(1);
});

test('the service accepts selected IDs and label fallbacks without updating existing patients', function (bool $useLabel) {
    $service = app(IsraeliPatientPaymentService::class);
    $before = $this->patient->getRawOriginal();
    $selection = $useLabel ? ['patient_search' => $service->patientLabel($this->patient)] : ['patient_id' => 53];
    foreach (range(1, 2) as $index) {
        $payment = $service->create($selection + [
            'amount' => 100, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => today(),
        ]);
        expect($payment->patient_id)->toBe(53);
    }
    expect(Patient::count())->toBe(1)->and(PartnerPatientPayment::count())->toBe(2)
        ->and($this->patient->fresh()->getRawOriginal())->toBe($before);
})->with([false, true]);

test('a selected clinic or missing patient is rejected without creating financial records', function (bool $missing) {
    $id = $missing ? 999 : Patient::create(['first_name' => 'Clinic', 'last_name' => 'Patient'])->id;
    expect(fn () => app(IsraeliPatientPaymentService::class)->create([
        'patient_id' => $id, 'amount' => 100, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => today(),
    ]))->toThrow(ValidationException::class);
    expect(PartnerPatientPayment::count())->toBe(0);
})->with([false, true]);

test('a plain duplicate name requires selection while selecting that patient permits payment', function () {
    $service = app(IsraeliPatientPaymentService::class);
    $payment = ['amount' => 100, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => today()];
    expect(fn () => $service->create($payment + ['first_name' => 'avraham', 'last_name' => 'cohen']))
        ->toThrow(ValidationException::class);
    $created = $service->create($payment + ['patient_id' => 53]);
    expect($created->patient_id)->toBe(53)->and(Patient::count())->toBe(1)
        ->and(PartnerPatientPayment::count())->toBe(1);
});
