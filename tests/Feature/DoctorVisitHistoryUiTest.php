<?php

use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Filament\Resources\Doctors\RelationManagers\VisitsRelationManager;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\SalarySettlement;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function doctorHistoryVisit(Doctor $doctor, string $date, string $label): Visit
{
    $patient = Patient::create(['first_name' => $label, 'last_name' => 'Patient']);
    $visit = Visit::create(['doctor_id' => $doctor->getKey(), 'patient_id' => $patient->getKey(), 'visit_date' => $date, 'total_price' => 100]);
    $service = TreatmentCase::create(['name' => $label, 'category' => 'therapy', 'is_active' => true]);
    $visit->treatmentCaseItems()->create(['treatment_case_id' => $service->getKey(), 'quantity' => 1, 'unit_price' => 100]);

    return $visit;
}

function doctorHistorySettlement(Doctor $doctor, Visit $visit, string $settledAt, string $status = 'confirmed'): SalarySettlement
{
    $settlement = SalarySettlement::create([
        'doctor_id' => $doctor->getKey(), 'period_start' => $visit->visit_date, 'period_end' => $visit->visit_date,
        'settled_at' => $settledAt, 'currency' => 'GEL', 'performed_total' => 100,
        'direct_expense_total' => 0, 'base_total' => 100, 'percentage' => 40,
        'salary_total' => 40, 'status' => $status,
    ]);
    $item = $visit->treatmentCaseItems()->firstOrFail();
    $settlement->items()->create([
        'visit_id' => $visit->getKey(), 'visit_treatment_case_id' => $item->getKey(),
        'revenue' => 100, 'direct_expense' => 0, 'salary_base' => 100, 'doctor_share' => 40,
    ]);

    return $settlement;
}

test('doctor visit history keeps its compact presentation with a boundary marker', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'Visit', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Visit', 'last_name' => 'Patient']);
    $visit = Visit::create([
        'doctor_id' => $doctor->getKey(),
        'patient_id' => $patient->getKey(),
        'visit_date' => '2026-08-28',
        'total_price' => 120,
        'currency' => 'GEL',
    ]);

    foreach ([['Implantation', 5], ['Crown', 17], ['Consultation', 1]] as [$name, $quantity]) {
        $service = TreatmentCase::create(['name' => $name, 'category' => 'therapy', 'is_active' => true]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->getKey(),
            'quantity' => $quantity,
            'unit_price' => 40,
        ]);
    }

    Livewire::test(VisitsRelationManager::class, [
        'ownerRecord' => $doctor,
        'pageClass' => ViewDoctor::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visit])
        ->assertTableColumnExists('visit_date')
        ->assertTableColumnExists('patient.full_name')
        ->assertTableColumnExists('treatment_cases')
        ->assertTableColumnExists('total_price')
        ->assertTableColumnExists('payment_status')
        ->assertTableColumnExists('salary_settlement_boundary')
        ->assertTableColumnDoesNotExist('comment')
        ->assertTableColumnDoesNotExist('discount_display')
        ->assertTableColumnDoesNotExist('paid_amount')
        ->assertSee('Implantation ×5')
        ->assertSee('Crown ×17')
        ->assertSee('+1');
});

test('doctor with no finalized salary settlement has no boundary marker', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'No', 'last_name' => 'Settlement', 'is_active' => true]);
    $visit = doctorHistoryVisit($doctor, '2026-08-28', 'Unsettled');

    Livewire::test(VisitsRelationManager::class, ['ownerRecord' => $doctor, 'pageClass' => ViewDoctor::class])
        ->assertTableColumnStateSet('salary_settlement_boundary', false, $visit);
});

test('one finalized salary settlement marks only its latest included visit', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'One', 'last_name' => 'Settlement', 'is_active' => true]);
    $older = doctorHistoryVisit($doctor, '2026-08-27', 'Older');
    $boundary = doctorHistoryVisit($doctor, '2026-08-28', 'Boundary');
    $settlement = doctorHistorySettlement($doctor, $older, '2026-08-29 10:00:00');
    $item = $boundary->treatmentCaseItems()->sole();
    $settlement->items()->create(['visit_id' => $boundary->getKey(), 'visit_treatment_case_id' => $item->getKey(), 'revenue' => 100, 'direct_expense' => 0, 'salary_base' => 100, 'doctor_share' => 40]);

    Livewire::test(VisitsRelationManager::class, ['ownerRecord' => $doctor, 'pageClass' => ViewDoctor::class])
        ->assertTableColumnStateSet('salary_settlement_boundary', false, $older)
        ->assertTableColumnStateSet('salary_settlement_boundary', true, $boundary)
        ->assertSee('დათვლილია აქამდე');
});

test('multiple finalized settlements use the most recent settlement boundary', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'Many', 'last_name' => 'Settlements', 'is_active' => true]);
    $first = doctorHistoryVisit($doctor, '2026-08-30', 'First');
    $latestBoundary = doctorHistoryVisit($doctor, '2026-08-28', 'Latest boundary');
    $notIncluded = doctorHistoryVisit($doctor, '2026-09-01', 'Not included');
    doctorHistorySettlement($doctor, $first, '2026-08-30 10:00:00');
    doctorHistorySettlement($doctor, $latestBoundary, '2026-09-02 10:00:00');

    Livewire::test(VisitsRelationManager::class, ['ownerRecord' => $doctor, 'pageClass' => ViewDoctor::class])
        ->assertTableColumnStateSet('salary_settlement_boundary', false, $first)
        ->assertTableColumnStateSet('salary_settlement_boundary', true, $latestBoundary)
        ->assertTableColumnStateSet('salary_settlement_boundary', false, $notIncluded);
});

test('cancelled or undone settlement does not create a boundary', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'Cancelled', 'last_name' => 'Settlement', 'is_active' => true]);
    $visit = doctorHistoryVisit($doctor, '2026-08-28', 'Cancelled item');
    doctorHistorySettlement($doctor, $visit, '2026-08-29 10:00:00', 'cancelled');

    Livewire::test(VisitsRelationManager::class, ['ownerRecord' => $doctor, 'pageClass' => ViewDoctor::class])
        ->assertTableColumnStateSet('salary_settlement_boundary', false, $visit);
});
