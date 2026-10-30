<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\Finance;
use App\Filament\Resources\LabTechnicians\Pages\ViewLabTechnician;
use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\CashboxTransaction;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\EmployeeSalarySettlement;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\EmployeeSalaryService;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\CashOutflowReport;
use App\Services\FinanceManager;
use App\Services\FinanceUsdUsageService;
use App\Services\PartnerFinanceSummary;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-10-28 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->tech = Employee::create([
        'first_name' => 'Held', 'last_name' => 'Cash audit',
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id,
        'is_active' => true, 'salary_active' => true, 'salary_type' => 'fixed',
        'monthly_salary_gel' => 300, 'salary_effective_from' => '2026-09-01', 'salary_payout_day' => 1,
    ]);
    $this->manager = app(CashboxManager::class);
    $this->service = app(EmployeeSalaryService::class);
});

function closeAuditClinicDay($test, int $carry = 200): void
{
    app(FinanceManager::class)->create([
        'type' => 'income', 'category' => 'other_income', 'amount' => 1200, 'currency' => 'GEL',
        'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => now(),
    ]);
    $test->manager->close($test->manager->today(), actual: 1200, carry: $carry);
    $test->travelTo('2026-10-29 12:00:00');
}

test('300 GEL fixed salary consumes retained cash once without touching the 200 GEL current drawer', function (string $month) {
    closeAuditClinicDay($this);
    $day = $this->manager->today();
    $cashboxBefore = DB::table('cashbox_transactions')->orderBy('id')->get()->toJson();
    expect($this->manager->summary($day)['expected'])->toBe(200.0)
        ->and($this->manager->availableCashForOpening($day)['GEL'])->toBe(1000.0)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe(1200.0);
    $payment = ['payment_source' => 'clinic', 'actual_paid_gel' => 300];
    $settlement = $this->service->payFixed($this->tech, $month, $payment);
    expect($this->manager->summary($day->fresh())['expected'])->toBe(200.0)
        ->and($this->manager->availableCashForOpening($day)['GEL'])->toBe(700.0)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe(900.0)
        ->and(DB::table('cashbox_transactions')->orderBy('id')->get()->toJson())->toBe($cashboxBefore)
        ->and(EmployeeSalarySettlement::count())->toBe(1)
        ->and($settlement->salary_month)->toBe($month)
        ->and($settlement->settled_at->toDateString())->toBe('2026-10-29')
        ->and($settlement->financeExpense->cash_source)->toBe('withdrawn_cash');
    $expenses = app(AccountingLedger::class)->pnl('2026-10-29', '2026-10-29')->where('metric', 'expense')->get();
    expect($expenses)->toHaveCount(1)->and((float) $expenses->sum('amount'))->toBe(300.0)
        ->and((float) app(CashOutflowReport::class)->totals('2026-10-29', '2026-10-29')->get('GEL')->amount)->toBe(300.0);
    expect(fn () => $this->service->payFixed($this->tech, $month, $payment))->toThrow(ValidationException::class);
    expect($this->manager->availableCashForOpening($day)['GEL'])->toBe(700.0)
        ->and(FinanceTransaction::where('type', 'expense')->count())->toBe(1)
        ->and(CashboxTransaction::where('type', 'cash_withdrawal')->count())->toBe(0);
    Livewire::test(Finance::class)->set('businessSource', 'clinic')->set('dateFrom', '2026-10-29')->set('dateUntil', '2026-10-29')
        ->assertViewHas('figures', fn ($f) => $f['GEL']['cash'] === 900.0 && $f['GEL']['expenses'] === 300.0 && $f['GEL']['profit'] === -300.0);
})->with(['2026-09', '2026-10', '2032-03']);

test('a full current drawer cannot substitute for insufficient previously retained Clinic cash', function () {
    closeAuditClinicDay($this, carry: 1000);
    $day = $this->manager->today();
    expect($this->manager->summary($day)['expected'])->toBe(1000.0)
        ->and($this->manager->availableCashForOpening($day)['GEL'])->toBe(200.0);
    expect(fn () => $this->service->payFixed($this->tech, '2026-10', ['payment_source' => 'clinic', 'actual_paid_gel' => 300]))
        ->toThrow(ValidationException::class);
    expect($this->manager->summary($day->fresh())['expected'])->toBe(1000.0)
        ->and($this->manager->availableCashForOpening($day)['GEL'])->toBe(200.0)
        ->and(EmployeeSalarySettlement::count())->toBe(0)
        ->and(FinanceTransaction::where('type', 'expense')->count())->toBe(0);
});

