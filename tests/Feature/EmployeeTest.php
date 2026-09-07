<?php

use App\Filament\Resources\EmployeePositions\Pages\CreateEmployeePosition;
use App\Filament\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('owner can create an employee without a linked user', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $position = EmployeePosition::query()->where('name', 'Technician')->sole();

    Livewire::actingAs($owner)->test(CreateEmployee::class)
        ->fillForm([
            'first_name' => 'Nika', 'last_name' => 'Tech',
            'position_id' => $position->id, 'phone' => null, 'birth_date' => null, 'personal_id' => null, 'is_active' => true,
        ])->call('create')->assertHasNoFormErrors();

    $employee = Employee::query()->sole();
    expect($employee->full_name)->toBe('Nika Tech')->and($employee->user_id)->toBeNull()
        ->and($employee->birth_date)->toBeNull()->and($employee->personal_id)->toBeNull()->and($employee->phone)->toBeNull();
});

test('owner can edit and optionally link an employee to a user', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $linkedUser = User::factory()->create();
    $other = EmployeePosition::query()->where('name', 'Other')->sole();
    $technician = EmployeePosition::query()->where('name', 'Technician')->sole();
    $employee = Employee::create([
        'first_name' => 'Nika', 'last_name' => 'Tech', 'position_id' => $other->id, 'is_active' => true,
    ]);

    Livewire::actingAs($owner)->test(EditEmployee::class, ['record' => $employee->getRouteKey()])
        ->fillForm(['position_id' => $technician->id, 'user_id' => $linkedUser->id, 'is_active' => false])
        ->call('save')->assertHasNoFormErrors();

    expect($employee->fresh()->position->is($technician))->toBeTrue()
        ->and($employee->fresh()->user->is($linkedUser))->toBeTrue()
        ->and($employee->fresh()->is_active)->toBeFalse();
});

test('positions can be managed and created inline from the employee form', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);

    Livewire::actingAs($owner)->test(CreateEmployeePosition::class)
        ->fillForm(['name' => 'Coordinator', 'is_active' => true, 'is_technician' => false])
        ->call('create')->assertHasNoFormErrors();

    Livewire::actingAs($owner)->test(CreateEmployee::class)
        ->assertFormComponentActionExists('position_id', 'createOption')
        ->callFormComponentAction('position_id', 'createOption', [
            'name' => 'Ceramist', 'is_active' => true, 'is_technician' => true,
        ])->assertHasNoFormErrors();

    expect(EmployeePosition::query()->where('name', 'Coordinator')->exists())->toBeTrue()
        ->and(EmployeePosition::query()->where('name', 'Ceramist')->where('is_technician', true)->exists())->toBeTrue();
});

test('employees without history can be deleted while referenced employees must be deactivated', function () {
    $position = EmployeePosition::query()->where('name', 'Technician')->sole();
    $unused = Employee::create(['first_name' => 'Unused', 'last_name' => 'Employee', 'position_id' => $position->id, 'is_active' => true]);
    $unused->delete();
    expect(Employee::find($unused->id))->toBeNull();

    $employee = Employee::create(['first_name' => 'Used', 'last_name' => 'Employee', 'position_id' => $position->id, 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Lab', 'last_name' => 'Patient']);
    $case = LabCase::create(['patient_id' => $patient->id, 'case_date' => today(), 'material' => 'pmma', 'quantity' => 1]);
    $case->additionalWorks()->create(['work_type' => 'milling', 'quantity' => 1, 'technician_id' => $employee->id]);

    expect(fn () => $employee->delete())->toThrow(ValidationException::class);
    $employee->update(['is_active' => false]);
    expect($employee->fresh()->is_active)->toBeFalse()
        ->and($case->additionalWorks()->first()->technician_id)->toBe($employee->id);
});
