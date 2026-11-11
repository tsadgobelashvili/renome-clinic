<?php

use App\Filament\Pages\TechnicianSalaries;
use App\Filament\Resources\LabTechnicians\Pages\ViewLabTechnician;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use App\Services\EmployeeSalaryService;
use App\Services\ExternalLabCaseData;
use App\Support\TechnicianSalaryReview;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

test('case doctor is shown once for aggregated works with unchanged amounts and selection', function () {
    $doctor = Doctor::create(['first_name' => 'Case', 'last_name' => 'Doctor']);
    $this->case->update(['doctor_id' => $doctor->id]);
    $rows = $this->service->pending($this->tech);
    DB::enableQueryLog();
    $group = $this->review->groups($this->tech->id, $rows)->sole();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($group['doctors'])->toBe([$doctor->id => 'Case Doctor'])
        ->and($group['items'])->toBe($rows->all())
        ->and($group['total_cents'])->toBe(78000)
        ->and($rows->sum('amount_gel'))->toBe(780.0)
        ->and($queries)->toHaveCount(2);

    app()->setLocale('ka');
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('calculateSalary');
    $page->assertSchemaStateSet(['selected_items' => $rows->keys()->all()]);
    $html = $page->getMountedActionModalHtml();
    expect(substr_count($html, 'Case Doctor'))->toBe(1);
    preg_match_all('/<th\b[^>]*>(.*?)<\/th>/su', $html, $headers);
    $labels = array_map(fn ($header) => trim(strip_tags($header)), $headers[1]);
    expect(array_slice($labels, 1, 5))->toBe(['თარიღი', 'ექიმი', 'პაციენტი', 'სამუშაო', 'ხელფასი']);
    $page->call('toggleSalaryReviewGroup', $group['key'])
        ->assertMountedActionModalSee('625.00')->assertMountedActionModalSee('125.00')->assertMountedActionModalSee('30.00');
    expect($page->getMountedActionModalHtml())->toContain('colspan="7"');
    expect($this->service->pending($this->tech)->all())->toBe($rows->all());
    Livewire::test(TechnicianSalaries::class)->call('openSalary', $this->tech->id)
        ->assertMountedActionModalSee('Case Doctor');
});

test('different cases for one patient show their own doctors and never the visit doctor', function () {
    $first = Doctor::create(['first_name' => 'First', 'last_name' => 'Doctor']);
    $second = Doctor::create(['first_name' => 'Second', 'last_name' => 'Doctor']);
    $visitDoctor = Doctor::create(['first_name' => 'Unrelated', 'last_name' => 'Doctor']);
    $this->case->patient->visits()->create(['doctor_id' => $visitDoctor->id, 'visit_date' => '2026-09-19']);
    $this->case->update(['doctor_id' => $first->id]);
    $other = $this->case->replicate();
    $other->doctor_id = $second->id;
    $other->save();
    $other->mainWorks()->create(['material' => 'pmma', 'quantity' => 2, 'technician_id' => $this->tech->id]);

    $rows = $this->service->pending($this->tech);
    $groups = $this->review->groups($this->tech->id, $rows)->keyBy('case_id');
    expect($groups)->toHaveCount(2)
        ->and($groups[$this->case->id]['doctors'])->toBe([$first->id => 'First Doctor'])
        ->and($groups[$other->id]['doctors'])->toBe([$second->id => 'Second Doctor'])
        ->and($groups->sum('total_cents'))->toBe(79000)
        ->and($rows->sum('amount_gel'))->toBe(790.0);
    Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('calculateSalary')
        ->assertMountedActionModalSee('First Doctor')->assertMountedActionModalSee('Second Doctor')
        ->assertMountedActionModalDontSee('Unrelated Doctor');
});

test('missing case doctor displays a dash without falling back to patient assignments', function () {
    $doctor = Doctor::create(['first_name' => 'Patient', 'last_name' => 'Doctor']);
    $this->case->patient->visits()->create(['doctor_id' => $doctor->id, 'visit_date' => '2026-09-19']);
    $group = $this->review->groups($this->tech->id, $this->service->pending($this->tech))->sole();
    expect($group['doctors'])->toBe([])->and($group['total_cents'])->toBe(78000);
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('calculateSalary');
    $page->assertMountedActionModalDontSee('Patient Doctor');
    expect($page->getMountedActionModalHtml())->toMatch('/<td[^>]*>—<\/td>/u');
});

