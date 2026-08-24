<?php

use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

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
