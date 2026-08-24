<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
