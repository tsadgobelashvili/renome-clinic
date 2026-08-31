<?php

use App\Filament\Resources\Doctors\Pages\ListDoctors;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('patients list uses the compact toolbar while search and filters still work', function () {
    $this->actingAs(User::factory()->create());
    $clinic = PatientGroup::query()->where('slug', PatientGroup::CLINIC_SLUG)->sole();
    $patient = Patient::create(['first_name' => 'Toolbar', 'last_name' => 'Patient']);
    $other = Patient::create(['first_name' => 'Different', 'last_name' => 'Person']);

    $component = Livewire::test(ListPatients::class)
        ->assertSuccessful()
        ->assertSeeHtml('renome-patients-list-page')
        ->assertDontSeeHtml('class="fi-header-heading"')
        ->assertDontSeeHtml('class="fi-breadcrumbs"')
        ->assertTableActionExists('create')
        ->assertTableActionExists('quickDebtFilter')
        ->searchTable('Toolbar')
        ->assertCanSeeTableRecords([$patient])
        ->assertCanNotSeeTableRecords([$other]);

    Livewire::test(ListPatients::class)
        ->filterTable('patient_group_id', $clinic->getKey())
        ->assertCanSeeTableRecords([$patient, $other]);

    expect($component->instance()->getHeading())->toBeNull()
        ->and($component->instance()->getBreadcrumbs())->toBe([]);
});

test('doctors list uses the compact toolbar while search and create remain available', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'Toolbar', 'last_name' => 'Doctor', 'is_active' => true]);
    $other = Doctor::create(['first_name' => 'Different', 'last_name' => 'Doctor', 'is_active' => true]);

    $component = Livewire::test(ListDoctors::class)
        ->assertSuccessful()
        ->assertSeeHtml('renome-doctors-list-page')
        ->assertDontSeeHtml('class="fi-header-heading"')
        ->assertDontSeeHtml('class="fi-breadcrumbs"')
        ->assertTableActionExists('create')
        ->searchTable('Toolbar')
        ->assertCanSeeTableRecords([$doctor])
        ->assertCanNotSeeTableRecords([$other]);

    expect($component->instance()->getHeading())->toBeNull()
        ->and($component->instance()->getBreadcrumbs())->toBe([]);
});
