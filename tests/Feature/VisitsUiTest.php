<?php

use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\TreatmentEstimate;
use App\Models\User;
use App\Models\Visit;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('visits filter by patient group through the patient relationship', function () {
    $this->actingAs(User::factory()->create());
    $partnerGroup = PatientGroup::query()->where('slug', PatientGroup::ISRAEL_PARTNER_SLUG)->sole();
    $clinicPatient = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Visit']);
    $partnerPatient = Patient::create([
        'first_name' => 'Partner',
        'last_name' => 'Visit',
        'patient_group_id' => $partnerGroup->getKey(),
    ]);
    $clinicVisit = Visit::create(['patient_id' => $clinicPatient->getKey(), 'visit_date' => today()]);
    $partnerVisit = Visit::create(['patient_id' => $partnerPatient->getKey(), 'visit_date' => today()]);

    Livewire::test(ListVisits::class)
        ->filterTable('patient_group_id', $partnerGroup->getKey())
        ->assertCanSeeTableRecords([$partnerVisit])
        ->assertCanNotSeeTableRecords([$clinicVisit]);
});

test('a new visit starts with one empty treatment row while edit does not add another row', function () {
    $this->actingAs(User::factory()->create());

    $createPage = Livewire::test(CreateVisit::class)
        ->assertOk()
        ->assertDontSee('გეგმა')
        ->assertDontSee('ვარიანტი');
    $newItems = collect($createPage->instance()->form->getRawState()['treatmentCaseItems'] ?? [])->values();

    expect($createPage->instance()->getTitle())->toBe('ახალი ვიზიტი')
        ->and($createPage->instance()->getBreadcrumbs())->toBe([])
        ->and($newItems)->toHaveCount(1)
        ->and($newItems->first()['treatment_case_id'] ?? null)->toBeNull()
        ->and((int) ($newItems->first()['quantity'] ?? 0))->toBe(1);

    [$patient, $doctor] = [
        Patient::create(['first_name' => 'Edit', 'last_name' => 'Patient']),
        Doctor::create(['first_name' => 'Edit', 'last_name' => 'Doctor', 'is_active' => true]),
    ];
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
    ]);
    $treatment = TreatmentCase::create([
        'name' => 'Existing treatment',
        'category' => 'therapy',
        'is_active' => true,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 2,
        'unit_price' => 100,
    ]);

    $editPage = Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()])
        ->assertOk()
        ->assertDontSee('გეგმა')
        ->assertDontSee('ვარიანტი');
    $savedItems = collect($editPage->instance()->form->getRawState()['treatmentCaseItems'] ?? [])->values();

    expect($editPage->instance()->getTitle())->toBe('ვიზიტის რედაქტირება')
        ->and($editPage->instance()->getBreadcrumbs())->toBe([])
        ->and($savedItems)->toHaveCount(1)
        ->and((int) ($savedItems->first()['treatment_case_id'] ?? 0))->toBe($treatment->getKey())
        ->and((int) ($savedItems->first()['quantity'] ?? 0))->toBe(2);
});

test('visit form saves a manual manipulation fallback with quantity price and total', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Manual', 'last_name' => 'Patient']);

    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'doctor_id' => null,
            'visit_type' => 'treatment',
            'treatmentCaseItems' => [[
                'service_choice' => '__manual__',
                'treatment_case_id' => null,
                'custom_service_name' => 'სპეციალური კლინიკური პროცედურა',
                'quantity' => 2,
                'unit_price' => 125,
            ]],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $visit = Visit::query()->sole();
    $item = $visit->treatmentCaseItems()->sole();

    expect($item->treatment_case_id)->toBeNull()
        ->and($item->custom_service_name)->toBe('სპეციალური კლინიკური პროცედურა')
        ->and((float) $visit->total_price)->toBe(250.0);
});

test('creating a visit redirects to the visits list', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Redirect', 'last_name' => 'Patient']);

    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'visit_type' => 'treatment',
            'treatmentCaseItems' => [[
                'service_choice' => '__manual__',
                'treatment_case_id' => null,
                'custom_service_name' => 'Redirect test service',
                'quantity' => 1,
                'unit_price' => 100,
            ]],
        ])
        ->call('create')
        ->assertHasNoErrors()
        ->assertRedirect(VisitResource::getUrl('index'));

    expect(Visit::query()->count())->toBe(1);
});

