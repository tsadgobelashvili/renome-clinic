<?php

use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentEstimate;
use App\Models\TreatmentEstimateItem;
use App\Models\TreatmentEstimateOption;
use App\Models\TreatmentEstimateStage;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('patient treatment plan action opens a scoped slide over with variants and exports', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'გიორგი', 'last_name' => 'ბერიძე']);
    $otherPatient = Patient::create(['first_name' => 'სხვა', 'last_name' => 'პაციენტი']);
    $doctor = Doctor::create(['first_name' => 'ნოდარ', 'last_name' => 'ელიშაკოვი', 'is_active' => true]);

    $estimate = TreatmentEstimate::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'estimate_date' => '2026-08-23',
        'comment' => 'გეგმის კომენტარი',
    ]);
    $option = TreatmentEstimateOption::create([
        'treatment_estimate_id' => $estimate->getKey(),
        'name' => 'პრემიუმ',
        'estimated_duration' => '4-6 თვე',
    ]);
    $stage = TreatmentEstimateStage::create([
        'treatment_estimate_option_id' => $option->getKey(),
        'name' => 'I ეტაპი',
        'sort_order' => 1,
    ]);
    TreatmentEstimateItem::create([
        'treatment_estimate_option_id' => $option->getKey(),
        'treatment_estimate_stage_id' => $stage->getKey(),
        'description' => 'იმპლანტაცია',
        'quantity' => 4,
        'unit_price' => 1200,
    ]);
    TreatmentEstimate::create([
        'patient_id' => $otherPatient->getKey(),
        'estimate_date' => '2026-08-22',
        'comment' => 'სხვა პაციენტის საიდუმლო გეგმა',
    ]);

    $action = TestAction::make('treatmentPlans');

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->assertActionExists($action, fn (Action $action): bool => $action->isModalSlideOver() && $action->getUrl() === null);

    $patient->load([
        'treatmentEstimates.doctor',
        'treatmentEstimates.options.items',
        'treatmentEstimates.options.stages.items',
    ]);

    $this->view('filament.resources.patients.treatment-plans-slide-over', [
        'patient' => $patient,
        'createUrl' => '/admin/treatment-estimates/create?patient_id='.$patient->getKey(),
    ])
        ->assertSee('გიორგი ბერიძე')
        ->assertSee('მკურნალობის გეგმა — 23.08.2026')
        ->assertSee('იმპლანტაცია')
        ->assertSee('4,800.00 ₾')
        ->assertSee('PDF')
        ->assertSee('Word')
        ->assertDontSee('სხვა პაციენტის საიდუმლო გეგმა');

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->assertActionExists($action, fn (Action $action): bool => $action->isModalSlideOver() && $action->getUrl() === null);
});

test('patient treatment plan slide over has a safe empty state', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'ცარიელი', 'last_name' => 'გეგმა']);

    $this->view('filament.resources.patients.treatment-plans-slide-over', [
        'patient' => $patient->load('treatmentEstimates'),
        'createUrl' => '/admin/treatment-estimates/create?patient_id='.$patient->getKey(),
    ])
        ->assertSee('ამ პაციენტისთვის მკურნალობის გეგმა ჯერ არ არის შექმნილი.')
        ->assertSee('ახალი გეგმის შექმნა');
});
