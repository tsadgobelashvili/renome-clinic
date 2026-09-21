<?php

use App\Filament\Resources\EmployeeAdvances\EmployeeAdvanceResource;
use App\Filament\Resources\EmployeeAdvances\Pages\CreateEmployeeAdvance;
use App\Filament\Resources\EmployeeAdvances\Pages\ListEmployeeAdvances;
use App\Filament\Resources\EmployeeAdvances\Pages\ViewEmployeeAdvance;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\CashboxTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceEntry;
use App\Models\EmployeePosition;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\Bank\BankReport;
use App\Services\EmployeeAdvanceService;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\CashOutflowReport;
use App\Services\FinanceUsdUsageService;
use App\Services\PurchaseAnalysis;
use App\Services\PurchaseCashPayment;
use App\Services\PurchaseCatalog;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->employee = Employee::create(['first_name' => 'Giorgi', 'last_name' => 'Buyer', 'is_active' => true,
        'position_id' => EmployeePosition::where('name', 'Administrator')->sole()->id]);
    FinanceOpeningBalance::create(['source' => 'cash', 'account_identifier' => 'cash', 'currency' => 'GEL', 'effective_date' => today()->subDay(), 'amount' => 5000]);
    $this->service = app(EmployeeAdvanceService::class);
    $this->ledger = app(AccountingLedger::class);
});

function advanceData(Employee $employee, array $extra = []): array
{
    return [...['employee_id' => $employee->id, 'amount' => 1000, 'source' => 'cashbox', 'date' => today()->toDateString(), 'posting_key' => (string) Str::uuid()], ...$extra];
}

function advancePurchase(array $groups): Purchase
{
    $supplier = Supplier::firstOrCreate(['name' => 'Advance supplier']);
    $purchase = Purchase::create(['supplier_id' => $supplier->id, 'source' => 'rs', 'purchase_date' => today(), 'document_number' => Str::random(8)]);
    foreach ($groups as $code => $amount) {
        $product = app(PurchaseCatalog::class)->resolve($supplier->id, Str::random(12));
        if ($code !== 'unknown') {
            app(PurchaseCatalog::class)->assignDirection($product, app(ExpenseDimensions::class)->id('direction', $code));
        }
        $purchase->items()->create(['purchase_product_id' => $product->id, 'quantity' => 1, 'unit_price' => $amount]);
    }

    return $purchase->fresh();
}

