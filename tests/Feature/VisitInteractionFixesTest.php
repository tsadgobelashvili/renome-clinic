<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinanceReports;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Filament\Resources\Visits\Schemas\VisitForm;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Filament\Forms\Components\ToggleButtons;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->patient = Patient::create(['first_name' => 'Diagnostic', 'last_name' => 'Test']);
});

test('editing a visit saves then redirects to dashboard', function () {
    $visit = Visit::create(['patient_id' => $this->patient->id, 'visit_date' => today(), 'visit_type' => 'consultation']);
    Livewire::test(EditVisit::class, ['record' => $visit->id])->fillForm(['comment' => 'Updated comment'])->call('save')
        ->assertHasNoFormErrors()->assertRedirect(Dashboard::getUrl());
    expect($visit->fresh()->comment)->toBe('Updated comment');
});

test('multi word manipulation typing preserves spaces and original input while resolving catalog identity', function () {
    $name = 'პაროდონტოლოგიური კონსულტაცია';
    $catalog = TreatmentCase::create(['name' => $name, 'category' => 'consultation', 'is_active' => true, 'default_price' => 50]);
    $page = Livewire::test(CreateVisit::class)->fillForm(['patient_id' => $this->patient->id, 'visit_type' => 'treatment']);
    $page->call('enableSchemaStateUpdateHooksForTesting');
    $key = array_key_first($page->instance()->form->getRawState()['treatmentCaseItems']);
    $path = "data.treatmentCaseItems.{$key}.manipulation_name";
    foreach (['პაროდონტოლოგიური', 'პაროდონტოლოგიური ', 'პაროდონტოლოგიური კ', $name, $name.' '] as $text) {
        $page->set($path, $text)->assertSet($path, $text);
    }
    $page->assertSet("data.treatmentCaseItems.{$key}.treatment_case_id", $catalog->id);
    expect(VisitForm::treatmentCaseSearchResults('პაროდონტოლოგიური კ', 'consultation'))->toHaveKey($catalog->id);
});

test('dashboard tomography creates a diagnostic visit with unchanged payment and no consultation cohort', function () {
    $ct = TreatmentCase::create(['name' => '3D CT', 'category' => 'tomography', 'default_price' => 100, 'is_active' => true]);
    Livewire::test(Dashboard::class)->callAction('manageTomography', [
        'patient_id' => $this->patient->id, 'currency' => 'GEL', 'consultation_source' => 'our_patient',
        'tomographyItems' => [['treatment_case_id' => $ct->id, 'quantity' => 1, 'unit_price' => 100]],
        'amount' => 100, 'paymentSplits' => [['payment_method' => 'cash', 'amount' => 100, 'currency' => 'GEL']],
    ])->assertHasNoActionErrors();
    $visit = Visit::sole();
    expect($visit->visit_type)->toBe('diagnostic')->and($visit->type_label)->toBe('დიაგნოსტიკა')
        ->and((float) $visit->payments()->sum('amount'))->toBe(100.0);
    Livewire::test(EditVisit::class, ['record' => $visit->id])
        ->assertFormSet(['visit_type' => 'diagnostic'])
        ->assertFormFieldIsHidden('visit_type')
        ->fillForm(['comment' => 'Diagnostic edit'])
        ->call('save')->assertHasNoFormErrors();
    expect($visit->fresh()->visit_type)->toBe('diagnostic')
        ->and($visit->fresh()->comment)->toBe('Diagnostic edit')
        ->and((float) $visit->payments()->sum('amount'))->toBe(100.0);
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 0 && $stats['tomography']['ct']['patients'] === 1);
    // A real consultation containing the same imaging service is still a consultation.
    $real = Visit::create(['patient_id' => $this->patient->id, 'visit_date' => today(), 'visit_type' => 'consultation', 'consultation_fee' => 20]);
    $real->treatmentCaseItems()->create(['treatment_case_id' => $ct->id, 'quantity' => 1, 'unit_price' => 100]);
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 1 && $stats['consultations']['started'] === 0);
});

test('manual visit mode choices exclude diagnostic', function () {
    Livewire::test(CreateVisit::class)->assertFormFieldExists('visit_type', fn ($field) => array_keys($field->getOptions()) === ['treatment', 'consultation']);
    $visit = Visit::create(['patient_id' => $this->patient->id, 'visit_date' => today(), 'visit_type' => 'consultation']);
    Livewire::test(EditVisit::class, ['record' => $visit->id])->assertFormFieldExists('visit_type', fn ($field) => array_keys($field->getOptions()) === ['treatment', 'consultation']);
    $field = collect(VisitForm::dashboardCreateSchema())->first(fn ($field) => $field instanceof ToggleButtons && $field->getName() === 'visit_type');
    expect(array_keys($field->getOptions()))->toBe(['treatment', 'consultation']);
});
