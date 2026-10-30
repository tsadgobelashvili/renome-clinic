<?php

use App\Filament\Pages\Finance;
use App\Filament\Resources\LabTechnicians\Pages\ViewLabTechnician;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\EmployeeSalarySettlement;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\EmployeeSalaryFunding;
use App\Services\EmployeeSalaryService;
use App\Services\Finance\CashOutflowReport;
use App\Services\FinanceUsdUsageService;
use App\Services\PartnerFinanceSummary;
use App\Support\CashboxManager;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/EmployeeSalaryFunding.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-10-29 12:30:00'));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    seedTechnicianClinicCash();
    $patient = Patient::create(['first_name' => 'Synthetic', 'last_name' => 'Funding', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    foreach (['GEL' => 2000, 'USD' => 100] as $currency => $amount) {
        $patient->partnerPayments()->create(['amount' => $amount, 'currency' => $currency, 'payment_method' => 'cash', 'paid_at' => now()]);
    }
    $this->tech = Employee::create([
        'first_name' => 'Fixed', 'last_name' => 'Funding',
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id,
        'is_active' => true, 'salary_active' => true, 'salary_type' => 'combined',
        'monthly_salary_gel' => 300, 'salary_effective_from' => '2026-09-01',
        'salary_payment_schedule' => '1', 'salary_payout_day' => 1,
    ]);
    $this->service = app(EmployeeSalaryService::class);
    $this->balances = app(FinanceUsdUsageService::class);
    $this->cashBefore = ['clinic' => $this->balances->cashBalances('clinic'), 'israeli' => $this->balances->cashBalances('israeli')];
});

