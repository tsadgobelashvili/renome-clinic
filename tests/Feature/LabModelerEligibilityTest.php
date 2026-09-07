<?php

use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use App\Services\EmployeeSalaryService;
use App\Support\LabTechnicianDisplay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/EmployeeSalaryFunding.php';

uses(RefreshDatabase::class);
beforeEach(fn () => seedTechnicianClinicCash());

test('compact lab technician names disambiguate active first names without changing full names', function () {
    $first = $this->employee->replicate();
    $first->fill(['user_id' => null, 'first_name' => 'mariam ', 'last_name' => 'Shavaeva'])->save();
    $second = $first->replicate();
    $second->fill(['first_name' => 'Mariam', 'last_name' => 'Ichukaidze'])->save();
    $this->case->update(['modeled_by' => null]);
    $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 20, 'technician_id' => $first->id]);
    $this->case->additionalWorks()->create(['work_type' => 'milling', 'quantity' => 20, 'technician_id' => $second->id]);
    Livewire::test(ListLabCases::class)
        ->assertTableColumnStateSet('modeler_display', 'Mariam S.', $this->case)
        ->assertTableColumnStateSet('additional_work_summary', [__('lab.additional_types_short.milling').' ×20 — Mariam I.'], $this->case);
    expect($first->fresh()->first_name)->toBe('mariam ')->and($second->fresh()->full_name)->toBe('Mariam Ichukaidze');
    $second->update(['is_active' => false]);
    expect((new LabTechnicianDisplay)->name($first))->toBe('Mariam')
        ->and((new LabTechnicianDisplay)->name(null))->toBe('—');
});

test('legacy modeler rate keys are migrated without changing rates or main technician configuration', function () {
    $this->employee->salaryRates()->delete();
    $rate = $this->employee->salaryRates()->create(['work_type' => 'pmma', 'amount' => 5, 'basis' => 'per_unit', 'is_active' => true]);
    $migration = require database_path('migrations/2026_09_07_170000_normalize_modeler_salary_rate_keys.php');
    $migration->up();
    $migration->up();
    expect($rate->fresh()->work_type)->toBe('pmma_modeling')->and($rate->fresh()->amount)->toBe('5.00')
        ->and($this->employee->salaryRates()->count())->toBe(1);
    $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 20, 'technician_id' => $this->employee->id]);
    expect($this->service->pending($this->employee)->sum('amount_gel'))->toBe(100.0);
});

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->modelerUser = User::factory()->create(['name' => 'Modeler Login']);
    $this->employee = Employee::create([
        'first_name' => 'Ilia', 'last_name' => 'Zlobin', 'user_id' => $this->modelerUser->id,
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id,
        'is_active' => true, 'salary_type' => 'performance', 'salary_active' => true, 'salary_modeler' => true,
    ]);
    foreach (['pmma_modeling' => 5, 'zircon_modeling' => 8] as $type => $rate) {
        $this->employee->salaryRates()->create(['work_type' => $type, 'amount' => $rate, 'basis' => 'per_unit', 'is_active' => true]);
    }
    $this->case = LabCase::create(['patient_id' => Patient::create(['first_name' => 'Lab', 'last_name' => 'Patient'])->id,
        'case_date' => '2026-09-07', 'source' => 'clinic', 'modeled_by' => $this->modelerUser->id, 'created_by' => auth()->id()]);
    $this->service = app(EmployeeSalaryService::class);
});

test('lab table shows compact modeler name and opens rows without a duplicate edit action', function () {
    $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 20]);
    $page = Livewire::test(ListLabCases::class)->assertCanSeeTableRecords([$this->case])
        ->assertTableColumnStateSet('modeler_display', 'Ilia', $this->case)
        ->assertTableActionDoesNotExist('edit')->assertSee(LabCaseResource::getUrl('edit', ['record' => $this->case]));
    $this->case->update(['modeled_by' => null, 'modeling' => 'unassigned legacy text']);
    expect($this->case->fresh()->modeler_display)->toBe('—');
    $this->case->mainWorks()->first()->update(['technician_id' => $this->employee->id]);
    expect($this->case->fresh()->modeler_display)->toBe('Ilia Zlobin');
});

test('modeled by resolves through linked employee with correct material quantity and rate', function (array $materials, string $expectedType, float $amount) {
    foreach ($materials as $material) {
        $this->case->mainWorks()->create(['material' => $material, 'quantity' => 20]);
    }
    $rows = $this->service->pending($this->employee);
    expect($rows)->toHaveCount(1)->and($rows->first()['work_type'])->toBe($expectedType)
        ->and($rows->first()['quantity'])->toBe(20)->and($rows->first()['amount_gel'])->toBe($amount);
})->with([
    'mixed' => [['pmma', 'zircon'], 'zircon_modeling', 160.0],
    'pmma only' => [['pmma'], 'pmma_modeling', 100.0],
    'zircon only' => [['zircon'], 'zircon_modeling', 160.0],
]);

test('created by never grants modeling salary and explicit modeler overrides a different row technician', function () {
    $other = $this->employee->replicate();
    $other->user_id = auth()->id();
    $other->save();
    $other->salaryRates()->create(['work_type' => 'pmma_modeling', 'amount' => 9, 'basis' => 'per_unit', 'is_active' => true]);
    $work = $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 20, 'technician_id' => $other->id]);
    expect($this->service->pending($other))->toBeEmpty()->and($this->service->pending($this->employee))->toHaveCount(1);
    $work->update(['technician_id' => null]);
    $this->case->update(['modeled_by' => null]);
    expect($this->service->pending($other))->toBeEmpty()->and($this->service->pending($this->employee))->toBeEmpty();
});

test('case and row assignment cannot duplicate modeling and skipped work survives finalize and undo', function () {
    foreach ([20, 4] as $quantity) {
        $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => $quantity, 'technician_id' => $this->employee->id]);
    }
    $rows = $this->service->pending($this->employee);
    expect($rows)->toHaveCount(2);
    $key = $rows->keys()->first();
    $settlement = settleTechnicianWithClinicCash($this->employee, [$key, $key]);
    expect($settlement->items()->count())->toBe(1)->and($this->service->pending($this->employee))->toHaveCount(1);
    expect(fn () => settleTechnicianWithClinicCash($this->employee, [$key]))->toThrow(ValidationException::class);
    $this->service->undo($settlement);
    $this->service->undo($settlement);
    expect($this->service->pending($this->employee))->toHaveCount(2);
});