test('consultation plan action opens the existing patient treatment plan flow', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Plan', 'last_name' => 'Patient']);
    $doctor = Doctor::create([
        'first_name' => 'Plan',
        'last_name' => 'Doctor',
        'is_active' => true,
    ]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'visit_type' => 'consultation',
    ]);
    $updatedAt = $visit->updated_at;
    Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()])
        ->assertSeeInOrder(['+ 3D CT', '+ გეგმა'])
        ->mountAction(TestAction::make('createEstimate')->schemaComponent())
        ->assertMountedActionModalSee('მკურნალობის გეგმა')
        ->assertActionDataSet([
            'mode' => 'create',
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
        ])
        ->setActionData([
            'estimate_date' => today()->toDateString(),
            'options' => [[
                'name' => 'ვარიანტი 1',
                'stages' => [[
                    'name' => 'I ეტაპი',
                    'sort_order' => 1,
                    'items' => [[
                        'description' => 'გეგმის მანიპულაცია',
                        'quantity' => 1,
                        'unit_price' => 100,
                    ]],
                ]],
            ]],
        ])->callMountedAction()->assertHasNoActionErrors()->assertNoRedirect();

    $estimate = TreatmentEstimate::query()->sole();
    expect($estimate->patient_id)->toBe($patient->getKey())
        ->and($estimate->doctor_id)->toBe($doctor->getKey())
        ->and($estimate->visit_id)->toBeNull()
        ->and($patient->fresh()->treatmentEstimates()->whereKey($estimate)->exists())->toBeTrue()
        ->and($visit->fresh()->updated_at->equalTo($updatedAt))->toBeTrue();
});

test('zero price unsaved consultation can open plan without validation or visit creation', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Zero', 'last_name' => 'Consultation']);
    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'visit_type' => 'consultation',
            'consultation_fee' => 0,
        ])
        ->assertActionEnabled(TestAction::make('createEstimate')->schemaComponent())
        ->mountAction(TestAction::make('createEstimate')->schemaComponent())
        ->assertMountedActionModalSee('მკურნალობის გეგმა')
        ->assertActionDataSet(['patient_id' => $patient->getKey()])
        ->setActionData([
            'estimate_date' => today()->toDateString(),
            'options' => [[
                'stages' => [[
                    'name' => 'I ეტაპი',
                    'sort_order' => 1,
                    'items' => [[
                        'description' => 'უფასო კონსულტაციის გეგმა',
                        'quantity' => 1,
                        'unit_price' => 0,
                    ]],
                ]],
            ]],
        ])->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertNoRedirect();

    expect(Visit::query()->count())->toBe(0)
        ->and(TreatmentEstimate::query()->sole()->patient_id)->toBe($patient->getKey());
});

test('visit form saves one catalog manipulation selected by its real id', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Catalog', 'last_name' => 'Patient']);
    $treatment = TreatmentCase::create([
        'name' => 'Catalog manipulation',
        'category' => 'therapy',
        'default_price' => 500,
        'is_active' => true,
    ]);

    expect(VisitForm::treatmentCaseSearchResults('Catalog manipulation'))
        ->toHaveKey($treatment->getKey(), $treatment->name);

    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'visit_type' => 'treatment',
            'treatmentCaseItems' => [[
                'service_choice' => (string) $treatment->getKey(),
                'treatment_case_id' => $treatment->getKey(),
                'quantity' => 2,
                'unit_price' => 500,
            ]],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $visit = Visit::query()->with('treatmentCaseItems')->sole();

    expect($visit->treatmentCaseItems->sole()->treatment_case_id)->toBe($treatment->getKey())
        ->and((float) $visit->total_price)->toBe(1000.0);
});