test('fixed modal posts one salary expense from the selected independent Finance source for any eligible month', function (string $type, string $source, string $month) {
    $this->tech->update(['salary_type' => $type]);
    $manager = app(CashboxManager::class);
    $day = $manager->dayFor(today()->toDateString());
    $drawer = $manager->summary($day)['expectedByCurrency'];
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])
        ->mountAction($type === 'combined' ? 'monthlySalary' : 'calculateSalary')
        ->assertSchemaStateSet(['actual_paid_gel' => '300.00', 'payment_source' => null])
        ->fillForm(['month' => $month, 'payment_source' => $source])
        ->callMountedAction()->assertHasNoActionErrors();

    $settlement = $this->tech->salarySettlements()->sole();
    $expense = $settlement->financeExpense;
    expect($settlement->salary_type)->toBe('fixed')->and($settlement->salary_month)->toBe($month)
        ->and($settlement->settled_at->format('Y-m-d H:i:s'))->toBe('2026-10-29 12:30:00')
        ->and($settlement->total_gel)->toBe('300.00')->and($settlement->actual_paid_gel)->toBe('300.00')
        ->and($settlement->closing_carry_gel)->toBe('0.00')->and($settlement->status)->toBe('confirmed')
        ->and($expense->employee_salary_settlement_id)->toBe($settlement->id)
        ->and($expense->type)->toBe('expense')->and($expense->category)->toBe('lab_salary')
        ->and($expense->expenseDirection->classification_code)->toBe('laboratory')
        ->and($expense->expenseType->classification_code)->toBe('salary')
        ->and($expense->amount)->toBe('300.00')->and($expense->currency)->toBe('GEL')
        ->and($expense->payment_method)->toBe('cash')->and($expense->cash_source)->toBe('withdrawn_cash')
        ->and($expense->funding_source)->toBe($source)
        ->and($expense->description)->toContain($this->tech->full_name, $month)
        ->and($expense->transaction_date->equalTo($settlement->settled_at))->toBeTrue()
        ->and($expense->cashboxTransaction)->toBeNull()
        ->and($manager->summary($day->fresh())['expectedByCurrency'])->toBe($drawer)
        ->and($this->tech->fresh()->salary_payout_day)->toBe(1)
        ->and($this->tech->fresh()->salary_payment_schedule)->toBe('1');

    foreach (['clinic', 'israeli'] as $cashSource) {
        $expected = $this->cashBefore[$cashSource];
        $expected['GEL'] -= $cashSource === $source ? 300 : 0;
        expect($this->balances->cashBalances($cashSource))->toBe($expected)
            ->and((float) $settlement->{$cashSource.'_cash_gel'})->toBe($cashSource === $source ? 300.0 : 0.0);
    }
    expect(app(PartnerFinanceSummary::class)->currentCashTotals()['GEL'])->toBe($this->cashBefore['israeli']['GEL'] - ($source === 'israeli' ? 300 : 0))
        ->and(FinanceTransaction::where('type', 'expense')->count())->toBe(1)
        ->and(PartnerFinanceTransaction::where('type', 'expense')->count())->toBe(0)
        ->and(PartnerFinanceTransaction::where('type', 'currency_exchange')->count())->toBe(0);
    if ($source === 'israeli') {
        expect($expense->israeliCashMovement->amount)->toBe('300.00')
            ->and($expense->israeliCashMovement->from_account)->toBe('cash')
            ->and($expense->israeliCashMovement->source)->toBe('israeli');
    } else {
        expect($expense->israeliCashMovement)->toBeNull();
    }
    expect(PartnerFinanceTransaction::where('type', 'salary_cash')->count())->toBe($source === 'israeli' ? 1 : 0)
        ->and(app(PartnerFinanceSummary::class)->expenseTotals()['GEL'])->toBe($source === 'israeli' ? 300.0 : 0.0);

    $report = app(CashOutflowReport::class);
    expect($report->entries('2026-10-29', '2026-10-29')->count())->toBe(1)
        ->and((float) $report->totals('2026-10-29', '2026-10-29')->get('GEL')->amount)->toBe(300.0)
        ->and($report->entries('2026-10-29', '2026-10-29', $source)->count())->toBe(1);
    $finance = Livewire::test(Finance::class)->set('source', $source === 'israeli' ? 'partner' : 'clinic')->call('showHistory', 'expenses')
        ->assertViewHas('entries', fn ($entries): bool => $entries->contains(fn (array $entry): bool => $entry['key'] === 'finance-'.$expense->id));
    $finance->call('showHistory', 'overview')->set('businessSource', $source)
        ->call('selectOverviewCard', 'cash_outflow')->call('selectCashOutflowGroup', 'expenses')
        ->assertViewHas('overviewDetails', fn ($rows): bool => $rows->count() === 1 && (float) $rows->sum('amount') === 300.0)
        ->assertSee('Lab technician salary')
        ->assertViewHas('figures', fn ($figures): bool => $figures['GEL']['expenses'] === 300.0 && $figures['GEL']['cash_outflow'] === 300.0);
    $page->mountAction('salaryHistory')->assertMountedActionModalSee($month)
        ->assertMountedActionModalSee('29.10.2026 12:30')->assertMountedActionModalSee(__('employees.salary.actual_paid').': 300.00')
        ->assertMountedActionModalDontSee(__('employees.salary.finance_unlinked_review'));
})->with(['fixed', 'combined'])->with(['clinic', 'israeli'])->with(['2026-09', '2026-10', '2032-03']);

test('fixed payment modal requires an explicit source and a positive exact full salary', function (array $data, string $field) {
    Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('monthlySalary')
        ->fillForm(['payment_source' => 'clinic', ...$data])->callMountedAction()->assertHasActionErrors([$field]);
    expect(EmployeeSalarySettlement::count())->toBe(0)->and(FinanceTransaction::where('type', 'expense')->count())->toBe(0);
})->with([
    'source required' => [['payment_source' => null], 'payment_source'],
    'amount required' => [['actual_paid_gel' => null], 'actual_paid_gel'],
    'zero' => [['actual_paid_gel' => 0], 'actual_paid_gel'],
    'partial' => [['actual_paid_gel' => 299.99], 'actual_paid_gel'],
    'overpayment' => [['actual_paid_gel' => 300.01], 'actual_paid_gel'],
    'fraction of a tetri' => [['actual_paid_gel' => '300.001'], 'actual_paid_gel'],
]);

