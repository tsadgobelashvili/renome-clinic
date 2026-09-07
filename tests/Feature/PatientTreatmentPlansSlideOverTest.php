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
        ->assertActionExists($action, fn (Action $action): bool => $action->isModalSlideOver() && $action->getUrl() === null)
        ->mountAction($action)
        ->assertActionDataSet(['mode' => 'list', 'patient_id' => $patient->getKey()])
        ->assertMountedActionModalSee('იმპლანტაცია')
        ->callAction(TestAction::make('showCreatePlanForm')->schemaComponent())
        ->assertActionDataSet(['mode' => 'create'])
        ->setActionData([
            'estimate_date' => '2026-08-24',
            'options' => [[
                'stages' => [[
                    'name' => 'I ეტაპი',
                    'sort_order' => 1,
                    'items' => [[
                        'description' => 'ახალი modal გეგმა',
                        'quantity' => 1,
                        'unit_price' => 250,
                    ]],
                ]],
            ]],
        ])->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNoRedirect()
        ->assertActionDataSet(['mode' => 'list'])
        ->assertMountedActionModalSee('ახალი modal გეგმა');

    expect($patient->treatmentEstimates()->whereDate('estimate_date', '2026-08-24')->exists())->toBeTrue();

    $patient->load([
        'treatmentEstimates.doctor',
        'treatmentEstimates.options.items',
        'treatmentEstimates.options.stages.items',
    ]);

    $this->view('filament.resources.patients.treatment-plans-slide-over', [
        'patient' => $patient,
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
    ])
        ->assertSee('ამ პაციენტისთვის მკურნალობის გეგმა ჯერ არ არის შექმნილი.');
});

test('patient profile treatment plans prefill a relevant assigned doctor without replacing a saved doctor', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Doctor', 'last_name' => 'Prefill']);
    $implantologist = Doctor::create([
        'first_name' => 'Implant', 'last_name' => 'Doctor',
        'specialty' => 'Implantologist', 'is_active' => true,
    ]);
    $orthopedist = Doctor::create([
        'first_name' => 'Ortho', 'last_name' => 'Doctor',
        'specialty' => 'Orthopedics', 'is_active' => true,
    ]);
    $savedDoctor = Doctor::create([
        'first_name' => 'Saved', 'last_name' => 'Doctor',
        'specialty' => 'Therapy', 'is_active' => true,
    ]);

    $patient->doctors()->attach($implantologist, [
        'role' => 'Implantology', 'assignment_source' => 'manual', 'is_primary' => false,
    ]);
    $patient->doctors()->attach($orthopedist, [
        'role' => 'Orthopedics', 'assignment_source' => 'manual', 'is_primary' => true,
    ]);

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->mountAction('treatmentPlans')
        ->assertActionDataSet(['doctor_id' => $orthopedist->getKey()]);

    $emptyEstimate = TreatmentEstimate::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => null,
        'estimate_date' => '2026-09-02',
    ]);

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->mountAction('treatmentPlans')
        ->call('editTreatmentPlanInModal', $emptyEstimate->getKey())
        ->assertActionDataSet(['doctor_id' => $orthopedist->getKey()]);

    $savedEstimate = TreatmentEstimate::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $savedDoctor->getKey(),
        'estimate_date' => '2026-09-02',
    ]);

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->mountAction('treatmentPlans')
        ->call('editTreatmentPlanInModal', $savedEstimate->getKey())
        ->assertActionDataSet(['doctor_id' => $savedDoctor->getKey()]);
});

test('patient profile opens and updates the same treatment plan without duplication', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Edit', 'last_name' => 'Plan']);
    $doctor = Doctor::create(['first_name' => 'Plan', 'last_name' => 'Doctor', 'is_active' => true]);
    $estimate = TreatmentEstimate::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_id' => null,
        'estimate_date' => '2026-09-01',
        'comment' => 'Original note',
    ]);
    $option = TreatmentEstimateOption::create([
        'treatment_estimate_id' => $estimate->getKey(),
        'name' => 'Option A',
    ]);
    $stage = TreatmentEstimateStage::create([
        'treatment_estimate_option_id' => $option->getKey(),
        'name' => 'I ეტაპი',
        'sort_order' => 1,
    ]);
    TreatmentEstimateItem::create([
        'treatment_estimate_option_id' => $option->getKey(),
        'treatment_estimate_stage_id' => $stage->getKey(),
        'description' => 'Existing manipulation',
        'quantity' => 2,
        'unit_price' => 125,
    ]);

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->mountAction('treatmentPlans')
        ->assertMountedActionModalSee(['Existing manipulation', '250.00 ₾', 'Plan Doctor'])
        ->assertNoRedirect();

    $cancelledEditor = Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->mountAction('treatmentPlans')
        ->call('editTreatmentPlanInModal', $estimate->getKey())
        ->assertActionDataSet(['mode' => 'edit', 'comment' => 'Original note'])
        ->fillForm(['comment' => 'Unsaved note'])
        ->call('cancelTreatmentPlanEditInModal')
        ->assertActionDataSet(['mode' => 'list'])
        ->assertMountedActionModalSee('Original note');

    expect($estimate->fresh()->comment)->toBe('Original note')
        ->and($estimate->fresh()->options()->count())->toBe(1);

    $editor = Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->mountAction('treatmentPlans')
        ->assertMountedActionModalSee('რედაქტირება')
        ->call('editTreatmentPlanInModal', $estimate->getKey())
        ->assertActionDataSet(['mode' => 'edit']);

    $editActionIndex = array_key_last($editor->get('mountedActions'));
    $editData = $editor->get("mountedActions.{$editActionIndex}.data");
    expect((int) $editData['patient_id'])->toBe($patient->getKey())
        ->and((int) $editData['doctor_id'])->toBe($doctor->getKey())
        ->and($editData['comment'])->toBe('Original note');
    $optionState = $editor->get("mountedActions.{$editActionIndex}.data.options");
    $optionKey = array_key_first($optionState);
    $options = array_values($optionState);
    $stageKey = array_key_first($options[0]['stages']);
    $stages = array_values($options[0]['stages']);
    $itemKey = array_key_first($stages[0]['items']);
    $items = array_values($stages[0]['items']);
    expect($items[0]['description'])->toBe('Existing manipulation');

    $editor->set("mountedActions.{$editActionIndex}.data.options.{$optionKey}.stages.{$stageKey}.items.{$itemKey}.description", 'Updated manipulation')
        ->set("mountedActions.{$editActionIndex}.data.options.{$optionKey}.stages.{$stageKey}.items.{$itemKey}.quantity", 3)
        ->fillForm(['comment' => 'Updated note'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNoRedirect()
        ->assertActionMounted('treatmentPlans')
        ->assertMountedActionModalSee('Updated note');

    expect(TreatmentEstimate::query()->count())->toBe(1)
        ->and($estimate->fresh()->getKey())->toBe($estimate->getKey())
        ->and($estimate->fresh()->patient_id)->toBe($patient->getKey())
        ->and($estimate->fresh()->visit_id)->toBeNull()
        ->and($estimate->fresh()->comment)->toBe('Updated note')
        ->and($estimate->fresh()->options()->count())->toBe(1)
        ->and($estimate->fresh()->items()->sole()->description)->toBe('Updated manipulation')
        ->and((float) $estimate->fresh()->items()->sole()->quantity)->toBe(3.0);
});
