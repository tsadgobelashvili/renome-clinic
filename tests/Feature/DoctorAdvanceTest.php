<?php

use App\Filament\Resources\EmployeeAdvances\Pages\CreateEmployeeAdvance;
use App\Filament\Resources\EmployeeAdvances\Pages\ListEmployeeAdvances;
use App\Filament\Resources\EmployeeAdvances\Pages\ViewEmployeeAdvance;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceEntry;
use App\Models\FinanceTransaction;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Models\Visit;
use App\Services\ClinicPayrollCycleService;
use App\Services\EmployeeAdvanceService;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\FinanceUsdUsageService;
use App\Services\IsraeliDoctorPayoutHistory;
use App\Services\IsraeliSalaryPayoutService;
use App\Services\SalarySettlementService;
use App\Support\AdvanceRecipients;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-14 12:00:00');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'ლევან', 'last_name' => 'ბერიკაშვილი', 'is_active' => true,
        'compensation_percentage' => 40, 'clinic_salary_payment_method' => 'cash', 'israeli_lab_zircon_rate' => 2600]);
    $this->employee = Employee::forceCreate(['id' => $this->doctor->id, 'first_name' => 'Other', 'last_name' => 'Employee', 'is_active' => true]);
    $this->manager = app(CashboxManager::class);
    $previous = $this->manager->today();
    $previous->update(['opening_balance' => 10000]);
    $this->manager->close($previous, 10000, 0);
    $this->travelTo('2026-10-15 12:00:00');
    $this->day = $this->manager->today();
    $this->manager->addOpeningBalance($this->day, 4000);
    $this->day->refresh();
    $this->service = app(EmployeeAdvanceService::class);
    $this->ledger = app(AccountingLedger::class);
});

function doctorAdvanceData(Doctor $doctor, array $extra = []): array
{
    return array_replace(['employee_id' => 'doctor:'.$doctor->id, 'date' => today()->toDateString(), 'amount' => 500,
        'posting_key' => (string) Str::uuid(), 'source' => 'cashbox', 'is_salary_advance' => true], $extra);
}

function doctorAdvanceClinicWork(Doctor $doctor): Visit
{
    $patient = Patient::create(['first_name' => 'Advance', 'last_name' => 'Patient']);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 1000]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Work', 'quantity' => 1, 'unit_price' => 1000]);
    $visit->payments()->create(['amount' => 1000, 'currency' => 'GEL', 'payment_method' => 'cash', 'payment_date' => today()]);

    return $visit;
}

function doctorAdvanceIsraeliWork(Doctor $doctor): int
{
    $patient = Patient::create(['first_name' => 'Partner', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    foreach (['GEL', 'USD'] as $currency) {
        $patient->partnerPayments()->create(['amount' => 10000, 'currency' => $currency, 'payment_method' => 'cash', 'paid_at' => now()]);
    }
    $case = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'case_date' => today()]);

    return $case->mainWorks()->create(['doctor_id' => $doctor->id, 'material' => 'zircon', 'quantity' => 2])->id;
}

test('one searchable selector contains active Employees and Doctors with explicit identities', function () {
    $inactive = Doctor::create(['first_name' => 'Hidden', 'last_name' => 'ბერიკაშვილი', 'is_active' => false]);
    $options = AdvanceRecipients::options();
    expect($options)->toHaveKey($this->employee->id)->toHaveKey('doctor:'.$this->doctor->id)
        ->not->toHaveKey('doctor:'.$inactive->id);
    foreach (['ლევან', 'ბერიკაშვილი', 'ლევან ბერიკაშვილი', 'ბერიკაშვილი ლევან'] as $term) {
        expect(AdvanceRecipients::options($term))->toHaveKey('doctor:'.$this->doctor->id);
    }
    Livewire::test(CreateEmployeeAdvance::class)->assertFormFieldExists('employee_id', fn ($field) => $field->isSearchable() && $field->getSearchResults('ბერიკაშვილი') === ['doctor:'.$this->doctor->id => 'ლევან ბერიკაშვილი — ექიმი']);
});