test('forged fixed payment sources cannot bypass the single source cash mapping', function (string $source) {
    expect(fn () => $this->service->payFixed($this->tech, '2026-10', ['payment_source' => $source, 'actual_paid_gel' => 300]))
        ->toThrow(ValidationException::class);
    expect(EmployeeSalarySettlement::count())->toBe(0)->and(FinanceTransaction::where('type', 'expense')->count())->toBe(0);
})->with(['bank', 'mixed', 'withdrawn_cash']);

test('insufficient selected cash rolls back the salary and shows a source field error', function (string $source) {
    $this->tech->update(['monthly_salary_gel' => 300000]);
    Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('monthlySalary')
        ->fillForm(['payment_source' => $source])->callMountedAction()->assertHasActionErrors(['payment_source']);
    expect(EmployeeSalarySettlement::count())->toBe(0)->and(FinanceTransaction::where('type', 'expense')->count())->toBe(0)
        ->and(PartnerFinanceTransaction::where('type', 'salary_cash')->count())->toBe(0);
    foreach ($this->cashBefore as $cashSource => $balance) {
        expect($this->balances->cashBalances($cashSource))->toBe($balance);
    }
})->with(['clinic', 'israeli']);

test('history expense and movement failures roll back the entire fixed payment', function (string $source, string $stage) {
    $failure = function () use ($stage): never {
        if ($stage !== 'history') {
            expect(EmployeeSalarySettlement::count())->toBe(1);
        }
        if ($stage === 'movement') {
            expect(FinanceTransaction::where('type', 'expense')->count())->toBe(1)
                ->and(PartnerFinanceTransaction::where('type', 'salary_cash')->count())->toBe(1);
        }
        throw new RuntimeException('Synthetic fixed funding failure');
    };
    match ($stage) {
        'history' => EmployeeSalarySettlement::creating($failure),
        'expense' => FinanceTransaction::creating($failure),
        'movement' => PartnerFinanceTransaction::created($failure),
    };
    expect(fn () => $this->service->payFixed($this->tech, '2026-10', ['payment_source' => $source, 'actual_paid_gel' => 300]))
        ->toThrow(RuntimeException::class, 'Synthetic fixed funding failure');
    expect(EmployeeSalarySettlement::count())->toBe(0)->and(FinanceTransaction::where('type', 'expense')->count())->toBe(0)
        ->and(PartnerFinanceTransaction::where('type', 'salary_cash')->count())->toBe(0);
    foreach ($this->cashBefore as $cashSource => $balance) {
        expect($this->balances->cashBalances($cashSource))->toBe($balance);
    }
})->with([
    ['clinic', 'history'], ['clinic', 'expense'],
    ['israeli', 'history'], ['israeli', 'expense'], ['israeli', 'movement'],
]);

test('repeated and stale modal submissions cannot pay or deduct a fixed month twice', function (string $source) {
    $stale = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('monthlySalary')
        ->fillForm(['month' => '2026-10', 'payment_source' => $source]);
    $payment = ['payment_source' => $source, 'actual_paid_gel' => 300];
    $settlement = $this->service->payFixed($this->tech, '2026-10', $payment);
    $balance = $this->balances->cashBalances($source);
    expect(fn () => $this->service->payFixed($this->tech, '2026-10', $payment))->toThrow(ValidationException::class);
    $stale->callMountedAction()->assertHasActionErrors(['month']);
    app(EmployeeSalaryFunding::class)->pay($settlement, ['clinic_cash_gel' => $source === 'clinic' ? 300 : 0, 'israeli_cash_gel' => $source === 'israeli' ? 300 : 0]);
    expect(EmployeeSalarySettlement::count())->toBe(1)
        ->and(FinanceTransaction::where('employee_salary_settlement_id', $settlement->id)->count())->toBe(1)
        ->and($this->balances->cashBalances($source))->toBe($balance)
        ->and(PartnerFinanceTransaction::where('type', 'salary_cash')->count())->toBe($source === 'israeli' ? 1 : 0);
})->with(['clinic', 'israeli']);

