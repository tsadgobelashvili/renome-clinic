<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\TreatmentCategory;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-13 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Levan', 'last_name' => 'Direction', 'is_active' => true, 'specialties' => ['therapy']]);
    $this->otherDoctor = Doctor::create(['first_name' => 'Other', 'last_name' => 'Direction', 'is_active' => true, 'specialties' => ['surgery', 'orthopedics']]);
    $this->clinic = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Person']);
    $this->israeli = Patient::create(['first_name' => 'Israeli', 'last_name' => 'Needle', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $this->catalog = [];
    foreach (['therapy', 'surgery', 'orthopedics', 'tomography', 'consultation'] as $category) {
        $this->catalog[$category] = TreatmentCase::create(['name' => 'Catalog '.$category, 'category' => $category, 'is_active' => true]);
    }
    $this->makeVisit = function (array $categories, array $attributes = []) {
        $visit = Visit::create($attributes + ['patient_id' => $this->israeli->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today(), 'currency' => 'GEL']);
        foreach ($categories as $category) {
            $visit->treatmentCaseItems()->create(['treatment_case_id' => $this->catalog[$category]->id, 'quantity' => 1, 'unit_price' => 100]);
        }

        return $visit;
    };
    $this->therapy = ($this->makeVisit)(['surgery']);
    $this->surgery = ($this->makeVisit)(['therapy'], ['doctor_id' => $this->otherDoctor->id]);
    $this->mixed = ($this->makeVisit)(['therapy', 'surgery'], ['doctor_id' => $this->otherDoctor->id]);
    $this->orthopedics = ($this->makeVisit)(['orthopedics'], ['doctor_id' => $this->otherDoctor->id]);
    $this->ct = ($this->makeVisit)(['tomography'], ['visit_type' => 'diagnostic', 'doctor_id' => null]);
    $this->consultation = ($this->makeVisit)(['consultation'], ['visit_type' => 'consultation']);
    $this->uncategorized = ($this->makeVisit)([]);
    $this->uncategorized->treatmentCaseItems()->create(['custom_service_name' => 'Therapy Surgery free text', 'quantity' => 1, 'unit_price' => 100]);
});

test('standalone Direction options reuse Doctor specialty configuration in either locale', function (string $locale) {
    app()->setLocale($locale);
    $custom = TreatmentCategory::create(['name' => 'Configured specialty']);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.Livewire::test(ListVisits::class)->html());
    $xpath = new DOMXPath($document);
    $select = $xpath->query('//*[@data-visits-direction]/select')->item(0);
    expect($select)->not->toBeNull();
    expect($select->getAttribute('aria-label'))->toBe(__('visits.direction'))
        ->and($select->getAttribute('wire:model.live'))->toBe('tableFilters.direction.value');
    $options = [];
    foreach ($xpath->query('./option', $select) as $option) {
        $options[$option->getAttribute('value')] = trim($option->textContent);
    }
    expect($options)->toEqual(['' => __('visits.all')] + TreatmentCase::CATEGORIES)
        ->and($options)->not->toHaveKey($custom->id);
})->with(['ka', 'en']);

test('assigned Doctor alone determines Direction and multiple specialties match without duplicates', function () {
    $page = Livewire::test(ListVisits::class)->assertCountTableRecords(7);
    $page->set('tableFilters.direction.value', 'therapy')->assertCountTableRecords(3)
        ->assertCanSeeTableRecords([$this->therapy, $this->consultation, $this->uncategorized])
        ->assertCanNotSeeTableRecords([$this->surgery, $this->mixed, $this->orthopedics, $this->ct]);
    // Surgery catalog work under a Therapy doctor qualifies; Therapy work under a surgeon does not.
    foreach (['surgery', 'orthopedics'] as $specialty) {
        $page->set('tableFilters.direction.value', $specialty)->assertCountTableRecords(3)
            ->assertCanSeeTableRecords([$this->surgery, $this->mixed, $this->orthopedics])
            ->assertCanNotSeeTableRecords([$this->therapy, $this->ct]);
        expect($page->instance()->getTableRecords()->pluck('id')->unique())->toHaveCount(3);
    }
    $page->set('tableFilters.direction.value', '')->assertCountTableRecords(7);
});