function advanceBank(): BankTransaction
{
    return BankTransaction::create(['transaction_date' => today(), 'direction' => 'outflow', 'amount' => 1000, 'currency' => 'GEL',
        'operation_type' => 'PMD', 'source' => 'api', 'bank_category_id' => BankCategory::where('code', 'supplier_materials')->value('id'),
        'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64)]);
}

function advanceManualData(int $amount = 150): array
{
    $registry = app(ExpenseDimensions::class);
    $direction = $registry->id('direction', 'surgery');

    return ['posting_key' => (string) Str::uuid(), 'expense_date' => today()->toDateString(), 'amount' => $amount,
        'expense_direction_id' => $direction, 'expense_type_id' => $registry->id('type', 'materials', $direction), 'description' => 'Non-RS materials'];
}

test('issuing cash advance posts one non expense movement on its issue date and retries safely', function () {
    $data = advanceData($this->employee);
    $advance = $this->service->issue($data, auth()->user());
    expect($this->service->issue($data, auth()->user())->id)->toBe($advance->id)
        ->and(CashboxTransaction::count())->toBe(1)->and(FinanceTransaction::count())->toBe(0)
        ->and(CashboxTransaction::sole()->type)->toBe('cash_withdrawal')
        ->and(CashboxTransaction::sole()->transaction_date->toDateString())->toBe($data['date'])
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(4000.0)
        ->and($this->ledger->pnl(null, null)->count())->toBe(0)
        ->and((float) $this->ledger->movements(null, null)->sum('amount'))->toBe(1000.0)
        ->and($advance->status)->toBe('open');
});

test('previous days cash uses retained cash without changing todays drawer and returns to same pool', function () {
    $manager = app(CashboxManager::class);
    $yesterday = $manager->dayFor(today()->subDay()->toDateString());
    $manager->close($yesterday, 5000, 0);
    $today = $manager->today();
    $before = $manager->summary($today)['expected'];
    $advance = $this->service->issue(advanceData($this->employee, ['source' => 'accumulated_cash']), auth()->user());
    expect($manager->summary($today)['expected'])->toBe($before)
        ->and($today->transactions()->count())->toBe(0)
        ->and($manager->availableCashForOpening($today)['GEL'])->toBe(4000.0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(4000.0)
        ->and($this->ledger->pnl(null, null)->count())->toBe(0)
        ->and((float) app(CashOutflowReport::class)->entries(null, null)->where('origin', 'employee_advance')->sum('amount'))->toBe(1000.0);
    $this->service->returnRemaining($advance->id, today()->toDateString(), (string) Str::uuid(), '1000', auth()->user());
    expect($manager->availableCashForOpening($today)['GEL'])->toBe(5000.0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(5000.0)
        ->and(app(FinanceUsdUsageService::class)->balances('clinic')['GEL'])->toBe(0.0)
        ->and($manager->summary($today)['expected'])->toBe($before)
        ->and(PartnerFinanceTransaction::where('employee_advance_id', $advance->id)->count())->toBe(2);
});

test('bank advance consumes existing debit without creating expense or duplicate movement', function () {
    $bank = advanceBank();
    $advance = $this->service->issue(advanceData($this->employee, ['source' => 'bank', 'bank_transaction_id' => $bank->id]), auth()->user());
    expect(BankTransaction::count())->toBe(1)->and(CashboxTransaction::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0)
        ->and($this->ledger->pnl(null, null)->count())->toBe(0)
        ->and((float) $this->ledger->movements(null, null)->sum('amount'))->toBe(1000.0);
    $this->service->manualExpense($advance->id, advanceManualData(), auth()->user());
    expect((float) $this->ledger->pnlTotals(null, null, 'bank')->sole()->expenses)->toBe(150.0)
        ->and($this->ledger->pnl(null, null, 'cash')->count())->toBe(0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(5000.0);
    expect(fn () => app(BankPurchaseMatching::class)->confirm($bank->id, [['purchase_id' => advancePurchase(['surgery' => 1000])->id, 'amount' => 1000]], auth()->user()))->toThrow(ValidationException::class);
});

test('RS plus manual expense settles partially then return closes without a second deduction or revenue', function () {
    $advance = $this->service->issue(advanceData($this->employee), auth()->user());
    $purchase = advancePurchase(['surgery' => 500, 'orthopedics' => 300]);
    $rs = $this->service->settlePurchase($advance->id, $purchase->id, today()->toDateString(), (string) Str::uuid(), auth()->user());
    expect($this->service->settlePurchase($advance->id, $purchase->id, today()->toDateString(), (string) Str::uuid(), auth()->user())->id)->toBe($rs->id);
    $manualData = advanceManualData();
    $manual = $this->service->manualExpense($advance->id, $manualData, auth()->user());
    expect($this->service->manualExpense($advance->id, $manualData, auth()->user())->id)->toBe($manual->id);
    $advance = $advance->fresh();
    expect((float) $advance->confirmed_expense_amount)->toBe(950.0)->and((float) $advance->remaining_amount)->toBe(50.0)
        ->and($advance->status)->toBe('partial')->and(CashboxTransaction::count())->toBe(1)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe(950.0)
        ->and((float) $this->ledger->dimensionEntries(null, null)->sum('amount'))->toBe(950.0);
    $groups = $this->ledger->dimensionGroups($this->ledger->dimensionEntries(null, null))->keyBy('dimension_code');
    expect((float) $groups['surgery']->amount)->toBe(650.0)->and((float) $groups['orthopedics']->amount)->toBe(300.0);
    $key = (string) Str::uuid();
    $this->service->returnRemaining($advance->id, today()->toDateString(), $key, '50', auth()->user());
    $this->service->returnRemaining($advance->id, today()->toDateString(), $key, '50', auth()->user());
    expect($advance->fresh()->status)->toBe('settled')->and(CashboxTransaction::count())->toBe(2)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(4050.0)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe(950.0)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->revenue)->toBe(0.0);
});

test('full settlement and overspending retain exact purchase totals and never post an extra payment', function (int $amount, string $status, int $owed) {
    $advance = $this->service->issue(advanceData($this->employee), auth()->user());
    $purchase = advancePurchase(['surgery' => $amount]);
    $this->service->settlePurchase($advance->id, $purchase->id, today()->toDateString(), (string) Str::uuid(), auth()->user());
    expect($advance->fresh()->status)->toBe($status)->and((float) $advance->fresh()->overspent_amount)->toBe((float) $owed)
        ->and((float) $advance->fresh()->remaining_amount)->toBe(0.0)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe((float) $amount)
        ->and(CashboxTransaction::count())->toBe(1)->and(FinanceTransaction::count())->toBe(0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(4000.0);
})->with([[1000, 'settled', 0], [1100, 'overspent', 100]]);

test('uncategorized RS portion updates analytically when mapped later without posting more cash', function () {
    $advance = $this->service->issue(advanceData($this->employee), auth()->user());
    $purchase = advancePurchase(['surgery' => 600, 'unknown' => 400]);
    $this->service->settlePurchase($advance->id, $purchase->id, today()->toDateString(), (string) Str::uuid(), auth()->user());
    expect((float) $this->ledger->dimensionEntries(null, null)->whereNull('expense_direction_id')->sum('amount'))->toBe(400.0);
    app(PurchaseCatalog::class)->assignDirection($purchase->items()->latest('id')->first()->purchaseProduct, app(ExpenseDimensions::class)->id('direction', 'therapy'));
    expect((float) $this->ledger->dimensionEntries(null, null)->whereNull('expense_direction_id')->sum('amount'))->toBe(0.0)
        ->and((float) $this->ledger->dimensionEntries(null, null, grouping: 'type')->sum('amount'))->toBe(1000.0)
        ->and(CashboxTransaction::count())->toBe(1);
});

test('advance cash and bank settlement methods are mutually exclusive and documents cannot change silently', function () {
    $advance = $this->service->issue(advanceData($this->employee), auth()->user());
    $purchase = advancePurchase(['surgery' => 900]);
    $entry = $this->service->settlePurchase($advance->id, $purchase->id, today()->toDateString(), (string) Str::uuid(), auth()->user());
    foreach ([
        fn () => app(PurchaseCashPayment::class)->post($purchase->id, auth()->user()),
        fn () => app(BankPurchaseMatching::class)->confirm(advanceBank()->id, [['purchase_id' => $purchase->id, 'amount' => 900]], auth()->user()),
        fn () => $purchase->items()->first()->update(['line_total' => 100]),
        fn () => $purchase->delete(), fn () => $entry->delete(), fn () => $entry->update(['amount' => 1]),
        fn () => $advance->update(['amount' => 1]), fn () => CashboxTransaction::sole()->delete(),
    ] as $change) {
        expect($change)->toThrow(ValidationException::class);
    }
    $other = $this->service->issue(advanceData($this->employee, ['source' => 'other']), auth()->user());
    expect(fn () => $this->service->settlePurchase($other->id, $purchase->id, today()->toDateString(), (string) Str::uuid(), auth()->user()))->toThrow(ValidationException::class);
    $cashPaid = advancePurchase(['surgery' => 100]);
    app(PurchaseCashPayment::class)->post($cashPaid->id, auth()->user());
    expect(fn () => $this->service->settlePurchase($other->id, $cashPaid->id, today()->toDateString(), (string) Str::uuid(), auth()->user()))->toThrow(ValidationException::class);
    $bankPaid = advancePurchase(['surgery' => 100]);
    app(BankPurchaseMatching::class)->confirm(advanceBank()->id, [['purchase_id' => $bankPaid->id, 'amount' => 100]], auth()->user());
    expect(fn () => $this->service->settlePurchase($other->id, $bankPaid->id, today()->toDateString(), (string) Str::uuid(), auth()->user()))->toThrow(ValidationException::class);
});

test('owner creates advance and posts manual confirmation from the compact detail page', function () {
    Livewire::test(CreateEmployeeAdvance::class)->fillForm([
        'employee_id' => $this->employee->id, 'amount' => 1000, 'date' => today()->toDateString(), 'source' => 'cashbox',
    ])->call('create')->assertHasNoFormErrors();
    $advance = EmployeeAdvance::sole();
    Livewire::test(ListEmployeeAdvances::class)->assertCanSeeTableRecords([$advance]);
    Livewire::test(ViewEmployeeAdvance::class, ['record' => $advance->id])->callAction('manualExpense', data: advanceManualData())
        ->assertHasNoActionErrors()->assertSee('Non-RS materials')->assertSee('850.00');
    Livewire::test(EditPurchase::class, ['record' => advancePurchase(['surgery' => 800])->id])
        ->callAction('linkAdvance', data: ['advance_id' => $advance->id, 'expense_date' => today()->toDateString()])->assertHasNoActionErrors();
    Livewire::test(ViewEmployeeAdvance::class, ['record' => $advance->id])->callAction('returnRemaining')->assertHasNoActionErrors()->assertSee('დახურული');
    expect((float) $advance->fresh()->confirmed_expense_amount)->toBe(950.0)->and(EmployeeAdvanceEntry::count())->toBe(3);
});

test('non owners and inactive owners are denied both page and posting service', function (string $role, bool $active) {
    $user = User::factory()->create(['role' => $role === 'unknown' ? 'lab_technician' : $role, 'is_active' => $active]);
    if ($role === 'unknown') {
        DB::table('users')->where('id', $user->id)->update(['role' => 'unknown']);
        $user->refresh();
    }
    $this->actingAs($user);
    expect(EmployeeAdvanceResource::canViewAny())->toBeFalse();
    $this->get(EmployeeAdvanceResource::getUrl())->assertForbidden();
    expect(fn () => $this->service->issue(advanceData($this->employee), $user))->toThrow(HttpException::class);
    expect(EmployeeAdvance::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
})->with([['administrator', true], ['lab_technician', true], ['unknown', true], ['owner', false]]);

test('invalid dates insufficient funds stale return confirmation and invalid classification post nothing', function () {
    expect(fn () => $this->service->issue(advanceData($this->employee, ['amount' => 6000]), auth()->user()))->toThrow(ValidationException::class);
    expect(EmployeeAdvance::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
    $advance = $this->service->issue(advanceData($this->employee), auth()->user());
    expect(fn () => $this->service->manualExpense($advance->id, [...advanceManualData(), 'expense_date' => today()->subDay()->toDateString()], auth()->user()))->toThrow(ValidationException::class);
    expect(fn () => $this->service->manualExpense($advance->id, [...advanceManualData(), 'expense_type_id' => null], auth()->user()))->toThrow(ValidationException::class);
    expect(fn () => $this->service->returnRemaining($advance->id, today()->toDateString(), (string) Str::uuid(), '10', auth()->user()))->toThrow(ValidationException::class);
    expect(EmployeeAdvanceEntry::count())->toBe(0)->and(CashboxTransaction::count())->toBe(1);
});

test('recognition date is separate from issue date and all funding sources preserve report filters', function () {
    $issued = today()->toDateString();
    $advance = $this->service->issue(advanceData($this->employee), auth()->user());
    $this->travel(2)->days();
    $this->service->manualExpense($advance->id, advanceManualData(), auth()->user());
    expect((float) $this->ledger->pnl($issued, $issued)->sum('amount'))->toBe(0.0)
        ->and((float) $this->ledger->pnl(today()->toDateString(), today()->toDateString(), 'cash', 'clinic')->sum('amount'))->toBe(150.0)
        ->and((float) $this->ledger->pnl(null, null, 'all', 'israeli')->sum('amount'))->toBe(0.0)
        ->and((float) $this->ledger->dimensionEntries(null, null, currency: 'USD')->sum('amount'))->toBe(0.0)
        ->and(CashboxTransaction::sole()->transaction_date->toDateString())->toBe($issued);
});

test('other source has auditable movement but no cash or bank posting', function () {
    $advance = $this->service->issue(advanceData($this->employee, ['source' => 'other']), auth()->user());
    $this->service->manualExpense($advance->id, advanceManualData(), auth()->user());
    $this->service->returnRemaining($advance->id, today()->toDateString(), (string) Str::uuid(), '850', auth()->user());
    expect(CashboxTransaction::count())->toBe(0)->and(PartnerFinanceTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(0)
        ->and((float) $this->ledger->pnl(null, null)->sum('amount'))->toBe(150.0)
        ->and($this->ledger->pnl(null, null, 'cash')->count())->toBe(0)
        ->and((float) $this->ledger->movements(null, null)->where('metric', 'outflow')->sum('amount'))->toBe(1000.0)
        ->and((float) $this->ledger->movements(null, null)->where('metric', 'inflow')->sum('amount'))->toBe(850.0)
        ->and($advance->fresh()->status)->toBe('settled');
});

test('bank totals exclude advance debit while retaining raw movements and RS bank allocations', function () {
    $bank = advanceBank();
    $advance = $this->service->issue(advanceData($this->employee, ['source' => 'bank', 'bank_transaction_id' => $bank->id]), auth()->user());
    $purchase = advancePurchase(['surgery' => 600, 'unknown' => 400]);
    $this->service->settlePurchase($advance->id, $purchase->id, today()->toDateString(), (string) Str::uuid(), auth()->user());
    $totals = app(BankReport::class)->totals([])->sole();
    expect((float) $totals->outflow)->toBe(1000.0)->and((float) $totals->expenses)->toBe(0.0)
        ->and((float) $this->ledger->dimensionEntries(null, null, 'bank')->sum('amount'))->toBe(1000.0)
        ->and((float) $this->ledger->dimensionEntries(null, null, 'bank')->whereNull('expense_direction_id')->sum('amount'))->toBe(400.0);
    expect(app(PurchaseAnalysis::class)->lines(['payment' => 'unlinked'])->count())->toBe(0)
        ->and(app(PurchaseAnalysis::class)->lines(['payment' => 'advance'])->count())->toBe(2);
    Livewire::test(ViewEmployeeAdvance::class, ['record' => $advance->id])->assertSee('გაუნაწილებელი RS: 400.00');
});

test('held cash already issued as an advance cannot also fund a drawer transfer', function () {
    $manager = app(CashboxManager::class);
    $source = $manager->dayFor(today()->subDay()->toDateString());
    $manager->close($source, 5000, 0);
    $destination = $manager->today();
    $this->service->issue(advanceData($this->employee, ['source' => 'accumulated_cash', 'amount' => 4000]), auth()->user());
    expect(fn () => $manager->transferCash($source, $destination, 2000, 'GEL', null, (string) Str::uuid()))->toThrow(ValidationException::class);
    expect(fn () => $this->service->issue(advanceData($this->employee, ['source' => 'accumulated_cash', 'amount' => 2000]), auth()->user()))->toThrow(ValidationException::class);
    expect(EmployeeAdvance::count())->toBe(1)->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(1000.0);
});

test('list totals are eager aggregated and do not query per advance', function () {
    foreach (range(1, 5) as $number) {
        $this->service->issue(advanceData($this->employee, ['source' => 'other']), auth()->user());
    }
    DB::enableQueryLog();
    $records = EmployeeAdvanceResource::getEloquentQuery()->get();
    $count = count(DB::getQueryLog());
    foreach ($records as $advance) {
        expect($advance->status)->toBe('open');
        $advance->confirmed_expense_amount;
        $advance->remaining_amount;
        $advance->employee->full_name;
    }
    expect(count(DB::getQueryLog()))->toBe($count);
    DB::disableQueryLog();
});

test('advance migration is reversible in an empty isolated advance schema and preserves reference records', function () {
    $migration = require database_path('migrations/2026_09_21_160000_create_employee_advances.php');
    $migration->down();
    expect(Schema::hasTable('employee_advances'))->toBeFalse()
        ->and(Employee::whereKey($this->employee->id)->exists())->toBeTrue();
    $migration->up();
    expect(Schema::hasTable('employee_advance_entries'))->toBeTrue()
        ->and(DB::table('partner_finance_entries')->count())->toBe(0);
});

test('employee and classification history cannot be deleted out from under a confirmed manual expense', function () {
    $advance = $this->service->issue(advanceData($this->employee, ['source' => 'other']), auth()->user());
    $entry = $this->service->manualExpense($advance->id, advanceManualData(), auth()->user());
    expect($entry->expenseType->isUsed())->toBeTrue()
        ->and(fn () => $entry->expenseType->delete())->toThrow(ValidationException::class)
        ->and(fn () => $this->employee->delete())->toThrow(ValidationException::class);
    $entry->expenseType->update(['active' => false]);
    expect((float) $this->ledger->pnl(null, null)->sum('amount'))->toBe(150.0);
});
