<?php

use App\Filament\Resources\Visits\Pages\ListVisits;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-13 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Levan', 'last_name' => 'Doctor', 'is_active' => true]);
    $this->otherDoctor = Doctor::create(['first_name' => 'Other', 'last_name' => 'Doctor', 'is_active' => true]);
    $clinic = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Patient']);
    $israeli = Patient::create(['first_name' => 'Israeli', 'last_name' => 'Needle', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $this->clinicVisit = Visit::create(['patient_id' => $clinic->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today(), 'currency' => 'GEL']);
    $this->ctVisit = Visit::create(['patient_id' => $israeli->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today(), 'visit_type' => 'diagnostic', 'currency' => 'GEL']);
    $ct = TreatmentCase::create(['name' => '3D CT', 'category' => 'tomography', 'is_active' => true]);
    $this->ctVisit->treatmentCaseItems()->create(['treatment_case_id' => $ct->id, 'quantity' => 1, 'unit_price' => 100]);
    $this->otherVisit = Visit::create(['patient_id' => $israeli->id, 'doctor_id' => $this->otherDoctor->id, 'visit_date' => today(), 'currency' => 'GEL']);
    $this->oldVisit = Visit::create(['patient_id' => $israeli->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today()->subDays(15), 'currency' => 'GEL']);
});

test('toolbar exposes only All Clinic and Israeli group choices beside Doctor', function (string $locale) {
    app()->setLocale($locale);
    $page = Livewire::test(ListVisits::class);
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="UTF-8">'.$page->html());
    $xpath = new DOMXPath($document);
    $group = $xpath->query('//*[@data-visits-patient-group]')->item(0);
    expect($group)->not->toBeNull();
    $select = $xpath->query('.//select', $group)->item(0);
    expect($select->getAttribute('wire:model.live'))->toBe('tableFilters.patient_group_id.value')
        ->and($select->getAttribute('aria-label'))->toBe(__('visits.patient_group'));
    $labels = [];
    foreach ($xpath->query('./option', $select) as $option) {
        $labels[] = trim($option->textContent);
    }
    expect($labels)->toEqual([__('visits.all'), __('visits.clinic'), __('visits.israeli')])
        ->and($xpath->query('preceding-sibling::label[1]//select[@*[name()="wire:model.live"]="tableFilters.doctor_id.value"]', $group))->toHaveCount(1);
})->with(['ka', 'en']);

test('All preserves visits while Clinic and Israeli use actual patient groups including CT-only patients', function () {
    $page = Livewire::test(ListVisits::class)->assertCountTableRecords(3)
        ->assertCanSeeTableRecords([$this->clinicVisit, $this->ctVisit, $this->otherVisit]);
    $page->set('tableFilters.patient_group_id.value', PatientGroup::clinicId())->assertCountTableRecords(1)
        ->assertCanSeeTableRecords([$this->clinicVisit])->assertCanNotSeeTableRecords([$this->ctVisit, $this->otherVisit]);
    $page->set('tableFilters.patient_group_id.value', PatientGroup::israelPartnerId())->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$this->ctVisit, $this->otherVisit])->assertCanNotSeeTableRecords([$this->clinicVisit]);
    $page->set('tableFilters.patient_group_id.value', '')->assertCountTableRecords(3);
});

test('Patient Group intersects doctor date and search without resetting their state', function () {
    $page = Livewire::test(ListVisits::class)
        ->set('tableFilters.doctor_id.value', $this->doctor->id)->set('tableSearch', 'Needle')
        ->set('tableFilters.patient_group_id.value', PatientGroup::israelPartnerId())
        ->assertCountTableRecords(1)->assertCanSeeTableRecords([$this->ctVisit])
        ->assertSet('tableFilters.doctor_id.value', $this->doctor->id)->assertSet('tableSearch', 'Needle');
    $from = $page->get('tableFilters.visit_date.from');
    $until = $page->get('tableFilters.visit_date.until');
    $page->set('tableFilters.patient_group_id.value', PatientGroup::clinicId())->assertCountTableRecords(0)
        ->assertSet('tableFilters.visit_date.from', $from)->assertSet('tableFilters.visit_date.until', $until);
    $page->set('tableFilters.patient_group_id.value', PatientGroup::israelPartnerId())
        ->set('tableFilters.visit_date.from', today()->subDays(20)->toDateString())->assertCountTableRecords(2)
        ->assertCanSeeTableRecords([$this->ctVisit, $this->oldVisit]);
});

test('Patient Group keeps pagination totals and ordering in the filtered SQL query', function () {
    for ($index = 0; $index < 12; $index++) {
        Visit::create(['patient_id' => $this->ctVisit->patient_id, 'doctor_id' => $this->doctor->id,
            'visit_date' => today()->subDay(), 'currency' => 'GEL']);
    }
    $page = Livewire::test(ListVisits::class)->set('tableFilters.patient_group_id.value', PatientGroup::israelPartnerId())
        ->assertCountTableRecords(14)->assertCanNotSeeTableRecords([$this->clinicVisit, $this->oldVisit]);
    $records = $page->instance()->getTableRecords();
    expect($records->total())->toBe(14)->and($records->count())->toBe(10)
        ->and($records->first()->visit_date->isToday())->toBeTrue();
    $page->call('gotoPage', 2)->assertCountTableRecords(14);
    expect($page->instance()->getTableRecords()->count())->toBe(4);
});
