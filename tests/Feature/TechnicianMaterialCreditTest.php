<?php

use App\Filament\Pages\TechnicianSalaries;
use App\Models\CashboxDay;
use App\Models\Employee;
use App\Models\EmployeeSalarySettlement;
use App\Models\FinanceTransaction;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\EmployeeSalaryFunding;
use App\Services\EmployeeSalaryService;
use App\Services\FinanceManager;
use App\Support\CashboxManager;
use App\Support\TechnicianSalaryReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\DatabaseSafety;

beforeEach(function () {
    DatabaseSafety::assertInMemory(DB::connection()->getConfig());
    Http::preventStrayRequests();
    $schemas = [
        'users' => 'name email password role is_active created_at updated_at',
        'employees' => 'first_name last_name user_id position_id is_active salary_active salary_type salary_effective_from monthly_salary_gel salary_main_technician salary_modeler salary_milling_eligible salary_abutment_eligible salary_balk_eligible created_at updated_at',
        'employee_positions' => 'name is_active is_technician',
        'employee_salary_rates' => 'employee_id work_type amount basis is_active effective_from',
        'employee_salary_settlements' => 'employee_id salary_type period_from period_until salary_month active_month total_gel current_salary_gel opening_carry_gel closing_carry_gel actual_paid_gel clinic_cash_gel israeli_cash_gel settings_snapshot status settled_at created_by undone_at undone_by created_at updated_at',
        'employee_salary_settlement_items' => 'employee_salary_settlement_id lab_main_work_id lab_additional_work_id active_source_key work_date patient_name work_type quantity rate_amount rate_basis amount_gel created_at updated_at',
        'patients' => 'first_name last_name',
        'lab_cases' => 'patient_id case_date source external_patient_name related_case_id case_relationship modeled_by',
        'lab_main_works' => 'lab_case_id material quantity technician_id',
        'lab_additional_works' => 'lab_case_id work_type quantity technician_id',
        'finance_transactions' => 'employee_salary_settlement_id amount currency',
    ];
    foreach ($schemas as $table => $columns) {
        $fields = array_map(fn ($column) => $column.' '.(str_ends_with($column, '_id') || preg_match('/amount|quantity|_gel$|^salary_(main|modeler|milling|abutment|balk)|^is_active$|^salary_active$/', $column) ? 'NUMERIC' : 'TEXT'), explode(' ', $columns));
        DB::statement('CREATE TABLE '.$table.' (id INTEGER PRIMARY KEY AUTOINCREMENT, '.implode(', ', $fields).')');
    }
    $this->actingAs(User::forceCreate(['name' => 'Owner', 'email' => 'lab-credit@example.test', 'role' => User::ROLE_OWNER, 'is_active' => true]));
    DB::table('employee_positions')->insert(['id' => 1, 'name' => 'Technician', 'is_active' => true, 'is_technician' => true]);
    DB::table('employees')->insert(['id' => 1, 'first_name' => 'Configured', 'last_name' => 'Technician', 'position_id' => 1,
        'is_active' => true, 'salary_active' => true, 'salary_type' => 'performance', 'salary_modeler' => true]);
    foreach (['pmma_modeling', 'zircon_modeling'] as $type) {
        DB::table('employee_salary_rates')->insert(['employee_id' => 1, 'work_type' => $type, 'amount' => 5, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-01-01']);
    }
    DB::table('patients')->insert(['id' => 1, 'first_name' => 'Same', 'last_name' => 'Patient']);
    DB::table('lab_cases')->insert(['id' => 1, 'patient_id' => 1, 'case_date' => '2026-09-01', 'source' => 'clinic']);
    $this->tech = Employee::find(1);
    $this->service = app(EmployeeSalaryService::class);
    // Exercise real settlement transactions and snapshots. The external cash layer is
    // replaced by a bounded fixture writer, never an application cashbox.
    $this->mock(EmployeeSalaryFunding::class, function ($mock) {
        $mock->shouldReceive('availableCash')->andReturn(10000.0);
        $mock->shouldReceive('pay')->andReturnUsing(function ($settlement, $allocation) {
            $paid = (float) ($allocation['clinic_cash_gel'] ?? 0) + (float) ($allocation['israeli_cash_gel'] ?? 0);
            if ($paid <= 0 || $paid > (float) $settlement->total_gel) {
                throw ValidationException::withMessages(['clinic_cash_gel' => 'Invalid test allocation']);
            }
            $settlement->update(['actual_paid_gel' => $paid, 'closing_carry_gel' => (float) $settlement->total_gel - $paid]);
            DB::table('finance_transactions')->insert(['employee_salary_settlement_id' => $settlement->id, 'amount' => $paid, 'currency' => 'GEL']);
        });
    });
});

function materialWork(string $material, int $quantity = 28, int $case = 1, int $technician = 1): int
{
    return DB::table('lab_main_works')->insertGetId(['lab_case_id' => $case, 'material' => $material, 'quantity' => $quantity, 'technician_id' => $technician]);
}

function materialPay($test, float $paid, ?array $selected = null): EmployeeSalarySettlement
{
    return $test->service->settle($test->tech, $selected ?? $test->service->pending($test->tech)->keys()->all(), allocation: ['clinic_cash_gel' => $paid, 'israeli_cash_gel' => 0]);
}

test('paid PMMA credits the configured Zirconia entitlement without rewriting history', function (int $rate, float $due) {
    materialWork('pmma');
    $prior = materialPay($this, 140);
    $snapshot = $prior->items()->get()->toArray();
    DB::table('employee_salary_rates')->where('work_type', 'zircon_modeling')->update(['amount' => $rate]);
    materialWork('zircon');
    $rows = $this->service->pending($this->tech);
    expect($rows->sum('amount_gel'))->toBe($due)
        ->and($rows->first()['rate_amount'])->toBe(number_format($rate, 2, '.', ''))
        ->and($rows->first()['material_credit_gel'])->toEqual(140)
        ->and($this->service->pendingTotals(collect([$this->tech]))[1])->toBe($due);
    if ($due > 0) {
        $new = materialPay($this, $due);
        expect((float) $new->total_gel)->toBe($due)->and($new->items()->sole()->rate_amount)->toBe('10.00');
        expect($this->service->pending($this->tech))->toBeEmpty();
    } else {
        expect(fn () => materialPay($this, 1))->toThrow(ValidationException::class);
    }
    expect($prior->fresh()->actual_paid_gel)->toBe('140.00')->and($prior->items()->get()->toArray())->toBe($snapshot)
        ->and(DB::table('finance_transactions')->sum('amount'))->toEqual(140 + $due);
})->with(['equal configured rates' => [5, 0.0], 'different configured rates' => [10, 140.0]]);

test('both materials before any payment and PMMA alone preserve existing compensation rules', function () {
    materialWork('pmma');
    expect($this->service->pending($this->tech)->sum('amount_gel'))->toBe(140.0);
    materialWork('zircon');
    $rows = $this->service->pending($this->tech);
    expect($rows->pluck('work_type')->all())->toBe(['zircon_modeling'])->and($rows->sum('amount_gel'))->toBe(140.0);
    materialPay($this, 140);
    expect($this->service->pending($this->tech))->toBeEmpty();
});

test('partial PMMA payout carries only the unpaid entitlement into the Zirconia payout', function () {
    materialWork('pmma');
    materialPay($this, 70);
    DB::table('employee_salary_rates')->where('work_type', 'zircon_modeling')->update(['amount' => 10]);
    materialWork('zircon');
    expect($this->service->pending($this->tech)->sum('amount_gel'))->toBe(140.0)->and($this->service->openingCarry($this->tech))->toBe(70.0);
    $new = materialPay($this, 210);
    expect($new->total_gel)->toBe('210.00')->and($new->closing_carry_gel)->toBe('0.00')
        ->and(DB::table('finance_transactions')->sum('amount'))->toEqual(280);
});

test('differing quantities flag overpayment without recovering paid cash and remove only superseded unpaid carry', function (float $paid, float $due, float $overpaid) {
    materialWork('pmma');
    materialPay($this, $paid);
    materialWork('zircon', 20);
    $row = $this->service->pending($this->tech)->first();
    expect($row['amount_gel'])->toEqual(0)->and($row['overpayment_gel'])->toEqual($overpaid)
        ->and($this->service->openingCarry($this->tech) + $this->service->pendingTotals(collect([$this->tech]))[1])->toEqual($due);
    if ($due > 0) {
        $settlement = materialPay($this, $due);
        expect($settlement->settings_snapshot['modeling_carry_reductions'][1])->toEqual(40)
            ->and($settlement->closing_carry_gel)->toBe('0.00');
    }
    expect(DB::table('finance_transactions')->sum('amount'))->toEqual($paid + $due);
})->with(['fully paid overpayment' => [140.0, 0.0, 40.0], 'partially paid smaller final quantity' => [70.0, 30.0, 0.0]]);

test('credits remain case and employee scoped including explicit same-case links across periods', function () {
    materialWork('pmma');
    materialPay($this, 140);
    DB::table('lab_cases')->insert([
        ['id' => 2, 'patient_id' => 1, 'case_date' => '2026-10-01', 'related_case_id' => null, 'case_relationship' => null],
        ['id' => 3, 'patient_id' => 1, 'case_date' => '2026-10-01', 'related_case_id' => 1, 'case_relationship' => 'same_case'],
    ]);
    materialWork('zircon', 28, 2);
    materialWork('zircon', 28, 3);
    expect($this->service->pending($this->tech, '2026-10-01')->sum('amount_gel'))->toBe(140.0);
    $other = $this->tech->replicate();
    $other->id = 2;
    $other->save();
    DB::table('employee_salary_rates')->insert(['employee_id' => 2, 'work_type' => 'zircon_modeling', 'amount' => 5, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-01-01']);
    materialWork('zircon', 28, 1, 2);
    expect($this->service->pending($other)->sum('amount_gel'))->toBe(140.0);
});

test('multiple Zirconia rows consume the prior credit once even when paid in reverse selection order', function () {
    materialWork('pmma');
    materialPay($this, 140);
    materialWork('zircon', 20);
    materialWork('zircon', 20);
    $rows = $this->service->pending($this->tech);
    expect($rows->pluck('amount_gel')->values()->all())->toEqual([0, 60]);
    materialPay($this, 60, [$rows->keys()->last()]);
    expect($this->service->pending($this->tech)->sum('amount_gel'))->toEqual(0)
        ->and(DB::table('finance_transactions')->sum('amount'))->toEqual(200);
});

test('main production compensation that pays both materials receives no PMMA modeling credit', function () {
    DB::table('employees')->where('id', 1)->update(['salary_modeler' => false, 'salary_main_technician' => true]);
    $this->tech->refresh();
    foreach (['pmma', 'zircon'] as $type) {
        DB::table('employee_salary_rates')->insert(['employee_id' => 1, 'work_type' => $type, 'amount' => 5, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-01-01']);
    }
    materialWork('pmma');
    materialPay($this, 140);
    materialWork('zircon');
    expect($this->service->pending($this->tech)->sum('amount_gel'))->toBe(140.0);
});

test('actual payroll overview selection and payout modal use the same credit and show overpayment', function () {
    app()->setLocale('en');
    materialWork('pmma');
    materialPay($this, 70);
    materialWork('zircon', 20);
    $rows = $this->service->pending($this->tech);
    $group = app(TechnicianSalaryReview::class)->groups(1, $rows)->keys()->first();
    $page = Livewire::test(TechnicianSalaries::class)->set('ready', true);
    expect($page->instance()->overview()['totals'][1])->toBe(30.0);
    $page->call('openSalary', 1)->assertActionMounted('calculateSalary')
        ->assertSchemaStateSet(['selected_items' => $rows->keys()->all()])->call('toggleSalaryReviewGroup', $group);
    expect($page->getMountedActionModalHtml())->toContain('prior PMMA credit', '30.00', 'Superseded unpaid PMMA carry');
    $page->fillForm(['clinic_cash_gel' => 30, 'israeli_cash_gel' => 0])->callMountedAction()->assertHasNoFormErrors();
    expect(DB::table('finance_transactions')->sum('amount'))->toEqual(100);
    expect(view('filament.resources.employees.salary-history', ['record' => $this->tech])->render())
        ->toContain('prior PMMA credit', 'Superseded unpaid PMMA carry');
});

test('overpayment warnings never offset another case and history reads stay batched', function () {
    app()->setLocale('en');
    materialWork('pmma');
    materialPay($this, 140);
    materialWork('zircon', 20);
    DB::table('lab_cases')->insert(['id' => 2, 'patient_id' => 1, 'case_date' => '2026-09-01', 'source' => 'clinic']);
    materialWork('zircon', 10, 2);
    $page = Livewire::test(TechnicianSalaries::class)->call('openSalary', 1);
    expect($page->getMountedActionModalHtml())->toContain('Overpaid: 40.00 GEL', 'no automatic recovery');
    expect($this->service->pending($this->tech)->sum('amount_gel'))->toEqual(50);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->service->pendingTotals(collect([$this->tech]));
    $before = count(DB::getQueryLog());
    for ($i = 0; $i < 20; $i++) {
        materialWork('zircon', 1, 2);
    }
    DB::flushQueryLog();
    $this->service->pendingTotals(collect([$this->tech]));
    expect(DB::getQueryLog())->toHaveCount($before);
    DB::disableQueryLog();
});

test('superseded carry is reconciled even when the Zirconia row is outside the selection or date filter', function () {
    materialWork('pmma');
    materialPay($this, 70);
    materialWork('zircon', 20);
    DB::table('lab_cases')->insert(['id' => 2, 'patient_id' => 1, 'case_date' => '2026-10-01', 'source' => 'clinic']);
    materialWork('pmma', 10, 2);
    $rows = $this->service->pending($this->tech, '2026-10-01');
    expect($rows)->toHaveCount(1)->and($rows->sum('amount_gel'))->toBe(50.0)->and($this->service->openingCarry($this->tech))->toBe(30.0);
    $settlement = $this->service->settle($this->tech, $rows->keys()->all(), '2026-10-01', allocation: ['clinic_cash_gel' => 80, 'israeli_cash_gel' => 0]);
    expect($settlement->total_gel)->toBe('80.00')->and($settlement->settings_snapshot['modeling_carry_reductions'][1])->toEqual(40)
        ->and($this->service->openingCarry($this->tech))->toBe(0.0)->and($this->service->pending($this->tech)->sum('amount_gel'))->toEqual(0);
});

test('recorded PMMA snapshot survives changed configuration and per-work quantities do not multiply the rate', function () {
    DB::table('employee_salary_rates')->where('work_type', 'pmma_modeling')->update(['basis' => 'per_work', 'amount' => 40]);
    DB::table('employee_salary_rates')->where('work_type', 'zircon_modeling')->update(['basis' => 'per_work', 'amount' => 60]);
    materialWork('pmma', 28);
    $prior = materialPay($this, 40);
    DB::table('employee_salary_rates')->where('work_type', 'pmma_modeling')->update(['amount' => 99]);
    materialWork('zircon', 35);
    expect($this->service->pending($this->tech)->sum('amount_gel'))->toBe(20.0)->and($prior->items()->sole()->rate_amount)->toBe('40.00');
});

test('real salary funding receives only the net entitlement and duplicate payout retries do not create another expense', function () {
    materialWork('pmma');
    materialPay($this, 70);
    DB::table('employee_salary_rates')->where('work_type', 'zircon_modeling')->update(['amount' => 10]);
    materialWork('zircon');
    DB::statement('CREATE TABLE patient_groups (id INTEGER PRIMARY KEY, name TEXT, slug TEXT)');
    DB::table('patient_groups')->insert(['id' => 1, 'name' => 'Israeli', 'slug' => PatientGroup::ISRAEL_PARTNER_SLUG]);
    DB::statement('CREATE TABLE cashbox_days (id INTEGER PRIMARY KEY, date TEXT, status TEXT)');
    DB::table('cashbox_days')->insert(['id' => 1, 'date' => today()->toDateString(), 'status' => 'open']);
    $this->mock(CashboxManager::class)->shouldReceive('dayFor')->andReturn(CashboxDay::find(1));
    $this->partialMock(EmployeeSalaryFunding::class)->shouldReceive('availableCash')->andReturn(10000.0);
    $this->mock(FinanceManager::class)->shouldReceive('create')->once()->andReturnUsing(function ($attributes) {
        expect($attributes['amount'])->toEqual(210)->and($attributes['cash_source'])->toBe('withdrawn_cash')
            ->and($attributes['clinic_cash_gel'])->toEqual(210)->and($attributes['israeli_cash_gel'])->toEqual(0);
        DB::table('finance_transactions')->insert(['employee_salary_settlement_id' => $attributes['employee_salary_settlement_id'], 'amount' => $attributes['amount'], 'currency' => 'GEL']);

        return (new FinanceTransaction)->forceFill($attributes);
    });
    $selected = $this->service->pending($this->tech)->keys()->all();
    $settlement = materialPay($this, 210, $selected);
    expect($settlement->actual_paid_gel)->toBe('210.00')->and($settlement->closing_carry_gel)->toBe('0.00');
    expect(fn () => materialPay($this, 210, $selected))->toThrow(ValidationException::class);
    expect(DB::table('finance_transactions')->count())->toBe(2)->and(DB::table('finance_transactions')->sum('amount'))->toEqual(280);
});
