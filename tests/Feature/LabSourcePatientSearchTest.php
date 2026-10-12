<?php

use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\LabPartyAutocomplete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->patient = Patient::create(['first_name' => 'ანა', 'last_name' => 'ბერიძე',
        'phone' => '599123456', 'birth_date' => '1988-03-14']);
    $this->case = LabCase::create(['source' => 'external', 'case_date' => today(),
        'external_clinic_name' => 'Other clinic', 'external_doctor_name' => 'Outside Doctor',
        'external_patient_name' => 'Outside Patient']);
    $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 2]);
    $this->case->mainWorks()->create(['material' => 'other', 'quantity' => 1]);
});

test('changing an existing Other Clinic case to Clinic resets incompatible patient state immediately', function () {
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id]);
    $page->set('data.patient_entry', 'Outside Patient')->set('data.source', 'clinic')
        ->assertSet('data.patient_id', null)->assertSet('data.patient_entry', null)
        ->assertSet('data.external_patient_name', null);
    foreach ($page->get('data.mainWorks') as $row) {
        expect($row['patient_search'])->toBeNull();
    }
    expect($this->case->fresh()->source)->toBe('external');
});

test('surname search after changing Other Clinic to Clinic selects and saves the existing patient', function () {
    $count = Patient::count();
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id])->set('data.source', 'clinic');
    $key = array_key_first($page->get('data.mainWorks'));
    $field = 'mainWorks.'.$key.'.patient_search';
    $label = app(LabPartyAutocomplete::class)->patientSuggestions('ბერიძე', 'clinic')[0];
    $page->set('data.'.$field, 'ბერიძე')
        ->assertFormFieldExists($field, fn ($component): bool => in_array($label, $component->getDatalistOptions(), true))
        ->assertSee($label)
        ->set('data.'.$field, $label)
        ->assertSet('data.patient_id', $this->patient->id)->assertSet('data.patient_entry', $label)
        ->call('save')->assertHasNoFormErrors();
    expect($this->case->fresh()->source)->toBe('clinic')
        ->and($this->case->fresh()->patient_id)->toBe($this->patient->id)
        ->and(Patient::count())->toBe($count)
        ->and($this->case->mainWorks()->count())->toBe(2);
});

test('patient lookup supports existing Clinic name phone and birth date searches', function (string $search) {
    expect(app(LabPartyAutocomplete::class)->patientSuggestions($search))->toContain($this->patient->lab_selection_label);
})->with(['ანა', 'ბერიძე', 'ანა ბერიძე', 'Beridze', 'Ana Beridze', '599123456', '14.03.1988', '1988-03-14']);

test('external to Israeli activates the same registered patient lookup without changing existing patients', function () {
    $israeli = Patient::create(['first_name' => 'Moshe', 'last_name' => 'Levi', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $count = Patient::count();
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id])->set('data.source', 'israeli');
    $key = array_key_first($page->get('data.mainWorks'));
    $page->set('data.mainWorks.'.$key.'.patient_search', 'Levi')
        ->assertFormFieldExists('mainWorks.'.$key.'.patient_search', fn ($component): bool => in_array($israeli->lab_selection_label, $component->getDatalistOptions(), true))
        ->set('data.mainWorks.'.$key.'.patient_search', $israeli->lab_selection_label)
        ->call('save')->assertHasNoFormErrors();
    expect($this->case->fresh()->source)->toBe('israeli')->and($this->case->fresh()->patient_id)->toBe($israeli->id)
        ->and(Patient::count())->toBe($count);
});

test('Other Clinic patient text remains free text and updates all repeated rows without creating a patient', function () {
    $count = Patient::count();
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id]);
    $key = array_key_first($page->get('data.mainWorks'));
    $page->set('data.mainWorks.'.$key.'.patient_search', 'Changed Outside Patient')
        ->assertSet('data.external_patient_name', 'Changed Outside Patient');
    foreach ($page->get('data.mainWorks') as $row) {
        expect($row['patient_search'])->toBe('Changed Outside Patient');
    }
    $page->call('save')->assertHasNoFormErrors();
    expect($this->case->fresh()->source)->toBe('external')->and($this->case->fresh()->patient_id)->toBeNull()
        ->and($this->case->fresh()->external_patient_name)->toBe('Changed Outside Patient')->and(Patient::count())->toBe($count);
});

test('correctly linked Clinic case remains linked when editing unrelated fields', function () {
    $clinic = LabCase::create(['source' => 'clinic', 'case_date' => today(), 'patient_id' => $this->patient->id]);
    $clinic->mainWorks()->create(['material' => 'pmma', 'quantity' => 1]);
    $count = Patient::count();
    Livewire::test(EditLabCase::class, ['record' => $clinic->id])->fillForm(['notes' => 'Updated'])->call('save')->assertHasNoFormErrors();
    expect($clinic->fresh()->patient_id)->toBe($this->patient->id)->and(Patient::count())->toBe($count);
});

test('new Clinic cases continue selecting existing patients with no duplicate', function () {
    $count = Patient::count();
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'clinic', 'mainWorks' => [['patient_search' => $this->patient->lab_selection_label, 'material' => 'pmma', 'quantity' => 1]],
    ])->callMountedAction()->assertHasNoActionErrors();
    expect(LabCase::where('source', 'clinic')->sole()->patient_id)->toBe($this->patient->id)->and(Patient::count())->toBe($count);
});

test('Clinic datalist labels remain visible to native browser filtering and resolve to existing IDs', function (string $search) {
    $options = app(LabPartyAutocomplete::class)->patientSuggestions($search, 'clinic');
    expect($options)->toHaveCount(1)->and($options[0])->toContain($search)
        ->and(app(LabPartyAutocomplete::class)->patientIdFromLabel($options[0]))->toBe($this->patient->id);
})->with(['ანა', 'ბერიძე', 'ანა ბერიძე', 'Beridze', 'Ana Beridze', '599123456', '14.03.1988', '1988-03-14']);

test('switching a legacy Other Clinic case cannot silently reuse its old linked patient', function () {
    $this->case->update(['patient_id' => $this->patient->id]);
    $count = Patient::count();
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id])->set('data.source', 'clinic')
        ->assertSet('data.patient_id', null)->call('save')->assertHasErrors(['patient_entry']);
    expect($this->case->fresh()->source)->toBe('external')->and(Patient::count())->toBe($count);
});

test('free text patient creation remains available after changing Other Clinic to Clinic', function () {
    $count = Patient::count();
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id])->set('data.source', 'clinic');
    $key = array_key_first($page->get('data.mainWorks'));
    $page->set('data.mainWorks.'.$key.'.patient_search', 'Fresh Patient')
        ->call('save')->assertHasNoFormErrors();
    expect($this->case->fresh()->source)->toBe('clinic')->and(Patient::count())->toBe($count + 1)
        ->and($this->case->fresh()->patient->patientGroup->slug)->toBe(PatientGroup::CLINIC_SLUG);
});