test('external case shows the external Doctor field without changing historical internal assignments', function () {
    $assistant = Employee::create([
        'first_name' => 'Assigned', 'last_name' => 'Assistant', 'is_active' => true, 'show_in_lab_doctor_list' => true,
        'position_id' => EmployeePosition::where('name', 'Assistant')->sole()->id,
    ]);
    $doctor = Doctor::create(['first_name' => 'Order', 'last_name' => 'Doctor', 'is_active' => true, 'external_lab_enabled' => true]);
    $unrelated = Doctor::create(['first_name' => 'Latest', 'last_name' => 'Doctor']);
    $this->case->patient->visits()->create(['doctor_id' => $unrelated->id, 'visit_date' => '2026-09-20']);
    $this->case->update(['assistant_employee_id' => $assistant->id]);
    $this->case->update(ExternalLabCaseData::prepare([
        'source' => 'external', 'external_doctor_name' => $doctor->full_name, 'external_patient_name' => 'Order Patient',
    ], $this->case));
    $before = $this->case->fresh()->getAttributes();
    $rows = $this->service->pending($this->tech);

    DB::enableQueryLog();
    $group = $this->review->groups($this->tech->id, $rows)->sole();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($group['doctors'])->toBe(['external:'.$this->case->id => 'Order Doctor'])
        ->and($queries)->toHaveCount(2)
        ->and($group['items'])->toBe($rows->all())
        ->and($group['total_cents'])->toBe(78000);
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('calculateSalary');
    $page->assertMountedActionModalSee('Order Doctor')->assertMountedActionModalDontSee('Assigned Assistant')
        ->assertMountedActionModalDontSee('Latest Doctor')->assertSchemaStateSet(['selected_items' => $rows->keys()->all()]);
    expect($this->case->fresh()->getAttributes())->toBe($before)
        ->and($this->service->pending($this->tech)->all())->toBe($rows->all());
});

test('internal assistant cases show the selected assistant instead of patient doctors or visits', function (string $source) {
    $assistant = Employee::create([
        'first_name' => 'Internal', 'last_name' => 'Assistant', 'is_active' => true, 'show_in_lab_doctor_list' => true,
        'position_id' => EmployeePosition::where('name', 'Assistant')->sole()->id,
    ]);
    $primary = Doctor::create(['first_name' => 'Primary', 'last_name' => 'Doctor']);
    $other = Doctor::create(['first_name' => 'Other', 'last_name' => 'Doctor']);
    $this->case->patient->doctors()->attach($primary, ['is_primary' => true, 'assignment_source' => 'manual', 'role' => 'orthopedics']);
    $this->case->patient->doctors()->attach($other, ['is_primary' => false, 'assignment_source' => 'manual', 'role' => 'therapy']);
    $this->case->patient->visits()->create(['doctor_id' => $primary->id, 'visit_date' => '2026-09-19']);
    $this->case->patient->visits()->create(['doctor_id' => $other->id, 'visit_date' => '2026-09-20']);
    $this->case->update(['source' => $source, 'assistant_employee_id' => $assistant->id]);
    $before = $this->case->fresh()->getAttributes();
    $rows = $this->service->pending($this->tech);
    $group = $this->review->groups($this->tech->id, $rows)->sole();
    expect($group['doctors'])->toBe(['employee:'.$assistant->id => 'Internal Assistant'])
        ->and($group['items'])->toBe($rows->all())->and($group['total_cents'])->toBe(78000);
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('calculateSalary');
    $page->assertMountedActionModalSee('Internal Assistant')->assertMountedActionModalDontSee('Primary Doctor')
        ->assertMountedActionModalDontSee('Other Doctor')->assertSchemaStateSet(['selected_items' => $rows->keys()->all()]);
    Livewire::test(TechnicianSalaries::class)->call('openSalary', $this->tech->id)
        ->assertMountedActionModalSee('Internal Assistant')->assertMountedActionModalDontSee('Primary Doctor')
        ->assertMountedActionModalDontSee('Other Doctor');
    expect($this->case->fresh()->getAttributes())->toBe($before)
        ->and($this->service->pending($this->tech)->all())->toBe($rows->all());
})->with(['clinic', 'israeli']);

