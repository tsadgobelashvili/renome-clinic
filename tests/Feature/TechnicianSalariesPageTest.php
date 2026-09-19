<?php

use App\Filament\Pages\TechnicianSalaries;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use App\Services\EmployeeSalaryService;
use App\Support\TechnicianSalaryReview;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

require_once __DIR__.'/../Support/EmployeeSalaryFunding.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    seedTechnicianClinicCash();
    $this->tech = Employee::create([
        'first_name' => 'Review', 'last_name' => 'Technician',
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id,
        'is_active' => true, 'salary_type' => 'performance', 'salary_active' => true,
        'salary_main_technician' => true, 'salary_milling_eligible' => true,
    ]);
    foreach (['zircon' => 25, 'pmma' => 5, 'milling' => 5] as $type => $amount) {
        $this->tech->salaryRates()->create(['work_type' => $type, 'amount' => $amount, 'basis' => 'per_unit', 'is_active' => true]);
    }
    $this->case = LabCase::create([
        'patient_id' => Patient::create(['first_name' => 'Grouped', 'last_name' => 'Patient'])->id,
        'case_date' => '2026-09-19', 'source' => 'clinic', 'created_by' => auth()->id(),
    ]);
    $this->case->mainWorks()->create(['material' => 'zircon', 'quantity' => 25, 'technician_id' => $this->tech->id]);
    $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 25, 'technician_id' => $this->tech->id]);
    $this->milling = $this->case->additionalWorks()->create(['work_type' => 'milling', 'quantity' => 6, 'technician_id' => $this->tech->id]);
    $this->service = app(EmployeeSalaryService::class);
    $this->review = app(TechnicianSalaryReview::class);
});

test('central overview lists active technicians and matches the existing calculator without patients or per technician queries', function () {
    $other = $this->tech->replicate();
    $other->first_name = 'Second';
    $other->salary_main_technician = false;
    $other->save();
    $other->salaryRates()->create(['work_type' => 'milling', 'amount' => 5, 'basis' => 'per_unit', 'is_active' => true]);
    $this->milling->update(['technician_id' => $other->id]);
    $inactive = $this->tech->replicate();
    $inactive->is_active = false;
    $inactive->save();
    $nontech = $this->tech->replicate();
    $nontech->position_id = EmployeePosition::create(['name' => 'Office review', 'is_active' => true, 'is_technician' => false])->id;
    $nontech->save();
    $employees = Employee::activeTechnicians()->get();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $totals = $this->service->pendingTotals($employees);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($totals[$this->tech->id])->toEqual(750)->and($totals[$other->id])->toEqual(30)
        ->and(collect($queries)->filter(fn ($q) => str_contains($q['query'], 'from "patients"')))->toBeEmpty();
    $third = $other->replicate();
    $third->save();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->service->pendingTotals($employees->concat([$third]));
    $single = DB::getQueryLog();
    DB::disableQueryLog();
    // Both main/additional source types exist in both runs: adding technicians adds no queries.
    expect(count($queries))->toBe(count($single));
    foreach ($employees as $employee) {
        expect($totals[$employee->id])->toEqual($this->service->pending($employee)->sum('amount_gel'));
    }
    $page = Livewire::test(TechnicianSalaries::class)->assertOk();
    expect($page->instance()->overview())->toBe([]);
    $page->set('ready', true)->assertSee('750.00')->assertSee('30.00');
    expect($page->instance()->overview()['records']->modelKeys())->toEqualCanonicalizing([$this->tech->id, $other->id, $third->id]);
    $page->set('from', '2026-09-20')->assertSee('0.00');
});

