<?php

use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\EmployeeSalarySettlement;
use App\Models\EmployeeSalarySettlementItem;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use App\Services\EmployeeSalaryService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/EmployeeSalaryFunding.php';

uses(RefreshDatabase::class);
beforeEach(fn () => seedTechnicianClinicCash());

test('employee profile lists only performed work and salary history opens separately', function () {
    $this->employee->update(['salary_active' => false]);
    $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 12, 'technician_id' => $this->employee->id]);
    $this->case->additionalWorks()->create(['work_type' => 'milling', 'quantity' => 3, 'technician_id' => $this->employee->id]);
    $this->case->additionalWorks()->create(['work_type' => 'individual_abutment', 'quantity' => 9]);
    $page = Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->assertSee(__('employees.performed_work'))->assertSee('PMMA')
        ->assertDontSee(__('employees.salary.type'))->assertDontSee(__('employees.salary.rates'));
    expect($page->instance()->performedWorks())->toHaveCount(2);
    $page->mountAction('salaryHistory')->assertMountedActionModalSee(__('employees.salary.no_history'));
});

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->employee = Employee::create([
        'first_name' => 'Nika', 'last_name' => 'Tech', 'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id,
        'is_active' => true, 'salary_type' => 'performance', 'salary_active' => true,
        'salary_main_technician' => true, 'salary_milling_eligible' => true, 'salary_abutment_eligible' => true, 'salary_balk_eligible' => true,
    ]);
    $this->case = LabCase::create(['patient_id' => Patient::create(['first_name' => 'sharon', 'last_name' => 'david'])->id, 'case_date' => '2026-09-07', 'source' => 'clinic', 'created_by' => auth()->id()]);
    $this->service = app(EmployeeSalaryService::class);
});

test('fixed salary settings save on the employee form and optional settings remain optional', function () {
    Livewire::test(EditEmployee::class, ['record' => $this->employee->id])
        ->fillForm(['salary_type' => 'fixed', 'salary_active' => true, 'monthly_salary_gel' => 1500, 'salary_effective_from' => '2026-09-01'])
        ->call('save')->assertHasNoFormErrors();
    expect($this->employee->fresh()->monthly_salary_gel)->toBe('1500.00');
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])->assertOk()->assertDontSee('1,500.00')
        ->mountAction('calculateSalary')->assertMountedActionModalSee('1,500.00');
});

test('performance rates are configured per employee through the profile form', function () {
    Livewire::test(EditEmployee::class, ['record' => $this->employee->id])
        ->fillForm(['salaryRates' => [
            ['work_type' => 'zircon', 'amount' => 12.5, 'basis' => 'per_unit', 'is_active' => true],
            ['work_type' => 'milling', 'amount' => 40, 'basis' => 'per_work', 'is_active' => true],
        ]])->call('save')->assertHasNoFormErrors();
    expect($this->employee->salaryRates()->count())->toBe(2)
        ->and($this->employee->salaryRates()->where('work_type', 'zircon')->sole()->amount)->toBe('12.50');
});

test('actual employee assignments and configured rate bases determine technician salary', function () {
    foreach (['zircon', 'pmma', 'milling', 'individual_abutment', 'titanium_bar_modeling', 'other'] as $type) {
        $this->employee->salaryRates()->create(['work_type' => $type, 'amount' => 12.5, 'basis' => $type === 'milling' ? 'per_work' : 'per_unit', 'is_active' => true]);
    }
    $other = $this->employee->replicate();
    $other->save();
    foreach (['zircon', 'pmma'] as $type) {
        $this->case->mainWorks()->create(['material' => $type, 'quantity' => 2, 'technician_id' => $this->employee->id]);
    }
    foreach (['milling', 'individual_abutment', 'titanium_bar_modeling', 'other'] as $type) {
        $this->case->additionalWorks()->create(['work_type' => $type, 'quantity' => 2, 'technician_id' => $this->employee->id]);
    }
    $this->case->additionalWorks()->create(['work_type' => 'milling', 'quantity' => 99, 'technician_id' => $other->id]);
    $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 99]);
    $rows = $this->service->pending($this->employee);
    expect($rows)->toHaveCount(7)->and($rows->sum('amount_gel'))->toBe(1375.0);
    expect($this->service->pending($other))->toBeEmpty();
});

test('selection finalizes checked work while skipped work remains pending and undo preserves snapshots', function () {
    $this->employee->salaryRates()->create(['work_type' => 'pmma', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true]);
    $a = $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 20, 'technician_id' => $this->employee->id]);
    $b = $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 4, 'technician_id' => $this->employee->id]);
    $settled = settleTechnicianWithClinicCash($this->employee, ['main-'.$a->id.'-employee-'.$this->employee->id.'-pmma']);
    expect($settled->total_gel)->toBe('500.00')->and($settled->items()->count())->toBe(1)
        ->and($this->service->pending($this->employee)->keys()->all())->toBe(['main-'.$b->id.'-employee-'.$this->employee->id.'-pmma']);
    expect(fn () => settleTechnicianWithClinicCash($this->employee, ['main-'.$a->id.'-employee-'.$this->employee->id.'-pmma']))->toThrow(ValidationException::class);
    $this->employee->salaryRates()->update(['amount' => 30]);
    expect($settled->items()->sole()->rate_amount)->toBe('25.00');
    $this->service->undo($settled);
    $this->service->undo($settled);
    expect($this->service->pending($this->employee))->toHaveCount(2)
        ->and($settled->fresh()->status)->toBe('undone')->and($settled->items()->count())->toBe(1);
    settleTechnicianWithClinicCash($this->employee, ['main-'.$a->id.'-employee-'.$this->employee->id.'-pmma']);
    expect(EmployeeSalarySettlement::count())->toBe(2)
        ->and(EmployeeSalarySettlementItem::whereNotNull('active_source_key')->count())->toBe(1);
});