test('Doctor cash advances reuse the same funding balances receivable and return flow', function (string $source) {
    $cash = $this->day->summary()['expected'];
    $pool = $this->manager->availableCashForOpening($this->day)['GEL'];
    $physical = app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'];
    $pnl = $this->ledger->pnlTotals(null, null)->toArray();
    $data = doctorAdvanceData($this->doctor, ['source' => $source]);
    $advance = $this->service->issue($data, $this->owner);
    expect($advance->employee_id)->toBeNull()->and($advance->doctor_id)->toBe($this->doctor->id)
        ->and($advance->recipient_name)->toBe('ლევან ბერიკაშვილი — ექიმი')
        ->and($this->service->issue($data, $this->owner)->id)->toBe($advance->id)
        ->and($this->day->summary()['expected'])->toBe($cash - ($source === 'cashbox' ? 500 : 0))
        ->and($this->manager->availableCashForOpening($this->day)['GEL'])->toBe($pool - ($source === 'accumulated_cash' ? 500 : 0))
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe($physical - 500)
        ->and($this->ledger->pnlTotals(null, null)->toArray())->toEqual($pnl)
        ->and(FinanceTransaction::count())->toBe(0);
    Livewire::test(ViewEmployeeAdvance::class, ['record' => $advance->id])->assertSee('ლევან ბერიკაშვილი — ექიმი');
    Livewire::test(ListEmployeeAdvances::class)->searchTable('ბერიკაშვილი')->assertCanSeeTableRecords([$advance]);
    $this->service->returnRemaining($advance->id, today()->toDateString(), (string) Str::uuid(), '500.00', $this->owner);
    expect($advance->fresh()->status)->toBe('settled')
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe($physical)
        ->and($this->manager->availableCashForOpening($this->day)['GEL'])->toBe($pool);
})->with(['cashbox', 'accumulated_cash']);

test('Doctor bank advance links the existing debit once and never synthesizes an expense or cash movement', function () {
    $category = BankCategory::create(['name' => 'Advance bank', 'accounting_treatment' => 'expense']);
    $bank = BankTransaction::create(['bank' => 'BOG', 'account_identifier' => 'ADV', 'currency' => 'GEL', 'transaction_date' => today(),
        'direction' => 'outflow', 'amount' => 500, 'operation_type' => 'PAY', 'bank_category_id' => $category->id,
        'fingerprint' => hash('sha256', (string) Str::uuid()), 'deduplication_key' => hash('sha256', (string) Str::uuid())]);
    $cash = $this->manager->physicalCashBalances();
    $data = doctorAdvanceData($this->doctor, ['source' => 'bank', 'bank_transaction_id' => $bank->id]);
    $advance = $this->service->issue($data, $this->owner);
    expect($advance->bank_transaction_id)->toBe($bank->id)->and($this->manager->physicalCashBalances())->toEqual($cash)
        ->and(CashboxTransaction::where('employee_advance_id', $advance->id)->count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(1)
        ->and($this->ledger->pnl(null, null)->where('entry_key', 'bank:'.$bank->id)->count())->toBe(0);
    expect($this->service->issue($data, $this->owner)->id)->toBe($advance->id);
    doctorAdvanceClinicWork($this->doctor);
    $salary = app(SalarySettlementService::class)->settle($this->doctor->id, '2026-10-01', today()->toDateString(), 40, $this->owner->id, patientGroup: 'clinic')[0];
    expect((float) $salary->salary_advance_applied)->toBe(400.0)->and((float) $salary->amount_payable)->toBe(0.0)
        ->and((float) $advance->fresh()->remaining_amount)->toBe(100.0)
        ->and(BankTransaction::count())->toBe(1)->and(FinanceTransaction::count())->toBe(0);
});

test('existing create form issues a Doctor advance and history survives Doctor deactivation', function () {
    Livewire::test(CreateEmployeeAdvance::class)->fillForm([
        'employee_id' => 'doctor:'.$this->doctor->id, 'source' => 'cashbox', 'amount' => 500,
        'date' => today()->toDateString(), 'is_salary_advance' => true, 'note' => 'Doctor advance',
    ])->call('create')->assertHasNoFormErrors();
    $advance = EmployeeAdvance::sole();
    expect($advance->doctor_id)->toBe($this->doctor->id)->and($advance->employee_id)->toBeNull();
    $this->doctor->update(['is_active' => false]);
    Livewire::test(ListEmployeeAdvances::class)->searchTable('ლევან ბერიკაშვილი')->assertCanSeeTableRecords([$advance]);
    Livewire::test(ViewEmployeeAdvance::class, ['record' => $advance->id])->assertSee('ლევან ბერიკაშვილი — ექიმი');
});

test('Doctor purchase advances reuse confirmations and are not deducted automatically from salary', function () {
    $advance = $this->service->issue(doctorAdvanceData($this->doctor, ['is_salary_advance' => false]), $this->owner);
    $dimensions = app(ExpenseDimensions::class);
    $direction = $dimensions->id('direction', 'general');
    $before = app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'];
    $this->service->manualExpense($advance->id, ['posting_key' => (string) Str::uuid(), 'expense_date' => today()->toDateString(),
        'amount' => 100, 'description' => 'Doctor purchase', 'expense_direction_id' => $direction,
        'expense_type_id' => $dimensions->id('type', 'other', $direction)], $this->owner);
    expect($this->service->doctorSalaryAllocation($this->doctor->id, 'GEL', 1000))->toBe([])
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe($before)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe(100.0)
        ->and($this->ledger->pnl(null, null)->sole()->counterparty)->toBe('ლევან ბერიკაშვილი — ექიმი');
});

test('GEL advances are never silently applied to USD salary bases', function () {
    $advance = $this->service->issue(doctorAdvanceData($this->doctor), $this->owner);
    expect(fn () => $this->service->doctorSalaryAllocation($this->doctor->id, 'USD', 1000))->toThrow(ValidationException::class);
    expect((float) $advance->fresh()->remaining_amount)->toBe(500.0)->and(EmployeeAdvanceEntry::count())->toBe(0);
});

test('failed Israeli payment rolls back advance recovery and salary creation together', function () {
    $work = doctorAdvanceIsraeliWork($this->doctor);
    $advance = $this->service->issue(doctorAdvanceData($this->doctor), $this->owner);
    expect(fn () => app(IsraeliSalaryPayoutService::class)->finalizeAndPay($this->doctor->id, '2026-10-01', today()->toDateString(), [$work],
        [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 99999]], (string) Str::uuid(), $this->owner))->toThrow(ValidationException::class);
    expect(SalarySettlement::count())->toBe(0)->and(EmployeeAdvanceEntry::where('kind', 'salary')->count())->toBe(0)
        ->and((float) $advance->fresh()->remaining_amount)->toBe(500.0);
});

