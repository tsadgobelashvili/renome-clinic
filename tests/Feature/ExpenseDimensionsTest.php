<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Bank;
use App\Filament\Pages\Finance;
use App\Filament\Pages\FinanceReports;
use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\BankCategorizationRule;
use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\User;
use App\Services\Bank\BankExpenseAssignment;
use App\Services\Bank\BankIngestionService;
use App\Services\EmployeePayrollService;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\FinanceManager;
use App\Support\ExpenseCategoryForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-09-17 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    $this->dimensions = app(ExpenseDimensions::class);
});

function dimensionExpense(array $data = []): FinanceTransaction
{
    return FinanceTransaction::create($data + ['type' => 'expense', 'transaction_date' => now(), 'category' => 'materials',
        'amount' => 1200, 'currency' => 'GEL', 'payment_method' => 'bank_transfer']);
}

test('hierarchical categories and subcategories regroup the same 1200 expense without changing totals', function () {
    $direction = $this->dimensions->id('direction', 'surgery');
    $type = $this->dimensions->id('type', 'materials');
    dimensionExpense(['expense_direction_id' => $direction, 'expense_type_id' => $type]);
    $ledger = app(AccountingLedger::class);
    foreach (['direction', 'type'] as $grouping) {
        $entries = $ledger->dimensionEntries('2026-09-17', '2026-09-17', grouping: $grouping);
        $parent = $ledger->dimensionGroups($entries)->sole();
        $child = $ledger->dimensionGroups($entries, $parent->category_key)->sole();
        expect((int) $parent->category_key)->toBe($grouping === 'direction' ? $direction : $type)
            ->and((int) $child->subcategory_key)->toBe($grouping === 'direction' ? $this->dimensions->id('type', 'materials', $direction) : $direction)
            ->and((float) $parent->amount)->toBe(1200.0)->and((float) $child->amount)->toBe(1200.0)
            ->and((float) $ledger->pnlTotals(null, null)->sole()->expenses)->toBe(1200.0);
    }
    $page = Livewire::test(Finance::class)->call('selectOverviewCard', 'expenses')->assertSee('ქირურგია')
        ->call('selectExpenseCategory', (string) $direction)->assertSee('მასალები')
        ->call('selectExpenseSubcategory', (string) $this->dimensions->id('type', 'materials', $direction))->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1)
        ->set('expenseGrouping', 'type')->assertSet('overviewCategory', '')->assertSee('მასალები')
        ->call('selectExpenseCategory', (string) $type)->assertSee('ქირურგია');
    $page->set('expenseDirectionFilter', $this->dimensions->id('direction', 'therapy'))
        ->assertViewHas('expenseGroups', fn ($rows) => $rows->isEmpty());
    Livewire::test(FinanceReports::class)->call('selectReportTab', 'expense')->assertSee('ქირურგია')
        ->assertViewHas('reportTotal', 1200.0)->set('expenseGrouping', 'type')->assertSee('მასალები')->assertViewHas('reportTotal', 1200.0);
});

test('same expense type can be used for different directions and filters compose', function () {
    $type = $this->dimensions->id('type', 'materials');
    foreach (['surgery', 'laboratory'] as $direction) {
        dimensionExpense(['expense_direction_id' => $this->dimensions->id('direction', $direction), 'expense_type_id' => $type]);
    }
    $ledger = app(AccountingLedger::class);
    $entries = $ledger->dimensionEntries(null, null, grouping: 'type');
    expect((float) $ledger->dimensionGroups($entries)->sole()->amount)->toBe(2400.0)
        ->and($ledger->dimensionGroups($entries, (string) $type))->toHaveCount(2)
        ->and($ledger->dimensionEntries(null, null, currency: 'USD')->count())->toBe(0)
        ->and($ledger->dimensionEntries('2020-01-01', '2020-01-02')->count())->toBe(0)
        ->and($ledger->dimensionEntries(null, null, source: 'bank')->count())->toBe(0)
        ->and($ledger->dimensionEntries(null, null, direction: $this->dimensions->id('direction', 'surgery'), type: $type)->count())->toBe(1);
    expect(array_map(fn ($field) => $field->getName(), ExpenseCategoryForm::schema()))->toBe(['expense_direction_id', 'expense_type_id']);
});