test('a changed salary setting rejects the stale amount without recording history or cash', function () {
    $page = Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('monthlySalary')
        ->fillForm(['payment_source' => 'clinic']);
    $this->tech->update(['monthly_salary_gel' => 301]);
    $page->callMountedAction()->assertHasActionErrors(['actual_paid_gel']);
    expect(EmployeeSalarySettlement::count())->toBe(0)->and(FinanceTransaction::where('type', 'expense')->count())->toBe(0);
});

test('legacy unlinked salary history is flagged for review and cannot be automatically charged', function () {
    $legacy = $this->service->settleFixed($this->tech, '2026-09');
    $before = $legacy->fresh()->getRawOriginal();
    Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])->mountAction('salaryHistory')
        ->assertMountedActionModalSee('2026-09')->assertMountedActionModalSee(__('employees.salary.finance_unlinked_review'));
    expect(fn () => $this->service->payFixed($this->tech, '2026-09', ['payment_source' => 'clinic', 'actual_paid_gel' => 300]))
        ->toThrow(ValidationException::class);
    expect($legacy->fresh()->getRawOriginal())->toBe($before)->and($legacy->fresh()->financeExpense)->toBeNull()
        ->and(FinanceTransaction::where('type', 'expense')->count())->toBe(0);
    foreach ($this->cashBefore as $cashSource => $balance) {
        expect($this->balances->cashBalances($cashSource))->toBe($balance);
    }
});

test('existing undo reverses fixed funding once and preserves payment history', function (string $source) {
    $settlement = $this->service->payFixed($this->tech, '2026-10', ['payment_source' => $source, 'actual_paid_gel' => 300]);
    $expense = $settlement->financeExpense;
    $this->service->undo($settlement);
    $this->service->undo($settlement);
    expect($settlement->fresh()->status)->toBe('undone')->and($settlement->fresh()->actual_paid_gel)->toBe('300.00')
        ->and($expense->fresh()->reversal->amount)->toBe('300.00')
        ->and(FinanceTransaction::whereNotNull('reversal_of_finance_transaction_id')->count())->toBe(1)
        ->and(EmployeeSalarySettlement::count())->toBe(1);
    foreach ($this->cashBefore as $cashSource => $balance) {
        expect($this->balances->cashBalances($cashSource))->toBe($balance);
    }
})->with(['clinic', 'israeli']);

test('Israeli USD funds a fixed salary only after an explicit GEL exchange', function () {
    $this->tech->update(['monthly_salary_gel' => 2500]);
    $payment = ['payment_source' => 'israeli', 'actual_paid_gel' => 2500];
    expect(fn () => $this->service->payFixed($this->tech, '2026-10', $payment))->toThrow(ValidationException::class);
    expect(EmployeeSalarySettlement::count())->toBe(0);
    $this->balances->record(['source' => 'israeli', 'usage_type' => 'exchange_only', 'usd_amount' => 100,
        'exchange_rate' => 5, 'received_gel_amount' => 500, 'transacted_at' => now()]);
    $settlement = $this->service->payFixed($this->tech, '2026-10', $payment);
    expect($this->balances->cashBalances('israeli'))->toBe(['GEL' => 0.0, 'USD' => 0.0])
        ->and(EmployeeSalarySettlement::count())->toBe(1)
        ->and($settlement->actual_paid_gel)->toBe('2500.00')
        ->and(PartnerFinanceTransaction::where('type', 'currency_exchange')->count())->toBe(1)
        ->and(PartnerFinanceTransaction::where('type', 'salary_cash')->count())->toBe(1)
        ->and($this->balances->cashBalances('clinic'))->toBe($this->cashBefore['clinic']);
});