test('effective date active settings and filters gate eligibility', function () {
    $this->employee->salaryRates()->create(['work_type' => 'pmma', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true]);
    $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 2, 'technician_id' => $this->employee->id]);
    expect($this->service->pending($this->employee, '2026-09-08'))->toBeEmpty();
    $this->employee->update(['salary_effective_from' => '2026-09-08']);
    expect($this->service->pending($this->employee))->toBeEmpty();
    $this->employee->update(['salary_effective_from' => null, 'salary_active' => false]);
    expect($this->service->pending($this->employee))->toBeEmpty();
    $this->employee->update(['salary_active' => true]);
    $this->employee->salaryRates()->update(['is_active' => false]);
    expect($this->service->pending($this->employee))->toBeEmpty();
});

test('fixed monthly settlements prevent duplicates and can be undone without deleting history', function () {
    $this->employee->update(['salary_type' => 'fixed', 'monthly_salary_gel' => 1500, 'salary_effective_from' => '2026-09-01']);
    expect(fn () => $this->service->settleFixed($this->employee, '2026-08'))->toThrow(ValidationException::class);
    $settled = $this->service->settleFixed($this->employee, '2026-09');
    expect($settled->total_gel)->toBe('1500.00')->and($settled->items()->count())->toBe(0);
    expect(fn () => $this->service->settleFixed($this->employee, '2026-09'))->toThrow(ValidationException::class);
    $this->service->undo($settled);
    $this->service->undo($settled);
    $this->service->settleFixed($this->employee, '2026-09');
    expect($this->employee->salarySettlements()->count())->toBe(2)
        ->and($settled->fresh()->settings_snapshot['monthly_salary_gel'])->toBe('1500.00');
});

test('profile modal selects pending rows renders the salary table and finalizes only checked rows', function () {
    $this->employee->salaryRates()->create(['work_type' => 'pmma', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true]);
    $a = $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 20, 'technician_id' => $this->employee->id]);
    $b = $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 4, 'technician_id' => $this->employee->id]);
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->mountAction('calculateSalary')->assertMountedActionModalSee('Sharon David')
        ->assertMountedActionModalSee('600.00')->assertMountedActionModalSee('PMMA')
        ->fillForm(['selected_items' => ['main-'.$a->id.'-employee-'.$this->employee->id.'-pmma']])
        ->assertMountedActionModalSee('500.00')
        ->fillForm(['actual_paid_gel' => 500, 'clinic_cash_gel' => 500, 'israeli_cash_gel' => 0])
        ->callMountedAction()->assertHasNoFormErrors();
    expect($this->employee->salarySettlements()->sole()->total_gel)->toBe('500.00')
        ->and($this->service->pending($this->employee)->keys()->all())->toBe(['main-'.$b->id.'-employee-'.$this->employee->id.'-pmma']);
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->mountAction('calculateSalary')->assertMountedActionModalSee('100.00')
        ->assertSchemaStateSet(['selected_items' => ['main-'.$b->id.'-employee-'.$this->employee->id.'-pmma']]);
    $settled = $this->employee->salarySettlements()->sole();
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->callAction('undoSalary', arguments: ['settlement' => $settled->id]);
    expect($this->service->pending($this->employee))->toHaveCount(2);
});

test('fixed salary can be finalized from the employee profile', function () {
    $this->employee->update(['salary_type' => 'fixed', 'monthly_salary_gel' => 1500]);
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])
        ->callAction('calculateSalary', data: ['month' => '2026-09'])->assertHasNoFormErrors();
    expect($this->employee->salarySettlements()->sole()->salary_month)->toBe('2026-09');
});

test('settled sources cannot accrue again after technician reassignment', function () {
    $this->employee->salaryRates()->create(['work_type' => 'milling', 'amount' => 10, 'basis' => 'per_unit', 'is_active' => true]);
    $work = $this->case->additionalWorks()->create(['work_type' => 'milling', 'quantity' => 2, 'technician_id' => $this->employee->id]);
    $settled = settleTechnicianWithClinicCash($this->employee, ['additional-'.$work->id.'-employee-'.$this->employee->id.'-milling', 'additional-'.$work->id.'-employee-'.$this->employee->id.'-milling']);
    $other = $this->employee->replicate();
    $other->save();
    $other->salaryRates()->create(['work_type' => 'milling', 'amount' => 20, 'basis' => 'per_unit', 'is_active' => true]);
    $work->update(['technician_id' => $other->id]);
    expect($settled->items()->count())->toBe(1)->and($settled->total_gel)->toBe('20.00')
        ->and($this->service->pending($other))->toBeEmpty();
    $this->service->undo($settled);
    expect($this->service->pending($other))->toHaveCount(1)
        ->and($this->service->pending($this->employee))->toBeEmpty();
});

test('salary profile is restricted to owners and undo cannot target another employee', function () {
    $this->employee->update(['salary_type' => 'fixed', 'monthly_salary_gel' => 1500]);
    $settled = $this->service->settleFixed($this->employee, '2026-09');
    $other = $this->employee->replicate();
    $other->save();
    expect(fn () => Livewire::test(ViewEmployee::class, ['record' => $other->id])
        ->callAction('undoSalary', arguments: ['settlement' => $settled->id]))->toThrow(ModelNotFoundException::class);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    Livewire::test(ViewEmployee::class, ['record' => $this->employee->id])->assertForbidden();
});
