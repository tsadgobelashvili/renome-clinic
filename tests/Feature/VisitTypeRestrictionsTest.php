<?php

use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->patient = Patient::create(['first_name' => 'Visit', 'last_name' => 'Types']);
});

test('consultation permits catalog consultations and imaging without requiring treatment', function (string $name, string $category) {
    $service = TreatmentCase::create(['name' => $name, 'category' => $category, 'is_active' => true]);
    expect(VisitForm::treatmentCaseSearchResults($name, 'consultation'))->toHaveKey($service->id);
    Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $this->patient->id, 'visit_type' => 'consultation', 'consultation_fee' => 20,
        'treatmentCaseItems' => [['treatment_case_id' => $service->id, 'quantity' => 1, 'unit_price' => 30]],
    ])->call('create')->assertHasNoFormErrors();
    expect(Visit::sole()->visit_type)->toBe('consultation');
})->with([['Consultation', 'consultation'], ['Periodontal consultation', 'consultation'], ['3D CT', 'tomography'], ['Panorama', 'tomography'], ['Plan preparation', 'consultation']]);

test('consultation fee alone saves and treatment accepts ordinary procedures', function () {
    Livewire::test(CreateVisit::class)->fillForm(['patient_id' => $this->patient->id, 'visit_type' => 'consultation', 'consultation_fee' => 40, 'treatmentCaseItems' => []])
        ->call('create')->assertHasNoFormErrors();
    $service = TreatmentCase::create(['name' => 'Filling', 'category' => 'therapy', 'is_active' => true]);
    Livewire::test(CreateVisit::class)->fillForm(['patient_id' => $this->patient->id, 'visit_type' => 'treatment',
        'treatmentCaseItems' => [['treatment_case_id' => $service->id, 'quantity' => 1, 'unit_price' => 50]],
    ])->call('create')->assertHasNoFormErrors();
    expect(Visit::count())->toBe(2);
});

test('switching to consultation preserves incompatible work and blocks save including forged dashboard input', function () {
    $service = TreatmentCase::create(['name' => 'Implant', 'category' => 'surgery', 'is_active' => true]);
    expect(VisitForm::treatmentCaseSearchResults('Implant', 'consultation'))->toBe([]);
    $items = [['treatment_case_id' => $service->id, 'quantity' => 1, 'unit_price' => 50]];
    $page = Livewire::test(CreateVisit::class)->fillForm(['patient_id' => $this->patient->id, 'visit_type' => 'treatment', 'treatmentCaseItems' => $items])
        ->set('data.visit_type', 'consultation')->call('create')->assertHasFormErrors(['treatmentCaseItems']);
    expect(collect($page->instance()->form->getRawState()['treatmentCaseItems'])->first()['treatment_case_id'])->toBe($service->id);
    expect(fn () => VisitForm::createDashboardVisit(['patient_id' => $this->patient->id, 'visit_type' => 'consultation', 'visit_date' => today(), 'treatmentCaseItems' => $items]))->toThrow(ValidationException::class);
    expect(Visit::count())->toBe(0);
});

test('historical incompatible consultation remains readable but editing requires correction', function () {
    $service = TreatmentCase::create(['name' => 'Legacy filling', 'category' => 'therapy', 'is_active' => true]);
    $visit = Visit::create(['patient_id' => $this->patient->id, 'visit_type' => 'consultation', 'visit_date' => today()]);
    $item = $visit->treatmentCaseItems()->create(['treatment_case_id' => $service->id, 'quantity' => 1, 'unit_price' => 50]);
    Livewire::test(EditVisit::class, ['record' => $visit->id])->assertOk()->call('save')->assertHasFormErrors(['treatmentCaseItems']);
    expect($item->fresh()->treatment_case_id)->toBe($service->id)->and($visit->fresh()->visit_type)->toBe('consultation');
});
