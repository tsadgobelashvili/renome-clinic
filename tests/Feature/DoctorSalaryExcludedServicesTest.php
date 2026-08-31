<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function salaryVisitWithServices(Patient $patient, Doctor $doctor, array $services, bool $paid = true): Visit
{
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'currency' => 'GEL',
        'total_price' => collect($services)->sum('price'),
    ]);

    foreach ($services as $service) {
        $catalogItem = TreatmentCase::create([
            'name' => $service['name'].' '.uniqid(),
            'category' => $service['category'],
            'is_active' => true,
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $catalogItem->getKey(),
            'quantity' => 1,
            'unit_price' => $service['price'],
        ]);
    }

    if ($paid) {
        $visit->payments()->create([
            'amount' => collect($services)->sum('price'),
            'currency' => 'GEL',
            'payment_date' => today(),
            'payment_method' => 'cash',
        ]);
    }

    return $visit;
}

test('CT panorama and consultation-only visits are not salary eligible', function (string $name, string $category) {
    $doctor = Doctor::create(['first_name' => 'Excluded', 'last_name' => 'Doctor', 'compensation_percentage' => 50, 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Patient']);
    salaryVisitWithServices($patient, $doctor, [['name' => $name, 'category' => $category, 'price' => 120]]);

    $report = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 50,
    );

    expect($report['details'])->toBe([])
        ->and($report['totals'])->toBe([]);
})->with([
    '3D CT' => ['3D CT', 'tomography'],
    'Panorama' => ['Panorama', 'tomography'],
    'Consultation' => ['Consultation', 'consultation'],
]);

test('mixed clinic visits calculate salary only from the eligible manipulation', function (string $name, string $category) {
    $doctor = Doctor::create(['first_name' => 'Mixed', 'last_name' => 'Doctor', 'compensation_percentage' => 50, 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Patient']);
    $visit = salaryVisitWithServices($patient, $doctor, [
        ['name' => $name, 'category' => $category, 'price' => 120],
        ['name' => 'Tooth extraction', 'category' => 'surgery', 'price' => 200],
    ]);

    $report = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 50,
    );

    expect($report['details'])->toHaveCount(1)
        ->and($report['details'][0]['visit_id'])->toBe($visit->getKey())
        ->and($report['details'][0]['items'])->toHaveCount(1)
        ->and($report['details'][0]['work_total'])->toBe(200.0)
        ->and($report['details'][0]['paid_total'])->toBe(200.0)
        ->and($report['details'][0]['base_total'])->toBe(200.0)
        ->and($report['details'][0]['doctor_share'])->toBe(100.0);
})->with([
    '3D CT' => ['3D CT', 'tomography'],
    'Panorama' => ['Panorama', 'tomography'],
    'Consultation' => ['Consultation', 'consultation'],
]);

test('normal and partner salary rules retain their existing bases after exclusions', function () {
    $doctor = Doctor::create(['first_name' => 'Normal', 'last_name' => 'Doctor', 'compensation_percentage' => 25, 'is_active' => true]);
    $clinic = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Patient']);
    $partner = Patient::create([
        'first_name' => 'Partner',
        'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);

    salaryVisitWithServices($clinic, $doctor, [['name' => 'Therapy', 'category' => 'therapy', 'price' => 400]]);
    salaryVisitWithServices($partner, $doctor, [
        ['name' => '3D CT', 'category' => 'tomography', 'price' => 120],
        ['name' => 'Partner therapy', 'category' => 'therapy', 'price' => 300],
    ], false);

    $clinicReport = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 25, null, PatientGroup::CLINIC_SLUG,
    );
    $partnerReport = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 25, null, PatientGroup::ISRAEL_PARTNER_SLUG,
    );

    expect($clinicReport['totals']['GEL']['base_total'])->toBe(400.0)
        ->and($clinicReport['totals']['GEL']['doctor_share'])->toBe(100.0)
        ->and($partnerReport['details'])->toHaveCount(1)
        ->and($partnerReport['details'][0]['items'])->toHaveCount(1)
        ->and($partnerReport['totals']['GEL']['base_total'])->toBe(300.0)
        ->and($partnerReport['totals']['GEL']['doctor_share'])->toBe(75.0);
});
