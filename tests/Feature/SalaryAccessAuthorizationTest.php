<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Pages\LabSalaries;
use App\Filament\Pages\TechnicianSalaries;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\Employees\Pages\ViewEmployee;
use App\Filament\Resources\LabTechnicians\LabTechnicianResource;
use App\Filament\Resources\LabTechnicians\Pages\ViewLabTechnician;
use App\Filament\Support\SalaryNavigation;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\Patient;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Models\Visit;
use App\Services\ClinicPayrollCycleService;
use App\Services\EmployeePayrollService;
use App\Services\EmployeeSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

function salaryAccessEmployee(bool $technician = false): Employee
{
    return Employee::create([
        'first_name' => 'Restricted', 'last_name' => $technician ? 'Technician' : 'Employee',
        'position_id' => EmployeePosition::where('name', $technician ? 'Technician' : 'Administrator')->sole()->id,
        'is_active' => true,
    ]);
}

test('administrator doctor salary response never loads employee payroll or combined totals', function () {
    $employee = salaryAccessEmployee();
    $employee->payrollSettings()->create([
        'source' => 'clinic', 'salary_model' => 'fixed_net', 'currency' => 'GEL',
        'net_amount' => 87654.32, 'default_payment_method' => 'bank_transfer',
        'effective_from' => today()->startOfMonth(), 'is_active' => true,
    ]);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $this->mock(ClinicPayrollCycleService::class)->shouldNotReceive('overview', 'preview', 'selectedReview', 'finalize');
    $this->mock(EmployeePayrollService::class)->shouldNotReceive('payableSummaries', 'finalize');
    $this->mock(EmployeeSalaryService::class)->shouldNotReceive('overviewTotals', 'pending', 'settle', 'payFixed');
    $queries = [];
    DB::listen(function ($query) use (&$queries) { $queries[] = $query->sql; });

    $this->get(DoctorCompensation::getUrl())->assertOk()
        ->assertSee('data-salary-tab="doctors"', false)
        ->assertDontSee('data-salary-tab="employees"', false)
        ->assertDontSee('data-salary-tab="technicians"', false)
        ->assertDontSee('data-clinic-payroll-date', false)
        ->assertDontSee('employee-payroll-detail', false)
        ->assertDontSee($employee->full_name)->assertDontSee('87,654.32');
    Livewire::test(DoctorCompensation::class)->call('$refresh')->assertOk()
        ->assertSet('employeeDetail', null)->assertSet('clinicPayrollReview', null);
    expect(array_column(SalaryNavigation::tabs(), 'mode'))->toBe(['doctors']);
    expect(collect($queries)->filter(fn ($sql) => preg_match('/(?:from|join) ["`]?(?:employees|employee_payroll_settings|payroll_entries|clinic_payroll_cycles|employee_salary_settlements)["`]?\b/i', $sql)))->toBeEmpty();
});

test('administrator cannot access employee technician or legacy salary URLs and profiles', function () {
    $employee = salaryAccessEmployee();
    $technician = salaryAccessEmployee(true);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    foreach ([
        DoctorCompensation::getUrl(['staff' => 'employees']),
        TechnicianSalaries::getUrl(), LabSalaries::getUrl(),
        EmployeeResource::getUrl('view', ['record' => $employee]),
        LabTechnicianResource::getUrl('view', ['record' => $technician]),
    ] as $url) {
        $this->get($url)->assertForbidden();
    }
    Livewire::withQueryParams(['staff' => 'employees'])->test(DoctorCompensation::class)->assertForbidden();
    Livewire::test(TechnicianSalaries::class)->assertForbidden();
    Livewire::test(LabSalaries::class)->assertForbidden();
    Livewire::test(ViewEmployee::class, ['record' => $employee->id])->assertForbidden();
    Livewire::test(ViewLabTechnician::class, ['record' => $technician->id])->assertForbidden();
});

test('administrator cannot switch salary mode in a Livewire request', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $this->mock(EmployeePayrollService::class)->shouldNotReceive('payableSummaries');
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertForbidden();
});

test('administrator employee detail selection and finalization requests are forbidden before querying payroll', function (string $method, array $arguments) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $this->mock(EmployeePayrollService::class)->shouldNotReceive('payableSummaries', 'finalize');
    $this->mock(ClinicPayrollCycleService::class)->shouldNotReceive('overview', 'preview', 'selectedReview', 'finalize');
    Livewire::test(DoctorCompensation::class)->call($method, ...$arguments)->assertForbidden();
    $this->assertDatabaseCount('payroll_entries', 0);
    $this->assertDatabaseCount('employee_salary_settlements', 0);
    $this->assertDatabaseCount('clinic_payroll_cycles', 0);
})->with([
    ['openEmployeeSalary', [999, 'clinic']],
    ['finalizeEmployeePayroll', []],
    ['selectClinicPayrollRows', [true]],
]);

test('administrator cannot mount combined payroll even with forged cycle arguments', function (array $arguments) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $this->mock(ClinicPayrollCycleService::class)->shouldNotReceive('overview', 'preview', 'selectedReview', 'finalize');
    Livewire::test(DoctorCompensation::class)->assertActionHidden('clinicPayroll')
        ->mountAction('clinicPayroll', $arguments)->assertActionNotMounted('clinicPayroll')
        ->assertSet('clinicPayrollReview', null);
})->with([[[]], [['cycle' => 999]]]);

