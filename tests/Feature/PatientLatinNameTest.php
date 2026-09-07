<?php

use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use App\Services\LabPartyAutocomplete;
use App\Support\GeorgianNameTransliterator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('Georgian names use one deterministic Latin transliteration', function () {
    expect(GeorgianNameTransliterator::transliterate('გიორგი'))->toBe('Giorgi')
        ->and(GeorgianNameTransliterator::transliterate('ბერიძე'))->toBe('Beridze')
        ->and(GeorgianNameTransliterator::transliterate('ჭიჭინაძე'))->toBe('Chichinadze')
        ->and(GeorgianNameTransliterator::transliterate('David'))->toBeNull();
});

test('clinic patient creation and ordinary Georgian name changes generate Latin fields', function () {
    $patient = Patient::create(['first_name' => 'გიორგი', 'last_name' => 'ბერიძე', 'phone' => '555100001']);
    expect($patient->first_name_latin)->toBe('Giorgi')
        ->and($patient->last_name_latin)->toBe('Beridze')
        ->and($patient->lab_name)->toBe('Giorgi Beridze');

    $patient->update(['first_name' => 'გივი', 'last_name' => 'მაისურაძე']);
    expect($patient->fresh()->first_name_latin)->toBe('Givi')
        ->and($patient->fresh()->last_name_latin)->toBe('Maisuradze');
});

test('manual Latin corrections survive Georgian source changes until explicit regeneration', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $patient = Patient::create(['first_name' => 'გიორგი', 'last_name' => 'ბერიძე', 'phone' => '555100003']);
    $patient->update(['first_name_latin' => 'George']);
    $patient->update(['first_name' => 'გივი']);
    expect($patient->fresh()->first_name_latin)->toBe('George');

    Livewire::actingAs($owner)->test(EditPatient::class, ['record' => $patient->id])
        ->assertFormFieldExists('first_name_latin')
        ->callFormComponentAction('first_name_latin', 'regenerate_first_name_latin')
        ->assertSchemaStateSet(['first_name_latin' => 'Givi'])
        ->call('save')->assertHasNoFormErrors();
    expect($patient->fresh()->first_name_latin)->toBe('Givi');
});

test('owners and administrators can edit Latin fields', function (string $role) {
    $patient = Patient::create(['first_name' => 'ნინო', 'last_name' => 'გელაშვილი', 'phone' => '555100002']);
    Livewire::actingAs(User::factory()->create(['role' => $role]))
        ->test(EditPatient::class, ['record' => $patient->id])
        ->assertFormFieldExists('first_name_latin')->assertFormFieldExists('last_name_latin')
        ->fillForm(['first_name_latin' => 'Ninoh', 'last_name_latin' => 'Gelashvili'])
        ->call('save')->assertHasNoFormErrors();
    expect($patient->fresh()->first_name_latin)->toBe('Ninoh');
})->with([User::ROLE_OWNER, User::ROLE_ADMINISTRATOR]);

test('patient searches match Georgian and Latin fields without creating duplicates', function () {
    $patient = Patient::create(['first_name' => 'ლევანი', 'last_name' => 'ქავთარაძე']);
    foreach (['ლევანი', 'ქავთარაძე', 'Levani', 'Kavtaradze', 'Levani Kavtaradze'] as $search) {
        expect(Patient::query()->searchForClinic($search)->sole()->is($patient))->toBeTrue()
            ->and(Patient::query()->searchForLab($search)->sole()->is($patient))->toBeTrue();
    }
    expect(Patient::count())->toBe(1);
});

test('Laboratory list form and autocomplete prefer generated Latin names while existing Latin patients remain unchanged', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $clinic = Patient::create(['first_name' => 'მარიამ', 'last_name' => 'შავაევა', 'birth_date' => '1990-01-02']);
    $israeli = Patient::create(['first_name' => 'Sharon', 'last_name' => 'David']);
    $case = LabCase::create(['patient_id' => $clinic->id, 'case_date' => today(), 'source' => 'clinic']);
    $case->mainWorks()->create(['material' => 'pmma', 'quantity' => 1]);

    expect($clinic->lab_name)->toBe('Mariam Shavaeva')
        ->and($israeli->lab_name)->toBe('Sharon David')
        ->and(app(LabPartyAutocomplete::class)->patientSuggestions('Mariam'))->toContain('Mariam Shavaeva — 02.01.1990');

    Livewire::actingAs($owner)->test(ListLabCases::class)
        ->assertCanSeeTableRecords([$case])->assertSee('Mariam Shavaeva')
        ->mountAction('create')->fillForm([
            'source' => 'clinic',
            'mainWorks' => [[
                'patient_search' => 'Mariam Shavaeva — 02.01.1990',
                'material' => 'pmma', 'quantity' => 1,
            ]],
        ])->callMountedAction()->assertHasNoFormErrors();
    expect(LabCase::latest('id')->first()->patient_id)->toBe($clinic->id);
});

test('transliteration updates do not alter existing patient relationships', function () {
    $patient = Patient::create(['first_name' => 'ანა', 'last_name' => 'ლომიძე']);
    $case = LabCase::create(['patient_id' => $patient->id, 'case_date' => today(), 'source' => 'clinic']);
    $patient->update(['last_name' => 'ბერიძე']);
    expect($case->fresh()->patient_id)->toBe($patient->id)
        ->and($patient->fresh()->labCases()->sole()->is($case));
});
