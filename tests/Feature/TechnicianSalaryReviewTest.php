<?php

use App\Filament\Resources\LabTechnicians\Pages\ViewLabTechnician;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use App\Services\EmployeeSalaryService;
use App\Support\TechnicianSalaryReview;
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

test('one case groups only the supplied eligible rows without recalculating rates', function () {
    $rows = $this->service->pending($this->tech);
    DB::enableQueryLog();
    $groups = $this->review->groups($this->tech->id, $rows);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($groups)->toHaveCount(1)
        ->and($groups->first()['items'])->toBe($rows->all())
        ->and($groups->first()['total_cents'])->toBe(78000)
        ->and($queries)->toHaveCount(2);
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('calculateSalary');
    expect(substr_count($page->getMountedActionModalHtml(), 'data-salary-group='))->toBe(1)
        ->and(substr_count($page->getMountedActionModalHtml(), 'data-salary-item='))->toBe(0);
    $page->call('toggleSalaryReviewGroup', $groups->keys()->first());
    expect(substr_count($page->getMountedActionModalHtml(), 'data-salary-item='))->toBe(3);
    $page->assertMountedActionModalSee('625.00')->assertMountedActionModalSee('125.00')->assertMountedActionModalSee('30.00');
});

test('mixed technicians keep separate totals selection and finalized snapshots', function () {
    $other = $this->tech->replicate();
    $other->salary_main_technician = false;
    $other->save();
    $other->salaryRates()->create(['work_type' => 'milling', 'amount' => 5, 'basis' => 'per_unit', 'is_active' => true]);
    $this->milling->update(['technician_id' => $other->id]);
    $a = $this->review->groups($this->tech->id, $this->service->pending($this->tech))->first();
    $b = $this->review->groups($other->id, $this->service->pending($other))->first();
    expect($a['items'])->toHaveCount(2)->and($a['total_cents'])->toBe(75000)
        ->and($b['items'])->toHaveCount(1)->and($b['total_cents'])->toBe(3000)
        ->and(array_intersect(array_keys($a['items']), array_keys($b['items'])))->toBeEmpty();
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('calculateSalary');
    $page->call('toggleSalaryReviewSelection', $a['key'])->assertSchemaStateSet(['selected_items' => []])
        ->call('toggleSalaryReviewSelection', $b['key'])->assertSchemaStateSet(['selected_items' => []])
        ->call('toggleSalaryReviewSelection', $a['key'])->assertSchemaStateSet(['selected_items' => array_keys($a['items'])])
        ->fillForm(['clinic_cash_gel' => 750, 'israeli_cash_gel' => 0])
        ->callMountedAction()->assertHasNoFormErrors();
    $settled = $this->tech->salarySettlements()->sole();
    $snapshot = $settled->items()->get()->toArray();
    expect($settled->total_gel)->toBe('750.00')->and($snapshot)->toHaveCount(2)
        ->and($this->service->pending($this->tech))->toBeEmpty()
        ->and($this->service->pending($other)->sum('amount_gel'))->toBe(30.0);
    Livewire::test(ViewLabTechnician::class, ['record' => $other->id])->mountAction('calculateSalary')
        ->call('toggleSalaryReviewGroup', $b['key'])->assertMountedActionModalSee('30.00');
    expect($settled->fresh()->total_gel)->toBe('750.00')->and($settled->items()->get()->toArray())->toBe($snapshot);
});

test('different cases for the same patient stay separate and groups page without losing selection', function () {
    for ($i = 0; $i < 25; $i++) {
        $case = $this->case->replicate();
        $case->save();
        $case->mainWorks()->create(['material' => 'pmma', 'quantity' => 1, 'technician_id' => $this->tech->id]);
    }
    $rows = $this->service->pending($this->tech);
    $groups = $this->review->groups($this->tech->id, $rows);
    expect($groups)->toHaveCount(26)->and($groups->sum('total_cents'))->toBe(90500);
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('calculateSalary');
    expect(substr_count($page->getMountedActionModalHtml(), 'data-salary-group='))->toBe(25);
    $page->call('setSalaryReviewPage', 2)->assertSchemaStateSet(['selected_items' => $rows->keys()->all()]);
    expect(substr_count($page->getMountedActionModalHtml(), 'data-salary-group='))->toBe(1);
    $last = $groups->last();
    $page->call('toggleSalaryReviewSelection', $last['key'])->call('setSalaryReviewPage', 1)
        ->assertSchemaStateSet(['selected_items' => array_values(array_diff($rows->keys()->all(), array_keys($last['items'])))])
        ->fillForm(['from' => '2026-09-20']);
    expect(substr_count($page->getMountedActionModalHtml(), 'data-salary-group='))->toBe(0);
});

test('expanded large groups render bounded details and preserve item level selection', function () {
    for ($i = 0; $i < 22; $i++) {
        $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 1, 'technician_id' => $this->tech->id]);
    }
    $rows = $this->service->pending($this->tech);
    $group = $this->review->groups($this->tech->id, $rows)->first();
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('calculateSalary')
        ->call('toggleSalaryReviewGroup', $group['key']);
    expect(substr_count($page->getMountedActionModalHtml(), 'data-salary-item='))->toBe(20);
    $selected = $rows->keys()->slice(1)->values()->all();
    $page->fillForm(['selected_items' => $selected])->call('setSalaryReviewDetailPage', 2)
        ->assertSchemaStateSet(['selected_items' => $selected]);
    expect(substr_count($page->getMountedActionModalHtml(), 'data-salary-item='))->toBe(5);
    $page->call('toggleSalaryReviewSelection', $group['key']);
    expect($page->get('mountedActions')[0]['data']['selected_items'])->toEqualCanonicalizing($rows->keys()->all());
    $page->call('toggleSalaryReviewGroup', $group['key']);
    expect(substr_count($page->getMountedActionModalHtml(), 'data-salary-item='))->toBe(0);
});
