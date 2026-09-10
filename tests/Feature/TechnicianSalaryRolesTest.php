<?php

use App\Filament\Resources\LabTechnicians\Pages\EditLabTechnician as EditEmployee;
use App\Filament\Resources\LabTechnicians\Pages\ViewLabTechnician as ViewEmployee;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use App\Services\EmployeeSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/EmployeeSalaryFunding.php';

uses(RefreshDatabase::class);
beforeEach(fn () => seedTechnicianClinicCash());

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $base = ['last_name' => 'Technician', 'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id, 'is_active' => true, 'salary_type' => 'performance', 'salary_active' => true, 'salary_milling_eligible' => true, 'salary_abutment_eligible' => true, 'salary_balk_eligible' => true];
    $this->main = Employee::create([...$base, 'first_name' => 'Main', 'salary_main_technician' => true]);
    $this->modeler = Employee::create([...$base, 'first_name' => 'Modeler', 'salary_modeler' => true]);
    foreach ([$this->main, $this->modeler] as $employee) {
        foreach (['zircon', 'pmma', 'zircon_modeling', 'pmma_modeling', 'milling', 'individual_abutment', 'abutment_modeling', 'titanium_bar_modeling'] as $type) {
            $employee->salaryRates()->create(['work_type' => $type, 'amount' => $employee->is($this->main) ? 10 : 5, 'basis' => 'per_unit', 'is_active' => true]);
        }
    }
    $this->case = LabCase::create(['patient_id' => Patient::create(['first_name' => 'Lab', 'last_name' => 'Patient'])->id, 'case_date' => '2026-09-07', 'source' => 'clinic', 'created_by' => auth()->id()]);
    $this->service = app(EmployeeSalaryService::class);
});

test('employee role flags save and modeling rows render in the existing salary modal', function () {
    Livewire::test(EditEmployee::class, ['record' => $this->modeler->id])
        ->fillForm(['salary_modeler' => true, 'salary_milling_eligible' => false])
        ->call('save')->assertHasNoFormErrors();
    expect($this->modeler->fresh()->salary_modeler)->toBeTrue()->and($this->modeler->fresh()->salary_milling_eligible)->toBeFalse();
    $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 20, 'technician_id' => $this->modeler->id]);
    Livewire::test(ViewEmployee::class, ['record' => $this->modeler->id])
        ->mountAction('calculateSalary')->assertMountedActionModalSee(__('employees.salary.pmma_modeling'))
        ->assertMountedActionModalSee('100.00');
});

test('modelers receive only zircon modeling for mixed work and main technician receives both materials', function () {
    foreach (['pmma', 'zircon'] as $material) {
        $this->case->mainWorks()->create(['material' => $material, 'quantity' => 20, 'technician_id' => $this->modeler->id]);
    }
    $rows = $this->service->pending($this->modeler);
    expect($rows->pluck('work_type')->all())->toBe(['zircon_modeling'])->and($rows->sum('amount_gel'))->toBe(100.0);
    expect($this->service->pending($this->main)->pluck('work_type')->all())->toBe(['pmma', 'zircon'])
        ->and($this->service->pending($this->main)->sum('amount_gel'))->toBe(400.0);
    settleTechnicianWithClinicCash($this->main, $this->service->pending($this->main)->keys()->all());
    settleTechnicianWithClinicCash($this->modeler, $rows->keys()->all());
    expect($this->service->pending($this->main))->toBeEmpty()->and($this->service->pending($this->modeler))->toBeEmpty();
});

test('pmma only and zircon only modeling use independent configured rates', function (string $material) {
    $this->case->mainWorks()->create(['material' => $material, 'quantity' => 20, 'technician_id' => $this->modeler->id]);
    expect($this->service->pending($this->modeler)->pluck('work_type')->all())->toBe([$material.'_modeling'])
        ->and($this->service->pending($this->modeler)->sum('amount_gel'))->toBe(100.0);
})->with(['pmma', 'zircon']);

test('modeling suppression uses explicit lab group rather than patient or date filters', function () {
    $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 20, 'technician_id' => $this->modeler->id]);
    $other = $this->case->replicate();
    $other->case_date = '2026-08-01';
    $other->save();
    $other->mainWorks()->create(['material' => 'zircon', 'quantity' => 20]);
    expect($this->service->pending($this->modeler))->toHaveCount(1);
    $other->update(['related_case_id' => $this->case->id, 'case_relationship' => 'same_case']);
    expect($this->service->pending($this->modeler, '2026-09-01'))->toBeEmpty();
});

test('milling and balk are paid only to the selected eligible performer', function (string $type) {
    $work = $this->case->additionalWorks()->create(['work_type' => $type, 'quantity' => 3, 'technician_id' => $this->modeler->id]);
    expect($this->service->pending($this->main))->toBeEmpty()->and($this->service->pending($this->modeler)->sum('amount_gel'))->toBe(15.0);
    $this->modeler->update([$type === 'milling' ? 'salary_milling_eligible' : 'salary_balk_eligible' => false]);
    expect($this->service->pending($this->modeler))->toBeEmpty();
    $work->update(['technician_id' => $this->main->id]);
    expect($this->service->pending($this->main)->sum('amount_gel'))->toBe(30.0);
})->with(['milling', 'titanium_bar_modeling']);

test('main technician milling is additional to main work', function () {
    $this->case->mainWorks()->create(['material' => 'zircon', 'quantity' => 20]);
    $this->case->additionalWorks()->create(['work_type' => 'milling', 'quantity' => 20, 'technician_id' => $this->main->id]);
    expect($this->service->pending($this->main))->toHaveCount(2)->and($this->service->pending($this->main)->sum('amount_gel'))->toBe(400.0);
});

test('abutment pays main and selected modeler separately with retry skip and undo protection', function () {
    $work = $this->case->additionalWorks()->create(['work_type' => 'individual_abutment', 'quantity' => 2, 'technician_id' => $this->modeler->id]);
    $skip = $this->case->additionalWorks()->create(['work_type' => 'individual_abutment', 'quantity' => 3, 'technician_id' => $this->modeler->id]);
    $mainKey = $this->service->pending($this->main)->keys()->first();
    $modelKey = $this->service->pending($this->modeler)->keys()->first();
    $mainSettlement = settleTechnicianWithClinicCash($this->main, [$mainKey]);
    $modelSettlement = settleTechnicianWithClinicCash($this->modeler, [$modelKey]);
    expect($mainSettlement->total_gel)->toBe('20.00')->and($modelSettlement->total_gel)->toBe('10.00');
    expect(fn () => settleTechnicianWithClinicCash($this->modeler, [$modelKey]))->toThrow(ValidationException::class);
    $work->update(['quantity' => 9, 'note' => 'edited']);
    expect($this->service->pending($this->modeler))->toHaveCount(1)->and($this->service->pending($this->main))->toHaveCount(1);
    $this->service->undo($mainSettlement);
    $this->service->undo($mainSettlement);
    expect($this->service->pending($this->main))->toHaveCount(2)->and($this->service->pending($this->modeler))->toHaveCount(1);
});
