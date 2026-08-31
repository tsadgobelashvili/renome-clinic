<?php

use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Filament\Resources\Doctors\RelationManagers\VisitsRelationManager;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('doctor visit history uses the compact five-column presentation', function () {
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

    foreach (['Implantation', 'Crown', 'Consultation'] as $name) {
        $service = TreatmentCase::create(['name' => $name, 'category' => 'therapy', 'is_active' => true]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->getKey(),
            'quantity' => 1,
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
        ->assertTableColumnDoesNotExist('comment')
        ->assertTableColumnDoesNotExist('discount_display')
        ->assertTableColumnDoesNotExist('paid_amount')
        ->assertSee('+1');
});
