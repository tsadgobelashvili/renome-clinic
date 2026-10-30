<?php

use App\Filament\Pages\TechnicianSalaries;
use App\Filament\Resources\LabTechnicians\Pages\ViewLabTechnician;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\EmployeeSalaryFunding;
use App\Services\EmployeeSalaryService;
use Carbon\Carbon;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/EmployeeSalaryFunding.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-29 12:30:00'));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->tech = Employee::create([
        'first_name' => 'Fixed', 'last_name' => 'Technician',
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id,
        'is_active' => true, 'salary_active' => true, 'salary_type' => 'combined',
        'monthly_salary_gel' => 300, 'salary_effective_from' => '2026-08-01',
        'salary_payment_schedule' => '1', 'salary_payout_day' => 1,
    ]);
    $this->service = app(EmployeeSalaryService::class);
});

test('fixed salary month is independent of early late historical or advance payment dates', function (string $type, string $month, string $date, int $day) {
    $this->travelTo(Carbon::parse($date.' 12:30:00'));
    seedTechnicianClinicCash();
    $this->tech->update(['salary_type' => $type, 'salary_payment_schedule' => (string) $day, 'salary_payout_day' => $day]);
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])
        ->mountAction($type === 'combined' ? 'monthlySalary' : 'calculateSalary');
    $field = $page->instance()->getSchema('mountedActionSchema0')->getComponentByStatePath('month');
    expect($field)->toBeInstanceOf(Select::class)
        ->and($field->getSearchResults($month))->toHaveKey($month);

    $page->fillForm(['month' => $month, 'payment_source' => 'clinic'])->callMountedAction()->assertHasNoActionErrors();
    $settlement = $this->tech->salarySettlements()->sole();
    expect($settlement->salary_month)->toBe($month)->and($settlement->active_month)->toBe($month)
        ->and($settlement->period_from->format('Y-m'))->toBe($month)
        ->and($settlement->settled_at->format('Y-m-d H:i:s'))->toBe($date.' 12:30:00')
        ->and($settlement->total_gel)->toBe('300.00')
        ->and($this->tech->fresh()->monthly_salary_gel)->toBe('300.00')
        ->and($this->tech->fresh()->salary_payout_day)->toBe($day)
        ->and($this->tech->fresh()->salary_payment_schedule)->toBe((string) $day);
    expect(fn () => $this->service->settleFixed($this->tech, $month))->toThrow(ValidationException::class);
    expect($this->tech->salarySettlements()->count())->toBe(1);
})->with(['fixed', 'combined'])->with([
    'historical unpaid' => ['2026-08', '2026-10-29', 1],
    'current month late' => ['2026-10', '2026-10-29', 1],
    'current month early' => ['2026-10', '2026-10-02', 31],
    'late after earned month' => ['2026-09', '2026-10-29', 1],
    'next month advance' => ['2026-11', '2026-10-29', 1],
    'advance beyond a preset horizon' => ['2032-03', '2026-10-29', 1],
]);

test('first salary month has options before its projected due date', function () {
    seedTechnicianClinicCash();
    $this->tech->update(['salary_effective_from' => '2026-10-01']);
    expect($this->service->monthlySchedule($this->tech)['due'])->toBeEmpty();
    $page = Livewire::test(TechnicianSalaries::class)->call('openMonthlySalary', $this->tech->id);
    $field = $page->instance()->getSchema('mountedActionSchema0')->getComponentByStatePath('month');
    expect($field->getOptions())->toBe(['2026-10' => '2026-10', '2026-11' => '2026-11']);
    $page->assertSchemaStateSet(['month' => '2026-10'])->fillForm(['payment_source' => 'clinic'])
        ->callMountedAction()->assertHasNoActionErrors();
    expect($this->tech->salarySettlements()->sole()->salary_month)->toBe('2026-10');
});

test('dropdown lists historical unpaid current and advance months but paid months remain in history', function () {
    $paid = $this->service->settleFixed($this->tech, '2026-08');
    $original = $paid->fresh()->getRawOriginal();
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('monthlySalary');
    $field = $page->instance()->getSchema('mountedActionSchema0')->getComponentByStatePath('month');
    expect(array_keys($field->getOptions()))->toBe(['2026-09', '2026-10', '2026-11'])
        ->and($field->getSearchResults('2026-08'))->toBeEmpty();
    $page->fillForm(['month' => '2026-08'])->callMountedAction()->assertHasActionErrors(['month']);
    expect($paid->fresh()->getRawOriginal())->toBe($original);

    $page->unmountAction()->mountAction('salaryHistory')
        ->assertMountedActionModalSee('2026-08')
        ->assertMountedActionModalSee('29.10.2026 12:30')
        ->assertMountedActionModalSee(__('employees.salary.confirmed'));
    $this->service->undo($paid);
    expect($this->service->fixedSalaryMonthOptions($this->tech))->toHaveKey('2026-08');
    $this->service->settleFixed($this->tech, '2026-08');
    expect($this->tech->salarySettlements()->whereNotNull('active_month')->count())->toBe(1)
        ->and($paid->fresh()->status)->toBe('undone');
});

