<?php

use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\VisitsRelationManager;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\PaymentProcessor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('patient financial summary is calculated from visits and payments', function () {
    $patient = Patient::create([
        'first_name' => 'History',
        'last_name' => 'Patient',
    ]);

    $doctor = Doctor::create([
        'first_name' => 'History',
        'last_name' => 'Doctor',
        'is_active' => true,
    ]);

    $firstVisit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => now()->subDay()->toDateString(),
        'total_price' => 100,
        'discount_amount' => 20,
    ]);

    $secondVisit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => now()->toDateString(),
        'total_price' => 50,
    ]);

    $firstVisit->payments()->create([
        'amount' => 40,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ]);

    $secondVisit->payments()->create([
        'amount' => 50,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'card',
    ]);

    expect($patient->visits()->count())->toBe(2)
        ->and($patient->getFinancialSummary())->toBe([
            'gross_amount' => 150.0,
            'discount_amount' => 20.0,
            'net_amount' => 130.0,
            'paid_amount' => 90.0,
            'remaining_amount' => 40.0,
        ]);
});

test('patient activity and balances remain separated by currency', function () {
    $patient = Patient::create(['first_name' => 'Currency', 'last_name' => 'Patient']);
    $doctor = Doctor::create([
        'first_name' => 'Currency',
        'last_name' => 'Doctor',
        'is_active' => true,
    ]);

    $gelVisit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => '2026-08-20',
        'total_price' => 1000,
        'currency' => 'GEL',
    ]);
    $usdVisit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => '2026-08-23',
        'total_price' => 500,
        'currency' => 'USD',
    ]);

    $gelPayment = $gelVisit->payments()->create([
        'amount' => 200,
        'currency' => 'GEL',
        'payment_date' => '2026-08-21',
        'payment_method' => 'cash',
    ]);
    $deletedLatestPayment = $usdVisit->payments()->create([
        'amount' => 100,
        'currency' => 'USD',
        'payment_date' => '2026-08-23',
        'payment_method' => 'card',
    ]);

    $summaries = $patient->getFinancialSummariesByCurrency();

    expect($summaries['GEL']['net_amount'])->toBe(1000.0)
        ->and($summaries['GEL']['paid_amount'])->toBe(200.0)
        ->and($summaries['GEL']['remaining_amount'])->toBe(800.0)
        ->and($summaries['USD']['net_amount'])->toBe(500.0)
        ->and($summaries['USD']['paid_amount'])->toBe(100.0)
        ->and($summaries['USD']['remaining_amount'])->toBe(400.0)
        ->and($patient->getLatestVisitRecord()->is($usdVisit))->toBeTrue()
        ->and($patient->getLatestPaymentRecord()->is($deletedLatestPayment))->toBeTrue();

    $deletedLatestPayment->delete();
    $freshPatient = $patient->fresh();

    expect($freshPatient->getLatestPaymentRecord()->is($gelPayment))->toBeTrue()
        ->and($freshPatient->getFinancialSummariesByCurrency()['USD']['paid_amount'])->toBe(0.0)
        ->and($freshPatient->getFinancialSummariesByCurrency()['USD']['remaining_amount'])->toBe(500.0);
});

test('patient unified history keeps same-day visits separate and preserves exact payment history', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Unified', 'last_name' => 'History']);
    $doctor = Doctor::create(['first_name' => 'Unified', 'last_name' => 'Doctor', 'is_active' => true]);
    $service = TreatmentCase::create(['name' => 'Implant', 'category' => 'surgery', 'is_active' => true]);
    $makeVisit = function (float $paid) use ($patient, $doctor, $service): Visit {
        $visit = Visit::create([
            'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
            'visit_date' => '2026-08-22', 'total_price' => 2000,
            'discount_type' => 'amount', 'discount_value' => 200, 'currency' => 'GEL',
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->getKey(), 'quantity' => 2, 'unit_price' => 1000,
        ]);
        app(PaymentProcessor::class)->process([
            'visit_id' => $visit->getKey(), 'amount' => $paid, 'currency' => 'GEL',
            'payment_date' => $paid === 1000.0 ? '2026-08-25' : '2026-08-22',
        ], $paid === 1000.0
            ? [['payment_method' => 'cash', 'amount' => 600], ['payment_method' => 'card', 'amount' => 400]]
            : [['payment_method' => 'bank_transfer', 'amount' => $paid]]);

        return $visit;
    };

    $visitA = $makeVisit(1000);
    $visitB = $makeVisit(1800);

    Livewire::test(VisitsRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => ViewPatient::class,
    ])->assertSuccessful()->assertCanSeeTableRecords([$visitA, $visitB]);

    expect($patient->visits()->count())->toBe(2)
        ->and($visitA->refresh()->paid_amount)->toBe(1000.0)
        ->and($visitA->remaining_amount)->toBe(800.0)
        ->and($visitB->refresh()->paid_amount)->toBe(1800.0)
        ->and($visitB->remaining_amount)->toBe(0.0)
        ->and($visitA->payments()->sole()->payment_date->toDateString())->toBe('2026-08-25')
        ->and($visitA->payments()->sole()->visit_id)->toBe($visitA->getKey())
        ->and($visitB->payments()->sole()->visit_id)->toBe($visitB->getKey());
});

test('patient profile adds a later split payment to the selected visit without creating a visit', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Later', 'last_name' => 'Payment']);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(), 'visit_date' => '2026-08-22',
        'total_price' => 1000, 'currency' => 'GEL',
    ]);

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->callAction(TestAction::make('makePayment'), [
            'payment_date' => '2026-08-25',
            'visit_id' => $visit->getKey(),
            'currency' => 'GEL',
            'amount' => 500,
            'splits' => [
                ['payment_method' => 'cash', 'amount' => 200],
                ['payment_method' => 'bank_transfer', 'amount' => 300],
            ],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('გადახდა წარმატებით დაემატა.');

    $payment = $visit->payments()->with(['splits', 'cashboxTransaction'])->sole();

    expect(Visit::query()->count())->toBe(1)
        ->and($payment->visit_id)->toBe($visit->getKey())
        ->and($payment->payment_date->toDateString())->toBe('2026-08-25')
        ->and((float) $payment->amount)->toBe(500.0)
        ->and($payment->splits)->toHaveCount(2)
        ->and($visit->refresh()->remaining_amount)->toBe(500.0)
        ->and($payment->cashboxTransaction)->not->toBeNull()
        ->and($payment->cashboxTransaction->transaction_date->toDateString())->toBe('2026-08-25')
        ->and(CashboxTransaction::query()->where('visit_id', $visit->getKey())->count())->toBe(2);
});