test('Bank remembered rules persist both dimensions and reporting counts each debit once', function () {
    $direction = $this->dimensions->id('direction', 'laboratory');
    $type = $this->dimensions->id('type', 'materials', $direction);
    $ingestion = app(BankIngestionService::class);
    $make = fn ($id) => new BankTransactionData(['operation_id' => $id, 'transaction_date' => '2026-09-17 00:00:00',
        'direction' => 'outflow', 'amount' => '1200.00', 'currency' => 'GEL', 'operation_type' => 'PMD', 'counterparty_name' => 'Lab supplier']);
    $ingestion->ingest([$make('dimension-one')], 'api');
    $row = BankTransaction::sole();
    Livewire::test(Bank::class)->call('showTransaction', $row->id)->set('expenseDirectionId', $direction)->set('expenseTypeId', $type)
        ->set('rememberRule', true)->call('saveInlineClassification')->assertHasNoErrors();
    $rule = BankCategorizationRule::sole();
    expect($rule->expense_direction_id)->toBe($direction)->and($rule->expense_type_id)->toBe($type);
    $ingestion->ingest([$make('dimension-two'), $make('dimension-one')], 'api');
    $other = BankTransaction::where('operation_id', 'dimension-two')->sole();
    expect($other->expense_direction_id)->toBe($direction)->and($other->expense_type_id)->toBe($type)
        ->and(BankTransaction::count())->toBe(2)->and(FinanceTransaction::count())->toBe(0)
        ->and((float) app(AccountingLedger::class)->pnlTotals(null, null)->sole()->expenses)->toBe(2400.0);
    app(BankExpenseAssignment::class)->assign($row->id, ['expense_direction_id' => $this->dimensions->id('direction', 'therapy'), 'expense_type_id' => $this->dimensions->id('type', 'materials', $this->dimensions->id('direction', 'therapy'))], auth()->user());
    expect(BankTransaction::count())->toBe(2)->and((float) app(AccountingLedger::class)->pnlTotals(null, null)->sole()->expenses)->toBe(2400.0);
});

test('mapping preserves legacy links amounts and unknown classifications and is idempotent', function () {
    $department = ExpenseCategory::where('name', 'თერაპია')->firstOrFail();
    $child = ExpenseSubcategory::create(['expense_category_id' => $department->id, 'name' => 'ხელფასი', 'active' => true]);
    $known = dimensionExpense(['expense_category_id' => $department->id, 'expense_subcategory_id' => $child->id]);
    $unknownCategory = ExpenseCategory::create(['name' => 'Custom ambiguous legacy category', 'active' => true]);
    $unknown = dimensionExpense(['expense_category_id' => $unknownCategory->id, 'category' => 'managed_'.$unknownCategory->id]);
    DB::table('finance_transactions')->whereIn('id', [$known->id, $unknown->id])->update(['expense_direction_id' => null, 'expense_type_id' => null]);
    $before = $known->fresh()->only(['expense_category_id', 'expense_subcategory_id', 'amount', 'category']);
    $this->dimensions->backfill();
    expect($known->fresh()->only(array_keys($before)))->toBe($before)
        ->and($known->fresh()->expense_direction_id)->toBe($this->dimensions->id('direction', 'therapy'))
        ->and($known->fresh()->expense_type_id)->toBe($this->dimensions->id('type', 'salary', $known->fresh()->expense_direction_id))
        ->and($unknown->fresh()->expense_type_id)->toBeNull()->and($unknown->fresh()->expense_direction_id)->toBeNull()
        ->and(array_sum($this->dimensions->backfill()))->toBe(0)->and(FinanceTransaction::count())->toBe(2);
});