test('central review reuses grouping finalization and stored history without settling another technician', function () {
    $other = $this->tech->replicate();
    $other->salary_main_technician = false;
    $other->save();
    $other->salaryRates()->create(['work_type' => 'milling', 'amount' => 5, 'basis' => 'per_unit', 'is_active' => true]);
    $this->milling->update(['technician_id' => $other->id]);
    $group = $this->review->groups($this->tech->id, $this->service->pending($this->tech))->first();
    $page = Livewire::test(TechnicianSalaries::class)->set('ready', true)
        ->set('from', '2026-09-01')->set('until', '2026-09-19')
        ->call('openSalary', $this->tech->id)->assertActionMounted('calculateSalary')
        ->assertSchemaStateSet(['from' => '2026-09-01', 'until' => '2026-09-19'])
        ->assertMountedActionModalSee('750.00');
    expect(substr_count($page->getMountedActionModalHtml(), 'data-salary-group='))->toBe(1);
    preg_match('/data-salary-group="([^"]+)"/', $page->getMountedActionModalHtml(), $matches);
    expect($matches[1])->toBe($group['key']);
    $page->call('toggleSalaryReviewGroup', $group['key'])->assertMountedActionModalSee('625.00')
        ->call('toggleSalaryReviewSelection', $group['key'])->assertOk()->assertHasNoErrors()->assertSchemaStateSet(['selected_items' => []])
        ->call('toggleSalaryReviewSelection', $group['key'])
        ->fillForm(['clinic_cash_gel' => 750, 'israeli_cash_gel' => 0])->callMountedAction()->assertHasNoFormErrors();
    $settled = $this->tech->salarySettlements()->sole();
    expect($settled->total_gel)->toBe('750.00')->and($this->service->pending($other)->sum('amount_gel'))->toBe(30.0);
    $page->call('openHistory', $this->tech->id)->assertActionMounted('salaryHistory')
        ->assertMountedActionModalSee('750.00')->assertMountedActionModalSee('625.00');
    expect($settled->fresh()->total_gel)->toBe('750.00');
});

test('central payroll page denies every non owner role and inactive owner', function ($role, $active) {
    $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $active]));
    expect(TechnicianSalaries::canAccess())->toBeFalse();
    Livewire::test(TechnicianSalaries::class)->assertForbidden();
    $this->get(TechnicianSalaries::getUrl())->assertForbidden();
})->with([
    ['administrator', true], ['lab_technician', true], ['unknown', true], ['owner', false],
]);

test('central page exposes owner navigation and rejects non technician review ids', function () {
    expect(TechnicianSalaries::canAccess())->toBeTrue()
        ->and(TechnicianSalaries::getNavigationParentItem())->toBe(LabCaseResource::getNavigationLabel());
    $this->get(TechnicianSalaries::getUrl())->assertOk();
    $this->tech->update(['is_active' => false]);
    expect(fn () => Livewire::test(TechnicianSalaries::class)->call('openSalary', $this->tech->id))->toThrow(ModelNotFoundException::class);
});

test('fixed current month and unpaid carry reuse existing salary records', function () {
    $this->tech->update(['salary_type' => 'fixed', 'monthly_salary_gel' => 900]);
    $page = Livewire::test(TechnicianSalaries::class)->set('ready', true)->assertSee('900.00')
        ->call('openSalary', $this->tech->id)->assertMountedActionModalSee('900.00')
        ->callMountedAction()->assertHasNoFormErrors();
    expect($page->instance()->overview()['totals'][$this->tech->id])->toEqual(0);
    $page->call('openHistory', $this->tech->id)->assertMountedActionModalSee('900.00');
});
test('batch totals preserve modeler attribution effective rates carry and settled eligibility', function () {
    $modeler = $this->tech->replicate();
    $modeler->salary_main_technician = false;
    $modeler->salary_modeler = true;
    $modeler->user_id = User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN])->id;
    $modeler->save();
    foreach (['zircon_modeling', 'pmma_modeling'] as $type) {
        $modeler->salaryRates()->create(['work_type' => $type, 'amount' => 7, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-09-01']);
        $modeler->salaryRates()->create(['work_type' => $type, 'amount' => 99, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2027-01-01']);
    }
    $this->case->update(['modeled_by' => $modeler->user_id]);
    $employees = Employee::activeTechnicians()->get();
    foreach ([null, '2026-09-19', '2026-09-20'] as $from) {
        $totals = $this->service->pendingTotals($employees, $from);
        foreach ($employees as $employee) {
            expect($totals[$employee->id])->toEqual($this->service->pending($employee, $from)->sum('amount_gel'));
        }
    }
    $this->service->settle($modeler, $this->service->pending($modeler)->keys()->all(), allocation: ['clinic_cash_gel' => 100, 'israeli_cash_gel' => 0]);
    $page = Livewire::test(TechnicianSalaries::class)->set('ready', true);
    expect($page->instance()->overview()['totals'][$modeler->id])->toEqual(75);
    $page->call('openSalary', $modeler->id)->assertMountedActionModalSee('75.00');
});

test('active technicians without salary settings remain visible and owner can view their history', function () {
    $this->tech->update(['salary_type' => null, 'salary_active' => false]);
    $page = Livewire::test(TechnicianSalaries::class)->set('ready', true)->assertSee($this->tech->full_name);
    expect($page->instance()->overview()['records']->modelKeys())->toBe([$this->tech->id]);
    $page->call('openHistory', $this->tech->id)->assertMountedActionModalSee(__('employees.salary.no_history'));
});
