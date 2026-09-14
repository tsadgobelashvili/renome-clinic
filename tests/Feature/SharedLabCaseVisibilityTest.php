<?php

use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\LabTechnicianRate;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->previous = User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]);
    $this->shared = User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]);
    $this->patient = Patient::create(['first_name' => 'Historical', 'last_name' => 'Lab Patient']);
    $this->doctor = Doctor::create(['first_name' => 'Lab', 'last_name' => 'Doctor', 'is_active' => true]);
    $this->cases = collect();
    foreach (['clinic' => $this->owner->id, 'israeli' => $this->previous->id, 'external' => null] as $source => $creator) {
        $case = LabCase::create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id,
            'case_date' => today()->subMonths(2), 'source' => $source, 'created_by' => $creator]);
        $case->mainWorks()->create(['material' => 'pmma', 'quantity' => 1]);
        $this->cases->push($case);
    }
    $this->actingAs($this->shared);
});

test('shared technician sees historical owner previous user and unauthored cases across all lab sources', function () {
    expect(LabCaseResource::getEloquentQuery()->pluck('lab_cases.id')->all())->toEqualCanonicalizing($this->cases->pluck('id')->all());
    // Existing date filters still apply: All reveals the historical records.
    Livewire::test(ListLabCases::class)->assertCanNotSeeTableRecords($this->cases)
        ->call('applyDatePeriod', 'all')->assertCanSeeTableRecords($this->cases);
    $owner = $this->cases->first();
    LabTechnicianRate::create(['technician_id' => $this->previous->id, 'work_type' => 'zirconia',
        'component_type' => 'production', 'rate_per_unit' => 20, 'is_active' => true]);
    $owner->workItems()->create(['work_type' => 'zirconia', 'component_type' => 'production', 'quantity' => 1,
        'technician_id' => $this->previous->id, 'work_date' => $owner->case_date, 'status' => 'completed']);
    expect(LabCaseResource::getEloquentQuery()->findOrFail($owner->id)->workItems)->toHaveCount(1);
});

test('shared technician edits an owner case without changing creator audit or delete permissions', function () {
    $case = $this->cases->first();
    $this->get(LabCaseResource::getUrl('edit', ['record' => $case]))->assertOk();
    Livewire::test(EditLabCase::class, ['record' => $case->id])->fillForm(['notes' => 'Shared account updated history'])
        ->call('save')->assertHasNoFormErrors();
    expect($case->fresh()->notes)->toBe('Shared account updated history')
        ->and($case->fresh()->created_by)->toBe($this->owner->id)->and(LabCaseResource::canDelete($case))->toBeFalse();
});

test('new cases from shared account and owner remain visible together', function () {
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'clinic', 'case_date' => today()->toDateString(),
        'mainWorks' => [['patient_search' => 'New Shared Patient', 'material' => 'pmma', 'quantity' => 1]],
    ])->callMountedAction()->assertHasNoActionErrors();
    $new = LabCase::latest('id')->first();
    expect($new->created_by)->toBe($this->shared->id);
    Livewire::test(ListLabCases::class)->call('applyDatePeriod', 'all')->assertCanSeeTableRecords($this->cases->push($new));
    $this->actingAs($this->owner);
    Livewire::test(ListLabCases::class)->call('applyDatePeriod', 'all')->assertCanSeeTableRecords($this->cases);
    expect(LabCaseResource::canDelete($new))->toBeTrue();
});

test('shared case visibility preserves date source doctor and search filters', function () {
    $clinic = $this->cases->first();
    $page = Livewire::test(ListLabCases::class)->filterTable('toolbar', [
        'source' => 'clinic', 'doctor_id' => $this->doctor->id,
        'from' => $clinic->case_date->toDateString(), 'until' => $clinic->case_date->toDateString(),
    ])->searchTable('Historical')->assertCanSeeTableRecords([$clinic])->assertCanNotSeeTableRecords($this->cases->slice(1));
    $page->searchTable('Absent Patient')->assertCanNotSeeTableRecords($this->cases);
});
