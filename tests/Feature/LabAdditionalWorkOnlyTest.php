<?php

use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use App\Services\EmployeeSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->patient = Patient::create(['first_name' => 'Lab', 'last_name' => 'Patient']);
    $this->doctor = Doctor::create(['first_name' => 'Lab', 'last_name' => 'Doctor', 'specialties' => ['orthopedics'], 'is_active' => true]);
    $this->technician = Employee::create(['first_name' => 'Lab', 'last_name' => 'Technician',
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id, 'is_active' => true,
        'salary_type' => 'performance', 'salary_active' => true, 'salary_main_technician' => true,
        'salary_abutment_eligible' => true, 'salary_milling_eligible' => true]);
    $this->caseData = ['source' => 'clinic', 'case_date' => today()->toDateString(),
        'patient_id' => $this->patient->id, 'doctor_id' => $this->doctor->id];
});

test('create uses the real mounted placeholder and live patient doctor updates', function () {
    $page = Livewire::test(ListLabCases::class)->mountAction('create');
    $key = array_key_first($page->get('mountedActions.0.data.mainWorks'));
    expect($page->get('mountedActions.0.data.mainWorks.'.$key.'.quantity'))->toBeNull();
    $page->set('mountedActions.0.data.mainWorks.'.$key.'.doctor_search', 'Lab Doctor')
        ->set('mountedActions.0.data.mainWorks.'.$key.'.patient_search', $this->patient->lab_selection_label)
        ->set('mountedActions.0.data.additionalWorks', [['work_type' => 'splint', 'quantity' => 1, 'technician_id' => $this->technician->id]])
        ->callMountedAction()->assertHasNoActionErrors();
    expect(LabCase::sole()->mainWorks()->count())->toBe(0);
});

test('standalone additional work saves without a main row and remains editable and payable', function (string $type) {
    $this->technician->salaryRates()->create(['work_type' => $type, 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true]);
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        ...$this->caseData,
        'mainWorks' => [['doctor_search' => 'Lab Doctor', 'patient_search' => $this->patient->lab_selection_label, 'quantity' => null]],
        'additionalWorks' => [['work_type' => $type, 'quantity' => 2, 'technician_id' => $this->technician->id]],
    ])->callMountedAction()->assertHasNoActionErrors();
    $case = LabCase::sole();
    expect($case->mainWorks()->count())->toBe(0)->and($case->additionalWorks()->sole()->work_type)->toBe($type);
    Livewire::test(EditLabCase::class, ['record' => $case->id])->fillForm(['notes' => 'Standalone work'])
        ->call('save')->assertHasNoFormErrors();
    expect($case->fresh()->notes)->toBe('Standalone work')->and($case->mainWorks()->count())->toBe(0)
        ->and(app(EmployeeSalaryService::class)->pending($this->technician)->sum('amount_gel'))->toBe(50.0);
    Livewire::test(EditLabCase::class, ['record' => $case->id])->fillForm(['mainWorks' => [], 'additionalWorks' => []])
        ->call('save')->assertHasFormErrors(['mainWorks']);
    expect($case->additionalWorks()->count())->toBe(1);
})->with(['splint', 'individual_abutment', 'milling', 'other']);

test('completely empty work is rejected including an untouched placeholder', function (array $rows) {
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        ...$this->caseData, 'mainWorks' => $rows, 'additionalWorks' => [],
    ])->callMountedAction()->assertHasActionErrors(['mainWorks']);
    expect(LabCase::count())->toBe(0);
})->with(['none' => [[]], 'placeholder' => [[['quantity' => null]]]]);

test('save hook validation errors for hidden patient state are visible inside the modal', function () {
    $page = Livewire::test(ListLabCases::class)->mountAction('create')
        ->fillForm(['additionalWorks' => [['work_type' => 'splint', 'quantity' => 1]]])
        ->callMountedAction()->assertHasErrors(['patient_entry']);
    $page->assertSchemaComponentVisible('validation_summary')
        ->assertSchemaComponentExists('validation_summary', checkComponentUsing: fn ($component): bool =>
            str_contains((string) $component->getContent(), __('lab.patient_required')));
    expect(LabCase::count())->toBe(0);
});

test('external standalone work retains case names without persisting its placeholder', function () {
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'external', 'case_date' => today()->toDateString(),
        'mainWorks' => [['doctor_search' => 'Outside Doctor', 'patient_search' => 'Outside Patient', 'quantity' => null]],
        'additionalWorks' => [['work_type' => 'splint', 'quantity' => 1, 'technician_id' => $this->technician->id]],
    ])->callMountedAction()->assertHasNoActionErrors();
    $case = LabCase::sole();
    expect($case->mainWorks()->count())->toBe(0)->and($case->external_doctor_name)->toBe('Outside Doctor')
        ->and($case->external_patient_name)->toBe('Outside Patient');
    Livewire::test(EditLabCase::class, ['record' => $case->id])->fillForm(['notes' => 'External standalone'])
        ->call('save')->assertHasNoFormErrors();
    expect($case->fresh()->external_patient_name)->toBe('Outside Patient');
});

test('started main rows still validate even alongside valid additional work', function (array $row, string $field) {
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        ...$this->caseData, 'mainWorks' => [$row],
        'additionalWorks' => [['work_type' => 'splint', 'quantity' => 1, 'technician_id' => $this->technician->id]],
    ])->callMountedAction()->assertHasActionErrors(['mainWorks.0.'.$field]);
    expect(LabCase::count())->toBe(0);
})->with([
    'shade' => [['shade' => 'A1', 'quantity' => 1], 'material'],
    'quantity' => [['quantity' => 2], 'material'],
    'explicit quantity one' => [['quantity' => 1], 'material'],
    'material' => [['material' => 'zircon', 'quantity' => null], 'quantity'],
]);

test('main-only and mixed cases retain their existing rows during editing', function (bool $mixed) {
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        ...$this->caseData, 'mainWorks' => [['material' => 'zircon', 'quantity' => 2, 'shade' => 'A1']],
        'additionalWorks' => $mixed ? [['work_type' => 'splint', 'quantity' => 1]] : [],
    ])->callMountedAction()->assertHasNoActionErrors();
    $case = LabCase::sole();
    $mainId = $case->mainWorks()->sole()->id;
    Livewire::test(EditLabCase::class, ['record' => $case->id])->fillForm(['notes' => 'Updated'])
        ->call('save')->assertHasNoFormErrors();
    expect($case->mainWorks()->sole()->id)->toBe($mainId)
        ->and((int) $case->mainWorks()->sole()->quantity)->toBe(2)
        ->and($case->additionalWorks()->count())->toBe($mixed ? 1 : 0);
})->with([false, true]);
