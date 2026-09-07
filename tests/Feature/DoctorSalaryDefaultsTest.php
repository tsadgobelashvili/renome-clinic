<?php

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use App\Services\SalarySettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function salaryDefaultVisit(Doctor $doctor, array $items, float $payment): Visit
{
    $patient = Patient::create(['first_name' => 'Salary', 'last_name' => 'Defaults']);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'currency' => 'GEL',
        'total_price' => collect($items)->sum('price'),
    ]);

    foreach ($items as $item) {
        $treatment = TreatmentCase::create([
            'name' => $item['name'],
            'category' => $item['category'],
            'is_active' => true,
        ]);
        $visitItem = $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $treatment->getKey(),
            'quantity' => 1,
            'unit_price' => $item['price'],
        ]);
        if (($item['expense'] ?? 0) > 0) {
            $visitItem->directExpenses()->create([
                'name' => 'Direct expense',
                'amount' => $item['expense'],
                'currency' => 'GEL',
            ]);
        }
    }

    $visit->payments()->create([
        'amount' => $payment,
        'currency' => 'GEL',
        'payment_date' => today(),
        'payment_method' => 'cash',
    ]);

    return $visit;
}

test('named doctors receive their configured default salary percentage', function (string $first, string $last, float $expected) {
    $doctor = Doctor::create(['first_name' => $first, 'last_name' => $last, 'is_active' => true]);

    expect((float) $doctor->compensation_percentage)->toBe($expected);
})->with([
    ['Levan', 'Berikashvili', 50.0],
    ['Nodar', 'Elishakov', 50.0],
    ['David', 'Chumburidze', 40.0],
    ['დავით', 'ჭუმბურიძე', 40.0],
    ['Shalva', 'Berdzuli', 40.0],
    ['Natalia', 'Iluridze', 40.0],
    ['Nino', 'Batsatsashvili', 40.0],
    ['Tamar', 'Kavtaradze', 40.0],
    ['Lela', 'Jiadze', 40.0],
]);

test('manual salary percentage override applies only to the current calculation', function () {
    $doctor = Doctor::create(['first_name' => 'David', 'last_name' => 'Chumburidze', 'is_active' => true]);
    salaryDefaultVisit($doctor, [['name' => 'Therapy', 'category' => 'therapy', 'price' => 1000]], 1000);

    $report = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 55,
    );

    expect($report['totals']['GEL']['doctor_share'])->toBe(550.0)
        ->and((float) $doctor->fresh()->compensation_percentage)->toBe(40.0);
});

test('Keti salary uses category percentages including mixed work and direct expenses', function () {
    $doctor = Doctor::create(['first_name' => 'Keti', 'last_name' => 'Kukhianidze', 'is_active' => true]);
    salaryDefaultVisit($doctor, [
        ['name' => 'Therapy work', 'category' => 'therapy', 'price' => 1000, 'expense' => 100],
        ['name' => 'Periodontology work', 'category' => 'periodontology', 'price' => 1000, 'expense' => 100],
    ], 2000);

    $report = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(),
    );
    $items = collect($report['details'][0]['items'])->keyBy('category');

    expect($doctor->compensation_category_percentages)->toBe(['therapy' => 40, 'periodontology' => 70])
        ->and($items['therapy']['applied_percentage'])->toBe(40.0)
        ->and($items['therapy']['doctor_share'])->toBe(360.0)
        ->and($items['periodontology']['applied_percentage'])->toBe(70.0)
        ->and($items['periodontology']['doctor_share'])->toBe(630.0)
        ->and($report['totals']['GEL']['doctor_share'])->toBe(990.0);
});

test('finalization stores the category percentage used for each salary item', function () {
    $doctor = Doctor::create(['first_name' => 'Keti', 'last_name' => 'Kukhianidze', 'is_active' => true]);
    salaryDefaultVisit($doctor, [
        ['name' => 'Therapy snapshot', 'category' => 'therapy', 'price' => 1000],
        ['name' => 'Periodontology snapshot', 'category' => 'periodontology', 'price' => 1000],
    ], 2000);

    $settlement = app(SalarySettlementService::class)->settle(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 40, null,
    )[0];

    expect($settlement->items->pluck('salary_percentage_snapshot')->map(fn ($value) => (float) $value)->sort()->values()->all())
        ->toBe([40.0, 70.0]);
});