test('doctor-less CT remains under All while CT with an assigned matching Doctor qualifies', function () {
    $page = Livewire::test(ListVisits::class)->assertCanSeeTableRecords([$this->ct]);
    foreach (array_keys(TreatmentCase::CATEGORIES) as $specialty) {
        $page->set('tableFilters.direction.value', $specialty)->assertCanNotSeeTableRecords([$this->ct]);
    }
    $assignedCt = ($this->makeVisit)(['tomography'], ['visit_type' => 'diagnostic']);
    $page->set('tableFilters.direction.value', 'therapy')->assertCanSeeTableRecords([$assignedCt, $this->consultation, $this->uncategorized]);
    $page->set('tableFilters.direction.value', 'tomography')->assertCountTableRecords(0);
});

test('Direction intersects patient group doctor date range and search without resetting state', function () {
    $clinic = ($this->makeVisit)([], ['patient_id' => $this->clinic->id, 'doctor_id' => $this->otherDoctor->id]);
    $anotherSurgeon = Doctor::create(['first_name' => 'Another', 'last_name' => 'Surgeon', 'specialties' => ['surgery']]);
    $other = ($this->makeVisit)([], ['doctor_id' => $anotherSurgeon->id]);
    $old = ($this->makeVisit)([], ['doctor_id' => $this->otherDoctor->id, 'visit_date' => today()->subDays(15)]);
    $page = Livewire::test(ListVisits::class)->set('tableFilters.direction.value', 'surgery')->assertCountTableRecords(5)
        ->set('tableFilters.patient_group_id.value', PatientGroup::israelPartnerId())->assertCountTableRecords(4)
        ->set('tableFilters.doctor_id.value', $this->otherDoctor->id)->assertCountTableRecords(3)
        ->set('tableSearch', 'Needle')->assertCountTableRecords(3)->assertCanSeeTableRecords([$this->surgery, $this->mixed, $this->orthopedics])
        ->assertCanNotSeeTableRecords([$clinic, $other, $old]);
    $from = $page->get('tableFilters.visit_date.from');
    $until = $page->get('tableFilters.visit_date.until');
    $page->set('tableFilters.direction.value', 'therapy')->assertCountTableRecords(0)
        ->assertSet('tableFilters.patient_group_id.value', PatientGroup::israelPartnerId())
        ->assertSet('tableFilters.doctor_id.value', $this->otherDoctor->id)->assertSet('tableSearch', 'Needle')
        ->assertSet('tableFilters.visit_date.from', $from)->assertSet('tableFilters.visit_date.until', $until);
    $page->set('tableFilters.direction.value', 'orthopedics')->set('tableFilters.visit_date.from', today()->subDays(20)->toDateString())
        ->assertCountTableRecords(4)->assertCanSeeTableRecords([$old]);
    $page->set('tableSearch', 'Absent')->assertCountTableRecords(0);
});

test('Doctor-specialty Direction preserves SQL pagination totals and date ordering', function () {
    for ($i = 0; $i < 12; $i++) {
        ($this->makeVisit)(['therapy', 'surgery'], ['doctor_id' => $this->otherDoctor->id, 'visit_date' => today()->subDay()]);
    }
    $page = Livewire::test(ListVisits::class)->set('tableFilters.direction.value', 'surgery')->assertCountTableRecords(15);
    $records = $page->instance()->getTableRecords();
    expect($records->total())->toBe(15)->and($records->count())->toBe(10)->and($records->first()->visit_date->isToday())->toBeTrue();
    $page->call('gotoPage', 2)->assertCountTableRecords(15);
    expect($page->instance()->getTableRecords()->count())->toBe(5);
});

test('Dashboard has no Direction UI filter or query condition even with injected direction state', function () {
    $page = Livewire::test(Dashboard::class)->assertCountTableRecords(7);
    expect($page->html())->not->toContain('data-visits-direction', 'tableFilters.direction')
        ->and($page->instance()->getTable()->getFilter('direction'))->toBeNull();
    $before = $page->instance()->getFilteredTableQuery();
    $sql = $before->toSql();
    $bindings = $before->getConnection()->prepareBindings($before->getBindings());
    $page->set('tableFilters.direction.value', 'therapy')->assertCountTableRecords(7)
        ->assertCanSeeTableRecords([$this->therapy, $this->surgery, $this->orthopedics, $this->ct, $this->consultation, $this->uncategorized]);
    expect($page->instance()->getFilteredTableQuery()->toSql())->toBe($sql)
        ->and($before->getConnection()->prepareBindings($page->instance()->getFilteredTableQuery()->getBindings()))->toBe($bindings);
});