test('inactive Doctors invalid recipient combinations insufficient funds and unauthorized users are blocked atomically', function () {
    $this->doctor->update(['is_active' => false]);
    expect(fn () => $this->service->issue(doctorAdvanceData($this->doctor), $this->owner))->toThrow(ValidationException::class);
    $this->doctor->update(['is_active' => true]);
    expect(fn () => $this->service->issue(doctorAdvanceData($this->doctor, ['employee_id' => $this->employee->id, 'doctor_id' => $this->doctor->id]), $this->owner))->toThrow(ValidationException::class);
    expect(fn () => $this->service->issue(doctorAdvanceData($this->doctor, ['amount' => 99999]), $this->owner))->toThrow(ValidationException::class);
    foreach ([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN] as $role) {
        $actor = User::factory()->create(['role' => $role]);
        expect(fn () => $this->service->issue(doctorAdvanceData($this->doctor), $actor))->toThrow(HttpException::class);
        $this->actingAs($actor);
        Livewire::test(CreateEmployeeAdvance::class)->assertForbidden();
    }
    expect(EmployeeAdvance::count())->toBe(0)->and(EmployeeAdvanceEntry::count())->toBe(0);
});

test('same numeric Employee and Doctor IDs never share advances or salary allocations', function () {
    $employee = $this->service->issue(doctorAdvanceData($this->doctor, ['employee_id' => $this->employee->id, 'amount' => 100]), $this->owner);
    $before = $employee->fresh()->getAttributes();
    $doctor = $this->service->issue(doctorAdvanceData($this->doctor, ['amount' => 200]), $this->owner);
    expect($employee->doctor_id)->toBeNull()->and($doctor->employee_id)->toBeNull()
        ->and($this->service->salaryAllocation($this->employee->id, 'GEL', 1000))->toEqual([$employee->id => 100.0])
        ->and($this->service->doctorSalaryAllocation($this->doctor->id, 'GEL', 1000))->toEqual([$doctor->id => 200.0])
        ->and($employee->fresh()->getAttributes())->toEqual($before);
});