test('commissions use general and bank fee and salary infers employee department', function () {
    app(BankIngestionService::class)->ingest([new BankTransactionData(['operation_id' => 'fee-dimension', 'transaction_date' => '2026-09-17 00:00:00',
        'direction' => 'outflow', 'amount' => '1.50', 'currency' => 'GEL', 'operation_type' => 'COM'])], 'api');
    expect(BankTransaction::sole()->expense_type_id)->toBe($this->dimensions->id('type', 'bank_fee', $this->dimensions->id('direction', 'general')))
        ->and(BankTransaction::sole()->expense_direction_id)->toBe($this->dimensions->id('direction', 'general'));
    $employee = Employee::create(['first_name' => 'Admin', 'last_name' => 'Test', 'is_active' => true,
        'position_id' => EmployeePosition::where('name', 'Administrator')->sole()->id]);
    $employee->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'fixed_net', 'net_amount' => 800,
        'currency' => 'GEL', 'default_payment_method' => 'cash', 'effective_from' => '2026-09-01', 'is_active' => true]);
    app(FinanceManager::class)->create(['type' => 'income', 'transaction_date' => now(), 'category' => 'other_income',
        'amount' => 2000, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    $entry = app(EmployeePayrollService::class)->finalize($employee, 'clinic', '2026-09-01', '2026-09-17');
    $expense = FinanceTransaction::where('payroll_entry_id', $entry->id)->sole();
    expect($expense->expense_direction_id)->toBe($this->dimensions->id('direction', 'administration'))
        ->and($expense->expense_type_id)->toBe($this->dimensions->id('type', 'salary', $expense->expense_direction_id))
        ->and($expense->amount)->toBe('800.00');
});

test('inactive dimensions preserve history and wrong-axis assignments are rejected', function () {
    $direction = $this->dimensions->id('direction', 'surgery');
    $type = $this->dimensions->id('type', 'materials', $direction);
    $expense = dimensionExpense(['expense_direction_id' => $direction, 'expense_type_id' => $type]);
    ExpenseCategory::findOrFail($type)->update(['active' => false]);
    $expense->update(['description' => 'History still editable']);
    expect($this->dimensions->childOptions($direction))->not->toHaveKey($type)
        ->and($this->dimensions->childOptions($direction, $type))->toHaveKey($type)
        ->and($this->dimensions->labelById($type))->toBe('მასალები');
    expect(fn () => dimensionExpense(['expense_direction_id' => $direction, 'expense_type_id' => $type]))
        ->toThrow(ValidationException::class);
    expect(fn () => dimensionExpense(['expense_direction_id' => $type, 'expense_type_id' => $direction]))
        ->toThrow(ValidationException::class);
    expect(fn () => ExpenseCategory::findOrFail($direction)->delete())
        ->toThrow(ValidationException::class);
});

test('contradictory legacy classifications remain for review without overwriting originals', function () {
    $category = ExpenseCategory::where('classification_code', 'materials')->whereNull('parent_id')->sole();
    $child = $category->subcategories()->create(['name' => 'ხელფასი']);
    $inferred = $this->dimensions->infer(['expense_category_id' => $category->id, 'expense_subcategory_id' => $child->id]);
    expect($inferred['expense_type_id'])->toBeNull()->and($child->fresh()->name)->toBe('ხელფასი');
});

test('Israeli expense entry stores both dimensions without affecting source or currency', function () {
    $dimensions = ['expense_direction_id' => $this->dimensions->id('direction', 'laboratory'),
        'expense_type_id' => $this->dimensions->id('type', 'materials', $this->dimensions->id('direction', 'laboratory'))];
    PartnerFinanceTransaction::create(['source' => 'israeli', 'type' => 'transfer', 'transacted_at' => now(),
        'category' => 'other_transfer', 'from_account' => 'bank', 'to_account' => 'cash', 'amount' => 2000, 'currency' => 'USD']);
    $expense = app(FinanceManager::class)->create($dimensions + ['type' => 'expense',
        'transaction_date' => now(), 'cash_source' => 'israeli', 'payment_method' => 'cash', 'amount' => 1200, 'currency' => 'USD']);
    expect($expense->only(array_keys($dimensions)))->toBe($dimensions)->and($expense->currency)->toBe('USD');
    $ledger = app(AccountingLedger::class);
    expect((float) $ledger->dimensionGroups($ledger->dimensionEntries(null, null, currency: 'USD', businessSource: 'israeli'))->sole()->amount)->toBe(1200.0)
        ->and($ledger->dimensionEntries(null, null, businessSource: 'clinic')->count())->toBe(0);
    Livewire::test(ListPartnerFinance::class)
        ->assertSuccessful()->assertSee('ლაბორატორია')->assertSee('მასალები');
});
