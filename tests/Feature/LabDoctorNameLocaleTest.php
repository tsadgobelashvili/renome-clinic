<?php

use App\Filament\Resources\Doctors\Pages\CreateDoctor;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use App\Services\LabPartyAutocomplete;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('Lab doctor searches both stored scripts and honors manually maintained spelling', function () {
    $doctor = Doctor::create(['first_name' => 'დავით', 'last_name' => 'ჭუმბურიძე',
        'first_name_en' => 'David', 'last_name_en' => 'Chumburidze', 'is_active' => true]);
    $autocomplete = app(LabPartyAutocomplete::class);
    foreach (['davi', 'DAVID', 'დავით', 'ჭუმბურიძე', 'Chumbur', 'David Chumburidze'] as $term) {
        expect($autocomplete->doctorSuggestions($term, 'en'))->toBe(['David Chumburidze'])
            ->and($autocomplete->doctorSuggestions($term, 'ka'))->toBe(['დავით ჭუმბურიძე']);
    }
    foreach (['David Chumburidze', 'დავით ჭუმბურიძე'] as $label) {
        expect($autocomplete->doctorIdFromLabel($label))->toBe($doctor->id);
    }
    expect($autocomplete->practitionerOptionLabel($doctor->id, 'en'))->toBe('David Chumburidze')
        ->and($doctor->fresh()->full_name)->toBe('დავით ჭუმბურიძე');
});

test('English fallback is display only and preserves partial manually entered names', function () {
    $doctor = Doctor::create(['first_name' => 'დავით', 'last_name' => 'ჭუმბურიძე', 'is_active' => true]);
    expect($doctor->labDisplayName('en'))->toBe('Davit Chumburidze');
    $doctor->update(['first_name_en' => 'David']);
    expect($doctor->labDisplayName('en'))->toBe('David Chumburidze')
        ->and($doctor->labDisplayName('ka'))->toBe('დავით ჭუმბურიძე')
        ->and($doctor->fresh()->last_name_en)->toBeNull()
        ->and(Doctor::count())->toBe(1);
});

test('Doctor create and edit maintain Latin names on the same record', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    Livewire::test(CreateDoctor::class)->fillForm([
        'first_name' => 'დავით', 'last_name' => 'ჭუმბურიძე', 'first_name_en' => 'Davit', 'last_name_en' => 'Chumburidze',
    ])->call('create')->assertHasNoFormErrors();
    $doctor = Doctor::sole();
    Livewire::test(EditDoctor::class, ['record' => $doctor->id])->assertFormSet(['first_name_en' => 'Davit'])
        ->fillForm(['first_name_en' => 'David'])->call('save')->assertHasNoFormErrors();
    expect($doctor->fresh()->first_name_en)->toBe('David')->and($doctor->fresh()->last_name_en)->toBe('Chumburidze')
        ->and($doctor->fresh()->full_name)->toBe('დავით ჭუმბურიძე')->and(Doctor::count())->toBe(1);
});

test('Lab create selection and edit hydration retain localized name and existing doctor id', function (string $locale, string $label) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN, 'locale' => $locale]));
    app()->setLocale($locale);
    $doctor = Doctor::create(['first_name' => 'დავით', 'last_name' => 'ჭუმბურიძე',
        'first_name_en' => 'David', 'last_name_en' => 'Chumburidze', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Test', 'last_name' => 'Patient']);
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'clinic', 'case_date' => today()->toDateString(),
        'mainWorks' => [['patient_search' => $patient->lab_selection_label,
            'doctor_search' => $label, 'material' => 'zircon', 'quantity' => 1]],
    ])->assertFormFieldExists('mainWorks.0.doctor_search', fn ($field): bool => $field instanceof Select
        && in_array($label, $field->getSearchResults('davi'), true)
        && in_array($label, $field->getSearchResults('დავით'), true)
        && $field->getOptionLabel() === $label)
        ->assertActionDataSet(['doctor_id' => $doctor->id])
        ->callMountedAction()->assertHasNoActionErrors();
    $case = LabCase::sole();
    $page = Livewire::test(EditLabCase::class, ['record' => $case->id]);
    expect(collect($page->get('data.mainWorks'))->first()['doctor_search'])->toBe($label);
    $page->fillForm(['notes' => 'Same doctor'])->call('save')->assertHasNoFormErrors();
    expect($case->fresh()->doctor_id)->toBe($doctor->id)->and(Doctor::count())->toBe(1);
})->with(['English' => ['en', 'David Chumburidze'], 'Georgian' => ['ka', 'დავით ჭუმბურიძე']]);
