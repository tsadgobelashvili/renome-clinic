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
    $this->doctor = Doctor::create(['first_name' => 'Levan', 'last_name' => 'Direction', 'is_active' => true]);
    $this->otherDoctor = Doctor::create(['first_name' => 'Other', 'last_name' => 'Direction', 'is_active' => true]);
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
    $this->therapy = ($this->makeVisit)(['therapy']);
    $this->surgery = ($this->makeVisit)(['surgery']);
    $this->mixed = ($this->makeVisit)(['therapy', 'surgery']);
    $this->orthopedics = ($this->makeVisit)(['orthopedics']);
    $this->ct = ($this->makeVisit)(['tomography'], ['visit_type' => 'diagnostic']);
    $this->consultation = ($this->makeVisit)(['consultation'], ['visit_type' => 'consultation']);
    $this->uncategorized = ($this->makeVisit)([]);
    $this->uncategorized->treatmentCaseItems()->create(['custom_service_name' => 'Therapy Surgery free text', 'quantity' => 1, 'unit_price' => 100]);
});

test('standalone Direction options are the managed catalog categories in either locale', function (string $locale) {
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
    expect($options)->toEqual(['' => __('visits.all')] + TreatmentCase::categoryOptions())
        ->and($options)->toHaveKey($custom->id);
})->with(['ka', 'en']);

test('All preserves results and mixed visits match each direction only once', function () {
    $page = Livewire::test(ListVisits::class)->assertCountTableRecords(7);
    $page->set('tableFilters.direction.value', 'therapy')->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$this->therapy, $this->mixed])
        ->assertCanNotSeeTableRecords([$this->surgery, $this->orthopedics, $this->ct, $this->consultation, $this->uncategorized]);
    expect($page->instance()->getTableRecords()->pluck('id')->all())->toHaveCount(2);
    $page->set('tableFilters.direction.value', 'surgery')->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$this->surgery, $this->mixed])->assertCanNotSeeTableRecords([$this->therapy]);
    $page->set('tableFilters.direction.value', '')->assertCountTableRecords(7);
});

test('CT consultation and uncategorized work follow actual catalog classification', function () {
    $page = Livewire::test(ListVisits::class);
    $page->set('tableFilters.direction.value', 'tomography')->assertCountTableRecords(1)->assertCanSeeTableRecords([$this->ct]);
    $page->set('tableFilters.direction.value', 'consultation')->assertCountTableRecords(1)->assertCanSeeTableRecords([$this->consultation]);
    $page->set('tableFilters.direction.value', 'other')->assertCountTableRecords(0);
    $page->set('tableFilters.direction.value', '')->assertCanSeeTableRecords([$this->uncategorized]);
});

test('Direction intersects patient group doctor date range and search without resetting state', function () {
    $clinic = ($this->makeVisit)(['orthopedics'], ['patient_id' => $this->clinic->id]);
    $other = ($this->makeVisit)(['orthopedics'], ['doctor_id' => $this->otherDoctor->id]);
    $old = ($this->makeVisit)(['orthopedics'], ['visit_date' => today()->subDays(15)]);
    $page = Livewire::test(ListVisits::class)->set('tableFilters.direction.value', 'orthopedics')->assertCountTableRecords(3)
        ->set('tableFilters.patient_group_id.value', PatientGroup::israelPartnerId())->assertCountTableRecords(2)
        ->set('tableFilters.doctor_id.value', $this->doctor->id)->assertCountTableRecords(1)
        ->set('tableSearch', 'Needle')->assertCountTableRecords(1)->assertCanSeeTableRecords([$this->orthopedics])
        ->assertCanNotSeeTableRecords([$clinic, $other, $old]);
    $from = $page->get('tableFilters.visit_date.from');
    $until = $page->get('tableFilters.visit_date.until');
    $page->set('tableFilters.direction.value', 'surgery')->assertCountTableRecords(2)
        ->assertSet('tableFilters.patient_group_id.value', PatientGroup::israelPartnerId())
        ->assertSet('tableFilters.doctor_id.value', $this->doctor->id)->assertSet('tableSearch', 'Needle')
        ->assertSet('tableFilters.visit_date.from', $from)->assertSet('tableFilters.visit_date.until', $until);
    $page->set('tableFilters.direction.value', 'orthopedics')->set('tableFilters.visit_date.from', today()->subDays(20)->toDateString())
        ->assertCountTableRecords(2)->assertCanSeeTableRecords([$this->orthopedics, $old]);
    $page->set('tableSearch', 'Absent')->assertCountTableRecords(0);
});

test('Direction preserves SQL pagination totals and date ordering without duplicate mixed rows', function () {
    for ($i = 0; $i < 12; $i++) {
        ($this->makeVisit)(['therapy', 'surgery'], ['visit_date' => today()->subDay()]);
    }
    $page = Livewire::test(ListVisits::class)->set('tableFilters.direction.value', 'therapy')->assertCountTableRecords(14);
    $records = $page->instance()->getTableRecords();
    expect($records->total())->toBe(14)->and($records->count())->toBe(10)->and($records->first()->visit_date->isToday())->toBeTrue();
    $page->call('gotoPage', 2)->assertCountTableRecords(14);
    expect($page->instance()->getTableRecords()->count())->toBe(4);
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