test('Clinic salary applies Doctor salary advances once and posts only the unpaid remainder', function (string $method) {
    $this->doctor->update(['clinic_salary_payment_method' => $method]);
    doctorAdvanceClinicWork($this->doctor);
    $advance = $this->service->issue(doctorAdvanceData($this->doctor, ['amount' => 150]), $this->owner);
    $before = app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'];
    $record = app(SalarySettlementService::class)->settle($this->doctor->id, '2026-10-01', today()->toDateString(), 40, $this->owner->id, patientGroup: 'clinic')[0];
    expect((float) $record->salary_total)->toBe(400.0)->and((float) $record->salary_advance_applied)->toBe(150.0)
        ->and((float) $record->amount_payable)->toBe(250.0)->and((float) $advance->fresh()->remaining_amount)->toBe(0.0)
        ->and(EmployeeAdvanceEntry::where('salary_settlement_id', $record->id)->sole()->amount)->toBe('150.00');
    if ($method === 'cash') {
        expect(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe($before - 250)
            ->and((float) FinanceTransaction::where('salary_settlement_id', $record->id)->sole()->amount)->toBe(250.0)
            ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe(400.0);
    } else {
        expect(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe($before)
            ->and(FinanceTransaction::count())->toBe(0);
    }
    expect(fn () => app(SalarySettlementService::class)->settle($this->doctor->id, '2026-10-01', today()->toDateString(), 40, $this->owner->id, patientGroup: 'clinic'))->toThrow(ValidationException::class);
    expect(EmployeeAdvanceEntry::where('salary_settlement_id', $record->id)->count())->toBe(1);
})->with(['cash', 'bank_transfer']);

test('Clinic batch review shows advance deduction and settles the same receivable', function () {
    doctorAdvanceClinicWork($this->doctor);
    $advance = $this->service->issue(doctorAdvanceData($this->doctor, ['amount' => 150]), $this->owner);
    $cycle = app(ClinicPayrollCycleService::class);
    $review = $cycle->preview();
    $row = collect($review['doctors'])->firstWhere('id', $this->doctor->id);
    expect((float) $row['salary_advance_applied']['GEL'])->toBe(150.0)->and((float) $row['amount_payable']['GEL'])->toBe(250.0)
        ->and((float) $review['doctor_totals']['GEL'])->toBe(250.0);
    $cycle->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    expect($advance->fresh()->status)->toBe('settled')->and((float) SalarySettlement::sole()->salary_advance_applied)->toBe(150.0);
});

test('Israeli allocations recover the Doctor advance and later payments cannot pay it again', function () {
    $work = doctorAdvanceIsraeliWork($this->doctor);
    $advance = $this->service->issue(doctorAdvanceData($this->doctor), $this->owner);
    $service = app(IsraeliSalaryPayoutService::class);
    $rows = [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 2000]];
    $key = (string) Str::uuid();
    $first = $service->finalizeAndPay($this->doctor->id, '2026-10-01', today()->toDateString(), [$work], $rows, $key, $this->owner);
    expect($first->settlement->salary_advance_applied)->toBe('500.00')->and($service->remaining($first->settlement))->toBe(2700.0)
        ->and($advance->fresh()->status)->toBe('settled');
    $history = app(IsraeliDoctorPayoutHistory::class)->find($this->doctor->id, 'payout:'.$first->id);
    expect($history['remaining'])->toBe(2700.0)->and($history['covered'])->toBe(2500.0)
        ->and((float) collect($history['items'])->sum('advance_applied'))->toBe(500.0);
    expect($service->finalizeAndPay($this->doctor->id, '2026-10-01', today()->toDateString(), [$work], $rows, $key, $this->owner)->id)->toBe($first->id);
    $service->payRemaining($first->salary_settlement_id, [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 2700]], (string) Str::uuid(), $this->owner);
    expect($service->remaining($first->settlement))->toBe(0.0)->and(EmployeeAdvanceEntry::where('kind', 'salary')->count())->toBe(1)
        ->and((float) $this->ledger->pnlTotals(null, null)->where('currency', 'GEL')->sole()->expenses)->toBe(5200.0)
        ->and($first->settlement->unpaidAllocations()->whereKey($first->salary_settlement_id)->exists())->toBeFalse();
});