test('Filament rejects execution of forged mounted payroll action state on the server', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $this->mock(ClinicPayrollCycleService::class)->shouldNotReceive('overview', 'preview', 'selectedReview', 'finalize');
    $component = Livewire::test(DoctorCompensation::class)->assertActionHidden('clinicPayroll')
        ->set('mountedActions', [['name' => 'clinicPayroll', 'arguments' => [], 'data' => []]])
        ->call('callMountedAction')->assertOk()
        ->assertSet('clinicPayrollReview', null);
    expect($component->instance()->clinicPayrollAction()->isAuthorized())->toBeFalse();
    $this->assertDatabaseCount('clinic_payroll_cycles', 0);
});

test('owner salary snapshots cannot be reused after changing to administrator', function (string $snapshot) {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    $component = Livewire::actingAs($owner)->test(DoctorCompensation::class);
    if ($snapshot === 'employees') {
        $component->set('staffTypeFilter', 'employees');
    } else {
        $component->mountAction('clinicPayroll')->assertActionMounted('clinicPayroll');
    }
    Livewire::actingAs($admin);
    $component->call('$refresh')->assertForbidden();
})->with(['employees', 'clinicPayroll']);

test('employee salary detail cannot be refreshed or finalized after owner access is revoked', function (string $method) {
    $employee = salaryAccessEmployee();
    $employee->payrollSettings()->create([
        'source' => 'israeli', 'salary_model' => 'fixed_net', 'currency' => 'GEL',
        'net_amount' => 87654.32, 'default_payment_method' => 'bank_transfer',
        'effective_from' => today()->startOfMonth(), 'is_active' => true,
    ]);
    $component = Livewire::actingAs(User::factory()->create(['role' => User::ROLE_OWNER]))
        ->test(DoctorCompensation::class)->call('openEmployeeSalary', $employee->id, 'israeli')->assertOk();
    expect($component->get('employeeDetail')['net_amount'])->toBe(87654.32);
    Livewire::actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $component->call($method)->assertForbidden();
    $this->assertDatabaseCount('payroll_entries', 0);
})->with(['$refresh', 'finalizeEmployeePayroll']);

test('employee and technician profile actions reject unauthorized Livewire hydration', function (bool $technician, string $method) {
    $employee = salaryAccessEmployee($technician);
    $page = $technician ? ViewLabTechnician::class : ViewEmployee::class;
    $component = Livewire::actingAs(User::factory()->create(['role' => User::ROLE_OWNER]))
        ->test($page, ['record' => $employee->id]);
    Livewire::actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $component->call('mountAction', $method)->assertForbidden();
})->with([
    [false, 'calculatePayroll'], [false, 'payrollHistory'],
    [true, 'calculateSalary'], [true, 'salaryHistory'],
]);

test('technician Livewire data and payment requests reject a previously authorized snapshot', function (string $method, array $arguments) {
    $component = Livewire::actingAs(User::factory()->create(['role' => User::ROLE_OWNER]))
        ->test(TechnicianSalaries::class);
    Livewire::actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $this->mock(EmployeeSalaryService::class)->shouldNotReceive('overviewTotals', 'pending', 'settle', 'payFixed', 'undo');
    $component->call($method, ...$arguments)->assertForbidden();
})->with([
    ['overview', []], ['openSalary', [999]], ['openMonthlySalary', [999]], ['openHistory', [999]],
    ['mountAction', ['calculateSalary']], ['mountAction', ['monthlySalary']],
    ['mountAction', ['salaryHistory']], ['mountAction', ['undoSalary', ['settlement' => 999]]],
    ['callMountedAction', []],
]);

test('owner retains all salary modes and combined payroll review', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    expect(array_column(SalaryNavigation::tabs(), 'mode'))->toBe(['doctors', 'employees', 'technicians']);
    $this->get(DoctorCompensation::getUrl())->assertOk();
    $this->get(DoctorCompensation::getUrl(['staff' => 'employees']))->assertOk();
    $this->get(TechnicianSalaries::getUrl())->assertOk();
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertOk()
        ->mountAction('clinicPayroll')->assertActionMounted('clinicPayroll');
});

test('doctor salary calculation and existing settlement action remain available', function (string $role) {
    $this->actingAs($user = User::factory()->create(['role' => $role]));
    $doctor = Doctor::create(['first_name' => 'Authorized', 'last_name' => 'Doctor', 'is_active' => true, 'compensation_percentage' => 40]);
    $patient = Patient::create(['first_name' => 'Synthetic', 'last_name' => 'Patient']);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id,
        'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 1000]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Synthetic work', 'quantity' => 1, 'unit_price' => 1000]);
    $visit->payments()->create(['amount' => 1000, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);

    Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $doctor->id, 'clinic')
        ->assertActionMounted('calculateSalary')->assertMountedActionModalSee('400.00')
        ->callMountedAction()->assertHasNoFormErrors();
    $settlement = SalarySettlement::sole();
    expect((float) $settlement->salary_total)->toBe(400.0)->and($settlement->created_by)->toBe($user->id);
})->with([User::ROLE_ADMINISTRATOR, User::ROLE_OWNER]);