test('external Doctor field is displayed independently of billing and historical doctor links', function () {
    $direct = Doctor::create(['first_name' => 'Direct', 'last_name' => 'Doctor']);
    $external = Doctor::create(['first_name' => 'External', 'last_name' => 'Doctor']);
    $this->case->update(['source' => 'external', 'doctor_id' => $direct->id,
        'external_billing_doctor_id' => $external->id, 'external_doctor_name' => $external->full_name]);
    expect($this->review->groups($this->tech->id, $this->service->pending($this->tech))->sole()['doctors'])
        ->toBe(['external:'.$this->case->id => 'External Doctor']);
    $this->case->update(['doctor_id' => null, 'external_billing_doctor_id' => null]);
    expect($this->review->groups($this->tech->id, $this->service->pending($this->tech))->sole()['doctors'])
        ->toBe(['external:'.$this->case->id => 'External Doctor']);
});

test('historically assigned assistants remain visible after role eligibility and active status change', function () {
    $assistant = Employee::create([
        'first_name' => 'Historical', 'last_name' => 'Assistant', 'is_active' => true, 'show_in_lab_doctor_list' => true,
        'position_id' => EmployeePosition::where('name', 'Assistant')->sole()->id,
    ]);
    $this->case->update(['assistant_employee_id' => $assistant->id]);
    $assistant->update(['is_active' => false, 'show_in_lab_doctor_list' => false,
        'position_id' => EmployeePosition::where('name', 'Manager')->sole()->id]);
    $before = $this->case->fresh()->getAttributes();
    $rows = $this->service->pending($this->tech);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $group = $this->review->groups($this->tech->id, $rows)->sole();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($group['doctors'])->toBe(['employee:'.$assistant->id => 'Historical Assistant'])
        ->and($queries)->toHaveCount(2)->and($group['items'])->toBe($rows->all())
        ->and($group['total_cents'])->toBe(78000);
    Livewire::test(TechnicianSalaries::class)->call('openSalary', $this->tech->id)
        ->assertMountedActionModalSee('Historical Assistant')
        ->assertSchemaStateSet(['selected_items' => $rows->keys()->all()]);
    Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('calculateSalary')
        ->assertMountedActionModalSee('Historical Assistant');
    expect($this->case->fresh()->getAttributes())->toBe($before)
        ->and($this->service->pending($this->tech)->all())->toBe($rows->all());
});

test('historically assigned inactive doctors remain visible without eligibility filters', function () {
    $doctor = Doctor::create(['first_name' => 'Historical', 'last_name' => 'Doctor', 'is_active' => false]);
    $this->case->update(['doctor_id' => $doctor->id]);
    $rows = $this->service->pending($this->tech);
    expect($this->review->groups($this->tech->id, $rows)->sole()['doctors'])
        ->toBe([$doctor->id => 'Historical Doctor']);
    Livewire::test(TechnicianSalaries::class)->call('openSalary', $this->tech->id)
        ->assertMountedActionModalSee('Historical Doctor');
});

test('an empty external Doctor field stays empty even when a billing doctor exists', function () {
    $billing = Doctor::create(['first_name' => 'Billing', 'last_name' => 'Only', 'external_lab_enabled' => true]);
    $this->case->update(['source' => 'external', 'external_billing_doctor_id' => $billing->id]);
    $before = $this->case->fresh()->getAttributes();
    $rows = $this->service->pending($this->tech);
    $group = $this->review->groups($this->tech->id, $rows)->sole();
    expect($group['doctors'])->toBe([])->and($group['items'])->toBe($rows->all())
        ->and($group['total_cents'])->toBe(78000);
    $page = Livewire::test(TechnicianSalaries::class)->call('openSalary', $this->tech->id)
        ->assertMountedActionModalDontSee('Billing Only');
    expect($page->getMountedActionModalHtml())->toMatch('/<td[^>]*>—<\/td>/u')
        ->and($this->case->fresh()->getAttributes())->toBe($before);
});