test('visit form saves multiple catalog and mixed manual manipulation rows', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Mixed', 'last_name' => 'Patient']);
    $first = TreatmentCase::create([
        'name' => 'First catalog manipulation',
        'category' => 'therapy',
        'is_active' => true,
    ]);
    $second = TreatmentCase::create([
        'name' => 'Second catalog manipulation',
        'category' => 'surgery',
        'is_active' => true,
    ]);

    Livewire::test(CreateVisit::class)
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'visit_type' => 'treatment',
            'treatmentCaseItems' => [
                [
                    'service_choice' => (string) $first->getKey(),
                    'treatment_case_id' => $first->getKey(),
                    'quantity' => 1,
                    'unit_price' => 100,
                ],
                [
                    'service_choice' => (string) $second->getKey(),
                    'treatment_case_id' => $second->getKey(),
                    'quantity' => 2,
                    'unit_price' => 200,
                ],
                [
                    'service_choice' => '__manual__',
                    'treatment_case_id' => null,
                    'custom_service_name' => 'ხელით დამატებული სამუშაო',
                    'quantity' => 1,
                    'unit_price' => 50,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoErrors();

    $items = Visit::query()->sole()->treatmentCaseItems()->orderBy('id')->get();

    expect($items)->toHaveCount(3)
        ->and($items->pluck('treatment_case_id')->filter()->values()->all())->toBe([$first->getKey(), $second->getKey()])
        ->and($items->last()->treatment_case_id)->toBeNull()
        ->and($items->last()->custom_service_name)->toBe('ხელით დამატებული სამუშაო');
});

test('visits page renders with a resettable seven day default range and working filters', function () {
    $this->actingAs(User::factory()->create());

    $doctor = Doctor::create([
        'first_name' => 'ნოდარ',
        'last_name' => 'ელიშაკოვი',
        'is_active' => true,
    ]);
    $otherDoctor = Doctor::create([
        'first_name' => 'ლევან',
        'last_name' => 'ექიმი',
        'is_active' => true,
    ]);
    $patient = Patient::create([
        'first_name' => 'გიორგი',
        'last_name' => 'ბერიძე',
    ]);

    Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
    ]);
    Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $otherDoctor->getKey(),
        'visit_date' => today()->subDays(20),
    ]);

    $page = Livewire::test(ListVisits::class)
        ->assertOk()
        ->assertSee('ახალი ვიზიტი')
        ->assertSee('7 დღე')
        ->assertSee('14 დღე')
        ->assertSee('1 თვე')
        ->assertSee('3 თვე')
        ->assertSee('6 თვე')
        ->assertSee('1 წელი')
        ->assertSee('ყველა პერიოდი')
        ->assertDontSee('ამ თვეში გადახდილი')
        ->assertDontSee('სულ გადასახდელი')
        ->assertSeeHtml(VisitResource::getUrl('create'))
        ->assertSet('tableFilters.visit_date.from', today()->subDays(6)->toDateString())
        ->assertSet('tableFilters.visit_date.until', today()->toDateString())
        ->assertCanSeeTableRecords(Visit::query()->where('doctor_id', $doctor->getKey())->get())
        ->assertCanNotSeeTableRecords(Visit::query()->where('doctor_id', $otherDoctor->getKey())->get())
        ->set('tableFilters.visit_date.from', today()->subMonth()->toDateString())
        ->set('tableFilters.visit_date.until', today()->toDateString())
        ->assertCanSeeTableRecords(Visit::query()->get())
        ->set('tableFilters.visit_date.from', today()->subYear()->toDateString())
        ->assertCanSeeTableRecords(Visit::query()->get())
        ->set('tableFilters.visit_date.from', today()->subDays(6)->toDateString())
        ->assertCanSeeTableRecords(Visit::query()->where('doctor_id', $doctor->getKey())->get())
        ->assertCanNotSeeTableRecords(Visit::query()->where('doctor_id', $otherDoctor->getKey())->get())
        ->set('tableFilters.visit_date.from', today()->subDays(13)->toDateString())
        ->set('tableFilters.doctor_id.value', $doctor->getKey())
        ->assertCanSeeTableRecords(Visit::query()->where('doctor_id', $doctor->getKey())->get())
        ->set('tableSearch', 'გიორგი')
        ->assertCanSeeTableRecords(Visit::query()->where('doctor_id', $doctor->getKey())->get())
        ->call('resetTableFiltersForm')
        ->assertSet('tableFilters.visit_date.from', today()->subDays(6)->toDateString())
        ->assertSet('tableFilters.visit_date.until', today()->toDateString());

    expect($page->instance()->getBreadcrumbs())->toBe([]);
});

test('visits search finds patients by name phone and personal id', function () {
    $this->actingAs(User::factory()->create());

    $targetPatient = Patient::create([
        'first_name' => 'SearchableFirst',
        'last_name' => 'SearchableLast',
        'phone' => '555123987',
        'personal_id' => '01010112345',
    ]);
    $otherPatient = Patient::create([
        'first_name' => 'Other',
        'last_name' => 'Patient',
        'phone' => '555000000',
        'personal_id' => '02020212345',
    ]);
    $targetVisit = Visit::create(['patient_id' => $targetPatient->getKey(), 'visit_date' => today()]);
    $otherVisit = Visit::create(['patient_id' => $otherPatient->getKey(), 'visit_date' => today()]);

    $page = Livewire::test(ListVisits::class);

    foreach (['SearchableFirst', 'SearchableLast', '555123987', '01010112345'] as $search) {
        $page->set('tableSearch', $search)
            ->assertCanSeeTableRecords([$targetVisit])
            ->assertCanNotSeeTableRecords([$otherVisit]);
    }
});

test('visits table has no actions column while each row still opens the visit', function () {
    $this->actingAs(User::factory()->create());

    $patient = Patient::create(['first_name' => 'Clickable', 'last_name' => 'Patient']);
    $visit = Visit::create(['patient_id' => $patient->getKey(), 'visit_date' => today()]);

    $page = Livewire::test(ListVisits::class)
        ->assertOk()
        ->assertTableActionDoesNotExist('view')
        ->assertTableActionDoesNotExist('edit')
        ->assertDontSee('მოქმედებები');

    expect($page->instance()->getTable()->getRecordUrl($visit))
        ->toBe(VisitResource::getUrl('edit', ['record' => $visit]));
});
