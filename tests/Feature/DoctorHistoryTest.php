<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('doctor financial summary includes visits discounts payments and balances', function () {
    $patient = Patient::create([
        'first_name' => 'Doctor',
        'last_name' => 'Patient',
    ]);
    $doctor = Doctor::create([
        'first_name' => 'Profile',
        'last_name' => 'Doctor',
        'is_active' => true,
    ]);

    $firstVisit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => now()->subDay()->toDateString(),
        'total_price' => 200,
        'discount_amount' => 50,
    ]);
    Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => now()->toDateString(),
        'total_price' => 100,
    ]);
    $firstVisit->payments()->create([
        'amount' => 120,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ]);

    expect($doctor->getFinancialSummary())->toBe([
        'visits_count' => 2,
        'gross_amount' => 300.0,
        'discount_amount' => 50.0,
        'net_amount' => 250.0,
        'paid_amount' => 120.0,
        'remaining_amount' => 130.0,
    ]);
});