test('external Doctor text is shown verbatim without matching a role or substituting its billing doctor', function () {
    $billing = Doctor::create(['first_name' => 'Unrelated', 'last_name' => 'Billing', 'external_lab_enabled' => true]);
    $this->case->update(['source' => 'external', 'external_doctor_name' => 'Selected External Assistant',
        'external_billing_doctor_id' => $billing->id]);
    $before = $this->case->fresh()->getAttributes();
    $rows = $this->service->pending($this->tech);
    expect($this->review->groups($this->tech->id, $rows)->sole()['doctors'])
        ->toBe(['external:'.$this->case->id => 'Selected External Assistant']);
    Livewire::test(TechnicianSalaries::class)->call('openSalary', $this->tech->id)
        ->assertMountedActionModalSee('Selected External Assistant')->assertMountedActionModalDontSee('Unrelated Billing');
    Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('calculateSalary')
        ->assertMountedActionModalSee('Selected External Assistant')->assertMountedActionModalDontSee('Unrelated Billing');
    expect($this->case->fresh()->getAttributes())->toBe($before)
        ->and($this->service->pending($this->tech)->all())->toBe($rows->all());
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

test('salary overview grand total includes technicians beyond the first page', function () {
    for ($i = 0; $i < 26; $i++) {
        Employee::create([
            'first_name' => 'Fixed '.$i, 'last_name' => 'Technician',
            'position_id' => $this->tech->position_id, 'is_active' => true,
            'salary_type' => 'fixed', 'salary_active' => true, 'monthly_salary_gel' => 100,
        ]);
    }
    $page = Livewire::test(TechnicianSalaries::class)->set('ready', true);
    $overview = $page->instance()->overview();
    expect($overview['records']->count())->toBe(25)
        ->and($overview['grandTotal'])->toBe(3380.0);
    $page->assertSee('3,380.00');
});

test('combined salary keeps work pending and finalizes the fixed amount once per month', function () {
    $this->travelTo(Carbon::parse('2026-09-25'));
    $this->tech->update(['salary_type' => 'combined', 'monthly_salary_gel' => 1000, 'salary_effective_from' => '2026-09-01']);
    $schedule = $this->service->monthlySchedule($this->tech);
    expect($schedule['due'])->toBe([])->and($schedule['upcoming']['due_date'])->toBe('2026-10-01');
    $september = Livewire::test(TechnicianSalaries::class)->set('ready', true);
    expect($september->instance()->overview()['grandTotal'])->toBe(780.0);
    expect($this->service->fixedSalaryMonthOptions($this->tech))->toHaveKey('2026-09');
    $this->travelTo(Carbon::parse('2026-10-01'));
    seedTechnicianClinicCash();
    $page = Livewire::test(TechnicianSalaries::class)->set('ready', true);
    expect($page->instance()->overview()['grandTotal'])->toBe(1780.0);
    $page->call('openMonthlySalary', $this->tech->id)->fillForm(['month' => '2026-09', 'payment_source' => 'clinic'])
        ->callMountedAction()->assertHasNoActionErrors();
    $settlement = $this->tech->salarySettlements()->sole();
    expect($settlement->salary_type)->toBe('fixed')->and((float) $settlement->total_gel)->toBe(1000.0)
        ->and($this->service->pending($this->tech)->sum('amount_gel'))->toEqual(780);
    expect($page->instance()->overview()['grandTotal'])->toBe(780.0);
    expect(fn () => $this->service->settleFixed($this->tech, '2026-09'))
        ->toThrow(ValidationException::class);
    $this->service->undo($settlement);
    expect($page->instance()->overview()['grandTotal'])->toBe(1780.0);
    $this->service->settleFixed($this->tech, '2026-09');
    expect($this->tech->salarySettlements()->where('status', 'confirmed')->count())->toBe(1);
});

test('unpaid combined monthly salaries survive month changes', function () {
    $this->travelTo(Carbon::parse('2026-11-01'));
    $this->tech->update(['salary_type' => 'combined', 'monthly_salary_gel' => 800, 'salary_effective_from' => '2026-09-01']);
    $schedule = $this->service->monthlySchedule($this->tech);
    expect(array_column($schedule['due'], 'month'))->toBe(['2026-09', '2026-10']);
    $this->service->settleFixed($this->tech, '2026-09');
    expect(array_column($this->service->monthlySchedule($this->tech)['due'], 'month'))->toBe(['2026-10']);
});
