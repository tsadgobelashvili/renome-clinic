<?php
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
uses(RefreshDatabase::class);

test('patient birthdays can be typed created edited cleared and validated', function ($create, $edit, $group) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    Livewire::test($create)->fillForm(['first_name' => 'Birthday', 'last_name' => 'Test', 'phone' => '555123456',
        'patient_group_id' => $group === 'clinic' ? PatientGroup::clinicId() : PatientGroup::israelPartnerId(),
        'birth_date' => '15.04.1992'])->call('create')->assertHasNoFormErrors();
    $patient = Patient::where('first_name', 'Birthday')->sole();
    expect($patient->birth_date->format('Y-m-d'))->toBe('1992-04-15');
    $page = Livewire::test($edit, ['record' => $patient->id])->assertFormSet(['birth_date' => '15.04.1992']);
    $page->fillForm(['birth_date' => '31.02.1992'])->call('save')->assertHasFormErrors(['birth_date']);
    expect($patient->fresh()->birth_date->format('Y-m-d'))->toBe('1992-04-15');
    $page->fillForm(['birth_date' => '29.02.1992'])->call('save')->assertHasNoFormErrors();
    expect($patient->fresh()->birth_date->format('Y-m-d'))->toBe('1992-02-29');
    $page->fillForm(['birth_date' => ''])->call('save')->assertHasNoFormErrors();
    expect($patient->fresh()->birth_date)->toBeNull();
})->with([
    [\App\Filament\Resources\Patients\Pages\CreatePatient::class, \App\Filament\Resources\Patients\Pages\EditPatient::class, 'clinic'],
    [\App\Filament\Resources\PartnerPatients\Pages\CreatePartnerPatient::class, \App\Filament\Resources\PartnerPatients\Pages\EditPartnerPatient::class, 'israeli'],
]);
