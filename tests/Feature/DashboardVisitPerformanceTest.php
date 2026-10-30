<?php

use App\Filament\Pages\Dashboard;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard new visit action mount loads doctor options without unrelated dashboard queries', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'Mount', 'last_name' => 'Doctor', 'is_active' => true]);
    $treatment = TreatmentCase::create(['name' => 'Mount treatment', 'category' => 'tomography', 'is_active' => true]);
    for ($i = 0; $i < 25; $i++) {
        $patient = Patient::create(['first_name' => 'Mount', 'last_name' => (string) $i]);
        $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 100]);
        $visit->treatmentCaseItems()->create(['treatment_case_id' => $treatment->id, 'quantity' => 1, 'unit_price' => 100]);
        $visit->payments()->create(['amount' => 100, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);
    }
    $component = Livewire::test(Dashboard::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $started = hrtime(true);
    try {
        $component->mountAction('newVisit')->assertActionMounted('newVisit');
        $queries = collect(DB::getQueryLog());
        $elapsed = round((hrtime(true) - $started) / 1e6, 2);
    } finally {
        DB::disableQueryLog();
    }
    file_put_contents(storage_path('app/dashboard-visit-mount.json'), json_encode([
        'queries' => $queries->count(), 'elapsed_ms' => $elapsed,
        'sql_ms' => $queries->sum('time'), 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        'sql' => $queries->sortByDesc('time')->values()->all(),
    ], JSON_PRETTY_PRINT));
    expect($component->instance()->getMountedAction()->getName())->toBe('newVisit')
        ->and($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect($query['query'])->toContain('from "doctors"')->not->toContain('select *');
    }
});

test('new visit preloads active doctors like tomography and retains bounded patient and doctor search', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Searchable', 'last_name' => 'Person', 'phone' => '555123456', 'personal_id' => '01001012345']);
    $doctor = Doctor::create(['first_name' => 'Searchable', 'last_name' => 'Doctor', 'is_active' => true]);
    $inactive = Doctor::create(['first_name' => 'Searchable', 'last_name' => 'Inactive', 'is_active' => false]);
    $component = Livewire::test(Dashboard::class)->mountAction('newVisit');
    $fields = collect($component->instance()->getSchema('mountedActionSchema0')->getFlatFields());
    $patientField = $fields->first(fn ($field) => $field->getName() === 'patient_id');
    $doctorField = $fields->first(fn ($field) => $field->getName() === 'doctor_id');
    expect($patientField->isPreloaded())->toBeFalse()->and($doctorField->isPreloaded())->toBeTrue()
        ->and($doctorField->isNative())->toBeFalse()
        ->and($doctorField->getOptions())->toHaveKey($doctor->id)->not->toHaveKey($inactive->id);
    foreach (['Searchable', '555123456', '01001012345'] as $search) {
        expect($patientField->getSearchResults($search))->toHaveKey($patient->id);
    }
    expect($doctorField->getSearchResults('Searchable'))->toHaveKey($doctor->id)->not->toHaveKey($inactive->id);
    expect($doctorField->getSearchResults('DOCTOR SEARCHABLE'))->toHaveKey($doctor->id)
        ->and($doctorField->getSearchResults('No matching name'))->toBeEmpty();
    $linked = Doctor::create(['first_name' => 'Zed', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient->doctors()->attach($linked->id, ['role' => 'other']);
    $component->fillForm(['patient_id' => $patient->id], 'mountedActionSchema0');
    $doctorField = collect($component->instance()->getSchema('mountedActionSchema0')->getFlatFields())
        ->first(fn ($field) => $field->getName() === 'doctor_id');
    expect(array_key_first($doctorField->getSearchResults('Doctor')))->toBe($linked->id);
    $tomography = Livewire::test(Dashboard::class)->mountAction('manageTomography');
    $tomographyDoctor = collect($tomography->instance()->getSchema('mountedActionSchema0')->getFlatFields())
        ->first(fn ($field) => $field->getName() === 'doctor_id');
    expect($tomographyDoctor->isSearchable())->toBeTrue()->and($tomographyDoctor->isNative())->toBeFalse()
        ->and($tomographyDoctor->getOptions())->toBe($doctorField->getOptions());
    for ($i = 0; $i < 55; $i++) {
        Patient::create(['first_name' => 'Bounded', 'last_name' => (string) $i]);
    }
    expect($patientField->getSearchResults('Bounded'))->toHaveCount(50);
});

test('dashboard doctor selection persists through visit and tomography modal submission', function (string $action) {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Selected', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Chosen', 'last_name' => 'Doctor', 'is_active' => true]);
    $service = TreatmentCase::create(['name' => 'Selected service', 'category' => $action === 'newVisit' ? 'therapy' : 'tomography', 'is_active' => true]);
    $items = [['treatment_case_id' => $service->id, 'quantity' => 1, 'unit_price' => 100]];
    $data = ['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today()->toDateString(),
        'currency' => 'GEL', 'paymentSplits' => []];
    if ($action === 'newVisit') {
        $data += ['visit_type' => 'treatment', 'treatmentCaseItems' => $items, 'discount_percent' => 0];
    } else {
        $data += ['consultation_source' => 'our_patient', 'tomographyItems' => $items, 'amount' => 100];
    }
    Livewire::test(Dashboard::class)->callAction($action, $data)->assertHasNoActionErrors();
    expect(Visit::sole()->doctor_id)->toBe($doctor->id)->and(Visit::sole()->patient_id)->toBe($patient->id);
})->with(['newVisit', 'manageTomography']);