test('already finalized current month does not silently default to an advance', function () {
    $this->service->settleFixed($this->tech, '2026-10');
    Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])
        ->mountAction('monthlySalary')->assertSchemaStateSet(['month' => null])
        ->callMountedAction()->assertHasActionErrors(['month']);
    expect($this->tech->salarySettlements()->count())->toBe(1);
});

test('effective date inactive salary and malformed months remain protected', function (array $settings, string $month) {
    $this->tech->update($settings);
    expect($this->service->fixedSalaryMonthOptions($this->tech, $month))->toBeEmpty();
    expect(fn () => $this->service->settleFixed($this->tech, $month))->toThrow(ValidationException::class);
    expect($this->tech->salarySettlements()->count())->toBe(0);
})->with([
    'before effective month' => [[], '2026-07'],
    'inactive employee' => [['is_active' => false], '2026-10'],
    'inactive salary' => [['salary_active' => false], '2026-10'],
    'performance only' => [['salary_type' => 'performance'], '2026-10'],
    'invalid month' => [[], '2026-13'],
]);

test('advance fixed settlement is excluded from upcoming and subsequent due planning totals', function () {
    $this->tech->update(['salary_effective_from' => '2026-10-01']);
    $page = Livewire::test(TechnicianSalaries::class)->set('ready', true);
    expect($page->instance()->overview()['grandTotal'])->toBe(0.0)
        ->and($this->service->monthlySchedule($this->tech)['upcoming']['month'])->toBe('2026-10');
    $this->service->settleFixed($this->tech, '2026-10');
    $this->service->settleFixed($this->tech, '2026-11');
    expect($this->service->monthlySchedule($this->tech))->toBe(['due' => [], 'upcoming' => null]);
    $this->travelTo(Carbon::parse('2026-11-29 12:30:00'));
    expect($page->instance()->overview()['grandTotal'])->toBe(0.0)
        ->and($this->service->monthlySchedule($this->tech))->toBe(['due' => [], 'upcoming' => null]);
    $this->travelTo(Carbon::parse('2026-12-29 12:30:00'));
    expect($page->instance()->overview()['grandTotal'])->toBe(0.0)
        ->and($this->service->monthlySchedule($this->tech)['upcoming']['month'])->toBe('2026-12');
});

test('advance before the first effective month is not counted again as an upcoming obligation', function () {
    $this->tech->update(['salary_effective_from' => '2027-02-01']);
    expect($this->service->monthlySchedule($this->tech)['upcoming']['month'])->toBe('2027-02');
    $this->service->settleFixed($this->tech, '2027-02');
    expect($this->service->monthlySchedule($this->tech))->toBe(['due' => [], 'upcoming' => null]);
});

test('fixed-only current obligation disappears after an advance reaches its salary month', function () {
    $this->tech->update(['salary_type' => 'fixed', 'salary_effective_from' => '2026-11-01']);
    $this->service->settleFixed($this->tech, '2026-11');
    $this->travelTo(Carbon::parse('2026-11-29 12:30:00'));
    $page = Livewire::test(TechnicianSalaries::class)->set('ready', true);
    expect($page->instance()->overview()['grandTotal'])->toBe(0.0);
});

test('existing finance funding records the actual date separately from a historical salary month', function () {
    seedTechnicianClinicCash();
    $settlement = $this->service->settleFixed($this->tech, '2026-08');
    app(EmployeeSalaryFunding::class)->pay($settlement, ['clinic_cash_gel' => 300, 'israeli_cash_gel' => 0]);
    $expense = $settlement->fresh()->financeExpense;
    expect($settlement->salary_month)->toBe('2026-08')
        ->and($settlement->settled_at->format('Y-m-d'))->toBe('2026-10-29')
        ->and($expense->transaction_date->format('Y-m-d'))->toBe('2026-10-29')
        ->and($expense->amount)->toBe('300.00')->and($expense->category)->toBe('lab_salary')
        ->and($settlement->fresh()->actual_paid_gel)->toBe('300.00');
    app(EmployeeSalaryFunding::class)->pay($settlement, ['clinic_cash_gel' => 300, 'israeli_cash_gel' => 0]);
    expect(FinanceTransaction::where('employee_salary_settlement_id', $settlement->id)->count())->toBe(1);
    Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('salaryHistory')
        ->assertMountedActionModalSee('2026-08')->assertMountedActionModalSee('29.10.2026 12:30')
        ->assertMountedActionModalSee(__('employees.salary.actual_paid').': 300.00');
});