test('Owner undo restores the retained pool once without adding current drawer cash', function () {
    closeAuditClinicDay($this);
    $day = $this->manager->today();
    $before = DB::table('cashbox_transactions')->orderBy('id')->get()->toJson();
    $settlement = $this->service->payFixed($this->tech, '2026-10', ['payment_source' => 'clinic', 'actual_paid_gel' => 300]);
    Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])
        ->callAction('undoSalary', arguments: ['settlement' => $settlement->id])->assertHasNoActionErrors();
    $this->service->undo($settlement);
    expect($this->manager->summary($day->fresh())['expected'])->toBe(200.0)
        ->and($this->manager->availableCashForOpening($day)['GEL'])->toBe(1000.0)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe(1200.0)
        ->and(DB::table('cashbox_transactions')->orderBy('id')->get()->toJson())->toBe($before)
        ->and($settlement->fresh()->status)->toBe('undone')
        ->and(FinanceTransaction::whereNotNull('reversal_of_finance_transaction_id')->count())->toBe(1);
});

function seedIndependentIsraeliAuditCash(): void
{
    app(FinanceManager::class)->create([
        'type' => 'income', 'category' => 'other_income', 'amount' => 2000, 'currency' => 'GEL',
        'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => now(),
    ]);
    $patient = Patient::create(['first_name' => 'Israeli', 'last_name' => 'Cash audit', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $patient->partnerPayments()->create(['amount' => 5000, 'currency' => 'GEL', 'payment_method' => 'cash', 'paid_at' => now()]);
}

test('Israeli fixed salary changes independent Finance from 5000 to 4700 while Dashboard cash stays 2000', function () {
    seedIndependentIsraeliAuditCash();
    $day = $this->manager->today();
    $balances = app(FinanceUsdUsageService::class);
    $summary = app(PartnerFinanceSummary::class);
    expect($balances->cashBalances('israeli')['GEL'])->toBe(5000.0)
        ->and($this->manager->summary($day)['expected'])->toBe(2000.0)
        ->and($this->manager->availableCashForOpening($day)['GEL'])->toBe(0.0);
    Livewire::test(Dashboard::class)->assertViewHas('cashBalances', fn ($cash) => $cash['GEL'] === 2000.0);
    $drawerRows = DB::table('cashbox_transactions')->orderBy('id')->get()->toJson();
    $drawerDay = $day->fresh()->getRawOriginal();
    $payment = ['payment_source' => 'israeli', 'actual_paid_gel' => 300];
    $settlement = $this->service->payFixed($this->tech, '2026-10', $payment);
    $expense = $settlement->financeExpense;
    $movement = $expense->israeliCashMovement;
    expect($balances->cashBalances('israeli')['GEL'])->toBe(4700.0)
        ->and($summary->currentCashTotals()['GEL'])->toBe(4700.0)
        ->and($summary->expenseTotals('2026-10-28', '2026-10-28')['GEL'])->toBe(300.0)
        ->and($summary->expenseTotals('2026-10-29', '2026-10-29')['GEL'])->toBe(0.0)
        ->and($balances->cashBalances('clinic')['GEL'])->toBe(2000.0)
        ->and($this->manager->summary($day->fresh())['expected'])->toBe(2000.0)
        ->and($this->manager->physicalCashBalances()['GEL'])->toBe(2000.0)
        ->and($this->manager->availableCashForOpening($day)['GEL'])->toBe(0.0)
        ->and(DB::table('cashbox_transactions')->orderBy('id')->get()->toJson())->toBe($drawerRows)
        ->and($day->fresh()->getRawOriginal())->toBe($drawerDay)
        ->and(EmployeeSalarySettlement::count())->toBe(1)
        ->and($settlement->salary_month)->toBe('2026-10')
        ->and($expense->amount)->toBe('300.00')->and((float) $expense->clinic_cash_gel)->toBe(0.0)
        ->and((float) $expense->israeli_cash_gel)->toBe(300.0)->and($expense->cashboxTransaction)->toBeNull()
        ->and($expense->category)->toBe('lab_salary')->and($expense->funding_source)->toBe('israeli')
        ->and($movement->finance_transaction_id)->toBe($expense->id)
        ->and($movement->type)->toBe('salary_cash')->and($movement->amount)->toBe('300.00')
        ->and(PartnerFinanceTransaction::where('type', 'expense')->count())->toBe(0);
    $pnl = app(AccountingLedger::class);
    expect($pnl->pnl('2026-10-28', '2026-10-28')->where('metric', 'expense')->count())->toBe(1)
        ->and((float) $pnl->pnl('2026-10-28', '2026-10-28', businessSource: 'israeli')->where('metric', 'expense')->sum('amount'))->toBe(300.0)
        ->and((float) $pnl->pnl('2026-10-28', '2026-10-28', businessSource: 'clinic')->where('metric', 'expense')->sum('amount'))->toBe(0.0);
    $cashReport = app(CashOutflowReport::class);
    $outflows = $cashReport->movements('2026-10-28', '2026-10-28', 'israeli', 'outflow')->get();
    expect($outflows)->toHaveCount(1)->and((float) $outflows->sole()->amount)->toBe(300.0)
        ->and($outflows->sole()->origin)->toBe('salary_cash')
        ->and($cashReport->entries('2026-10-28', '2026-10-28')->count())->toBe(1)
        ->and((float) $cashReport->totals('2026-10-28', '2026-10-28')->get('GEL')->amount)->toBe(300.0);
    $entry = PartnerFinanceEntry::where('entry_key', 'transaction-'.$movement->id)->sole();
    $partnerPage = Livewire::test(ListPartnerFinance::class)->assertCanSeeTableRecords([$entry])
        ->set('movementType', 'expense')->assertCanSeeTableRecords([$entry])
        ->filterTable('transaction_type', 'expense')->assertCanSeeTableRecords([$entry]);
    $overview = $partnerPage->instance()->overview();
    expect($overview['cash']['GEL'])->toBe(4700.0)->and($overview['expense']['GEL'])->toBe(300.0)
        ->and($overview['expense_funding']['direct_gel'])->toBe(300.0);
    Livewire::test(Dashboard::class)->assertViewHas('cashBalances', fn ($cash) => $cash['GEL'] === 2000.0);
    Livewire::test(Finance::class)->set('businessSource', 'israeli')->set('dateFrom', '2026-10-28')->set('dateUntil', '2026-10-28')
        ->assertViewHas('figures', fn ($f) => $f['GEL']['cash'] === 4700.0 && $f['GEL']['expenses'] === 300.0 && $f['GEL']['profit'] === 4700.0);
    expect(fn () => $this->service->payFixed($this->tech, '2026-10', $payment))->toThrow(ValidationException::class);
    expect(FinanceTransaction::where('type', 'expense')->count())->toBe(1)
        ->and(PartnerFinanceTransaction::where('type', 'salary_cash')->count())->toBe(1)
        ->and($balances->cashBalances('israeli')['GEL'])->toBe(4700.0)
        ->and(CashboxTransaction::where('type', 'cash_withdrawal')->count())->toBe(0);
});

test('Owner undo restores Israeli Finance once without changing Main Clinic cash', function () {
    seedIndependentIsraeliAuditCash();
    $drawerRows = DB::table('cashbox_transactions')->orderBy('id')->get()->toJson();
    $settlement = $this->service->payFixed($this->tech, '2026-10', ['payment_source' => 'israeli', 'actual_paid_gel' => 300]);
    Livewire::test(ViewLabTechnician::class, ['record' => $this->tech->id])
        ->callAction('undoSalary', arguments: ['settlement' => $settlement->id])->assertHasNoActionErrors();
    $this->service->undo($settlement);
    expect(app(PartnerFinanceSummary::class)->currentCashTotals()['GEL'])->toBe(5000.0)
        ->and(app(PartnerFinanceSummary::class)->expenseTotals()['GEL'])->toBe(0.0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli')['GEL'])->toBe(5000.0)
        ->and($this->manager->today()->summary()['expected'])->toBe(2000.0)
        ->and(DB::table('cashbox_transactions')->orderBy('id')->get()->toJson())->toBe($drawerRows)
        ->and($settlement->fresh()->status)->toBe('undone')
        ->and($settlement->fresh()->actual_paid_gel)->toBe('300.00')
        ->and(FinanceTransaction::whereNotNull('reversal_of_finance_transaction_id')->count())->toBe(1)
        ->and(PartnerFinanceTransaction::where('type', 'salary_cash')->where('to_account', 'cash')->count())->toBe(1)
        ->and((float) app(AccountingLedger::class)->pnl(null, null, businessSource: 'israeli')->where('metric', 'expense')->sum('amount'))->toBe(0.0);
});

test('existing Israeli withdrawals remain separate from a subsequent fixed salary', function () {
    seedIndependentIsraeliAuditCash();
    $withdrawal = PartnerFinanceTransaction::create(['source' => 'israeli', 'type' => 'owner_withdrawal', 'from_account' => 'cash',
        'amount' => 1000, 'currency' => 'GEL', 'transacted_at' => now()]);
    $original = $withdrawal->fresh()->getRawOriginal();
    $this->service->payFixed($this->tech, '2026-10', ['payment_source' => 'israeli', 'actual_paid_gel' => 300]);
    expect(app(FinanceUsdUsageService::class)->cashBalances('israeli')['GEL'])->toBe(3700.0)
        ->and($this->manager->today()->summary()['expected'])->toBe(2000.0)
        ->and($withdrawal->fresh()->getRawOriginal())->toBe($original)
        ->and(PartnerFinanceTransaction::where('type', 'owner_withdrawal')->count())->toBe(1)
        ->and(app(PartnerFinanceSummary::class)->expenseTotals()['GEL'])->toBe(300.0);
});