test('fully advanced Israeli salary requires no extra payment and preserves item composition', function () {
    $work = doctorAdvanceIsraeliWork($this->doctor);
    $this->service->issue(doctorAdvanceData($this->doctor, ['amount' => 5200, 'source' => 'accumulated_cash']), $this->owner);
    $payout = app(IsraeliSalaryPayoutService::class)->finalizeAndPay($this->doctor->id, '2026-10-01', today()->toDateString(), [$work], [], (string) Str::uuid(), $this->owner);
    expect((float) $payout->total_gel)->toBe(0.0)->and((float) $payout->settlement->salary_advance_applied)->toBe(5200.0)
        ->and(app(IsraeliSalaryPayoutService::class)->remaining($payout->settlement))->toBe(0.0)
        ->and((float) $payout->composition_snapshot['remaining_after'])->toBe(0.0);
    $history = app(IsraeliDoctorPayoutHistory::class)->find($this->doctor->id, 'payout:'.$payout->id);
    expect($history['remaining'])->toBe(0.0)->and((float) collect($history['items'])->sum('advance_applied'))->toBe(5200.0);
});

test('legacy Israeli GEL and converted USD payment snapshots also deduct the same advance', function (string $currency) {
    $work = doctorAdvanceIsraeliWork($this->doctor);
    $this->service->issue(doctorAdvanceData($this->doctor), $this->owner);
    $record = app(SalarySettlementService::class)->settle($this->doctor->id, '2026-10-01', today()->toDateString(), 40, $this->owner->id,
        patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG, paymentCurrency: $currency, exchangeRate: 2,
        selectedLabWorkIds: [$work], israeliLabOnly: true)[0];
    expect($record->salary_advance_applied)->toBe('500.00')->and((float) $record->payment_amount)->toBe($currency === 'GEL' ? 4700.0 : 2350.0)
        ->and(EmployeeAdvanceEntry::where('salary_settlement_id', $record->id)->count())->toBe(1);
    if ($currency === 'GEL') {
        $history = app(IsraeliDoctorPayoutHistory::class)->find($this->doctor->id, 'settlement:'.$record->id);
        expect($history['paid_basis'])->toBe(4700.0)->and($history['remaining'])->toBe(0.0)
            ->and((float) collect($history['items'])->sum('advance_applied'))->toBe(500.0);
    }
    expect(fn () => app(SalarySettlementService::class)->undo($record->id, $this->doctor->id))->toThrow(ValidationException::class);
})->with(['GEL', 'USD']);

test('recipient migration preserves historical employee advances and Doctor salary snapshots', function () {
    doctorAdvanceClinicWork($this->doctor);
    $salary = app(SalarySettlementService::class)->settle($this->doctor->id, '2026-10-01', today()->toDateString(), 40, $this->owner->id, patientGroup: 'clinic')[0];
    $legacy = $this->service->issue(doctorAdvanceData($this->doctor, ['employee_id' => $this->employee->id, 'amount' => 100]), $this->owner);
    $migration = require database_path('migrations/2026_10_15_120000_add_doctor_advance_recipients.php');
    // RefreshDatabase holds a test transaction; SQLite cannot toggle foreign_keys
    // there as Laravel's normal, non-transactional SQLite migrator does.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $migration->down();
    $advanceBefore = (array) DB::table('employee_advances')->where('id', $legacy->id)->first();
    $salaryBefore = (array) DB::table('salary_settlements')->where('id', $salary->id)->first();
    $movements = DB::table('cashbox_transactions')->orderBy('id')->get()->toArray();
    $migration->up();
    $advanceAfter = (array) DB::table('employee_advances')->where('id', $legacy->id)->first();
    $salaryAfter = (array) DB::table('salary_settlements')->where('id', $salary->id)->first();
    expect($advanceAfter['doctor_id'])->toBeNull()->and($salaryAfter['salary_advance_applied'])->toEqual(0);
    unset($advanceAfter['doctor_id'], $salaryAfter['salary_advance_applied']);
    expect($advanceAfter)->toEqual($advanceBefore)->and($salaryAfter)->toEqual($salaryBefore)
        ->and(DB::table('cashbox_transactions')->orderBy('id')->get()->toArray())->toEqual($movements)
        ->and(DB::select('PRAGMA foreign_key_check'))->toBe([]);
});
