<?php

use App\Models\{User, Patient, Doctor, Visit, TreatmentCase, Payment, CashboxTransaction};
use App\Services\{PaymentProcessor, DoctorCompensationCalculator, HistoricalPayment};
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use App\Filament\Resources\Visits\Pages\CreateVisit;

uses(RefreshDatabase::class);
beforeEach(function () {
    $this->travelTo('2026-09-28 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->patient = Patient::create(['first_name' => 'Historic', 'last_name' => 'Patient']);
    $this->doctor = Doctor::create(['first_name' => 'Historic', 'last_name' => 'Doctor', 'compensation_percentage' => 40]);
    $this->service = TreatmentCase::create(['name' => 'Historical therapy', 'category' => 'therapy', 'is_active' => true]);
    $this->manager = app(CashboxManager::class);
    $this->old = $this->manager->dayFor('2026-09-19');
    $this->old->update(['status' => 'closed', 'actual_closing_balance' => 0, 'expected_closing_balance' => 0, 'carry_forward_balance' => 0]);
    $this->current = $this->manager->today();
    $this->visit = Visit::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => '2026-09-19', 'currency' => 'GEL', 'total_price' => 1000]);
    $this->visit->treatmentCaseItems()->create(['treatment_case_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 1000]);
});

test('historical cash and card enter finance and salary without changing drawer days', function ($method) {
    $old = $this->old->fresh()->getAttributes();
    $current = $this->manager->summary($this->current);
    $payment = app(PaymentProcessor::class)->process(['visit_id' => $this->visit->id, 'amount' => 500, 'currency' => 'GEL', 'payment_date' => '2026-09-19', 'is_historical' => true], [['payment_method' => $method, 'currency' => 'GEL', 'amount' => 500]]);
    expect($this->old->fresh()->getAttributes())->toBe($old)
        ->and($this->manager->summary($this->current))->toBe($current)
        ->and(CashboxTransaction::count())->toBe(0)
        ->and($this->manager->summary($this->old)['expectedByCurrency']['GEL'])->toBe(0.0)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe($method === 'cash' ? 500.0 : 0.0)
        ->and($this->manager->availableCashForOpening($this->current)['GEL'])->toBe($method === 'cash' ? 500.0 : 0.0);
    $liquidity = app(\App\Services\Finance\LiquidityReport::class)->current('all', ['currency' => 'GEL', 'reported_balance' => 17000]);
    expect($liquidity['totals']['GEL']['bank'])->toBe(17000.0);
    $entry = app(\App\Services\Finance\AccountingLedger::class)->pnl('2026-09-19', '2026-09-19')->where('origin', 'patient_payment')->sole();
    expect((float) $entry->amount)->toBe(500.0)->and($entry->payment_method)->toBe($method);
    $salary = app(DoctorCompensationCalculator::class)->calculate($this->doctor->id, '2026-09-01', '2026-09-28');
    expect($salary['totals']['GEL']['doctor_share'])->toBe(200.0);
    app(PaymentProcessor::class)->correct($payment, ['payment_date' => '2026-09-18'], [['payment_method' => 'cash', 'currency' => 'GEL', 'amount' => 300]]);
    expect($this->manager->physicalCashBalances()['GEL'])->toBe(300.0)->and(CashboxTransaction::count())->toBe(0);
    app(PaymentProcessor::class)->void($payment->fresh());
    expect($this->manager->physicalCashBalances()['GEL'])->toBe(0.0);
})->with(['cash', 'card']);

test('ordinary payments still reject closed days and historical dates must be past', function () {
    foreach ([['is_historical' => false, 'payment_date' => '2026-09-19'], ['is_historical' => true, 'payment_date' => '2026-09-28']] as $attributes) {
        expect(fn () => app(PaymentProcessor::class)->process(['visit_id' => $this->visit->id, 'amount' => 100, ...$attributes], [['payment_method' => 'cash', 'amount' => 100, 'currency' => 'GEL']]))->toThrow(ValidationException::class);
    }
    expect(Payment::count())->toBe(0);
});

test('new historical visit persists its selected date and mode from staged payment', function () {
    Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => '2026-09-18', 'visit_type' => 'treatment',
        'treatmentCaseItems' => [['service_choice' => (string) $this->service->id, 'treatment_case_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 1000]],
    ])->callAction(\Filament\Actions\Testing\TestAction::make('makePayment')->schemaComponent(), ['amount' => 1000, 'currency' => 'GEL', 'is_historical' => true, 'payment_date' => '2026-09-19', 'splits' => [['payment_method' => 'card', 'currency' => 'GEL', 'amount' => 1000]]])->assertHasNoFormErrors();
    $payment = Payment::sole();
    expect($payment->is_historical)->toBeTrue()->and($payment->payment_date->toDateString())->toBe('2026-09-19')->and(CashboxTransaction::count())->toBe(0);
});


test('historical payment rejects unauthenticated writes and unsupported product imports', function () {
    expect(fn () => HistoricalPayment::attributes(['is_historical' => true, 'payment_date' => '2026-09-19', 'products' => [['product_id' => 1]]]))->toThrow(ValidationException::class);
    auth()->logout();
    expect(fn () => app(PaymentProcessor::class)->process(['visit_id' => $this->visit->id, 'amount' => 100, 'payment_date' => '2026-09-19', 'is_historical' => true], [['payment_method' => 'cash', 'amount' => 100, 'currency' => 'GEL']]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(Payment::count())->toBe(0);
});

test('Israeli historical receipts retain the actual date outside clinic drawers', function () {
    $this->patient->update(['patient_group_id' => \App\Models\PatientGroup::israelPartnerId()]);
    app(\App\Services\PartnerVisitPaymentRecorder::class)->record($this->patient->fresh(), [['payment_method' => 'card', 'amount' => 200, 'currency' => 'GEL']], '2026-09-19');
    $entry = \App\Models\PartnerPatientPayment::sole();
    expect($entry->paid_at->toDateString())->toBe('2026-09-19')->and($entry->payment_method)->toBe('card')->and(CashboxTransaction::count())->toBe(0);
});


test('dashboard new visit exposes and saves historical cash and card payments', function ($method) {
    Livewire::test(\App\Filament\Pages\Dashboard::class)->mountAction('newVisit')
        ->assertMountedActionModalSee('ძველი გადახდა')
        ->set('mountedActions.0.data.is_historical', true)
        ->set('mountedActions.0.data.payment_date', '2026-09-19')
        ->assertMountedActionModalSee('გადახდის რეალური თარიღი');
    $visit = \App\Filament\Resources\Visits\Schemas\VisitForm::createDashboardVisit([
        'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => '2026-09-18',
        'visit_type' => 'treatment', 'is_historical' => true, 'payment_date' => '2026-09-19',
        'treatmentCaseItems' => [['treatment_case_id' => $this->service->id, 'quantity' => 1, 'unit_price' => 500]],
        'paymentSplits' => [['payment_method' => $method, 'amount' => 500, 'currency' => 'GEL']],
    ]);
    expect($visit->payments->sole()->is_historical)->toBeTrue()
        ->and($visit->payments->sole()->payment_date->toDateString())->toBe('2026-09-19')
        ->and(CashboxTransaction::count())->toBe(0)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe($method === 'cash' ? 500.0 : 0.0);
})->with(['cash', 'card']);
