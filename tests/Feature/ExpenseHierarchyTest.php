<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Bank;
use App\Filament\Pages\BankRules;
use App\Filament\Pages\ExpenseCategories;
use App\Models\BankTransaction;
use App\Models\ExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\Bank\BankClassificationService;
use App\Services\Bank\BankExpenseAssignment;
use App\Services\Bank\BankIngestionService;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->dimensions = app(ExpenseDimensions::class);
});

test('settings creates distinct same-name children and Bank only offers children of the selected parent', function () {
    $settings = Livewire::test(ExpenseCategories::class);
    $parents = [];
    foreach (['Test Surgery' => ['Materials', 'Salary', 'Equipment'], 'Test Therapy' => ['Materials', 'Salary']] as $name => $children) {
        $settings->call('edit')->set('name', $name)->call('save')->assertHasNoErrors();
        $parent = ExpenseCategory::where('name', $name)->sole();
        foreach ($children as $child) {
            $settings->call('edit', null, $parent->id)->set('name', $child)->call('save')->assertHasNoErrors();
        }
        $parents[] = $parent;
    }
    [$surgery, $therapy] = $parents;
    $material = $surgery->children()->where('name', 'Materials')->sole();
    expect($therapy->children()->where('name', 'Materials')->sole()->id)->not->toBe($material->id);
    Livewire::test(Bank::class)->set('expenseDirectionId', $surgery->id)
        ->assertViewHas('typeOptions', fn ($options) => count($options) === 3 && isset($options[$material->id]))
        ->set('expenseTypeId', $material->id)->set('expenseDirectionId', $therapy->id)
        ->assertSet('expenseTypeId', null)
        ->assertViewHas('typeOptions', fn ($options) => count($options) === 2 && ! isset($options[$material->id]));
    Livewire::test(BankRules::class)->call('edit')->set('directionId', $surgery->id)->set('typeId', $material->id)
        ->set('directionId', $therapy->id)->assertSet('typeId', null);
    expect(fn () => $this->dimensions->validate(['expense_direction_id' => $therapy->id, 'expense_type_id' => $material->id], required: true))
        ->toThrow(ValidationException::class);
});

test('remembered rules restore valid pairs and ignore inactive or mismatched children', function () {
    $parent = $this->dimensions->id('direction', 'surgery');
    $child = ExpenseCategory::findOrFail($this->dimensions->id('type', 'materials', $parent));
    $rule = app(BankExpenseAssignment::class)->saveRule(['counterparty' => 'Hierarchy supplier', 'confirm_company_default' => true,
        'expense_direction_id' => $parent, 'expense_type_id' => $child->id, 'active' => true], auth()->user());
    $make = fn ($id) => new BankTransactionData(['operation_id' => $id, 'transaction_date' => '2026-09-17 10:00:00',
        'direction' => 'outflow', 'amount' => '1200.00', 'currency' => 'GEL', 'operation_type' => 'PMD', 'counterparty_name' => 'Hierarchy supplier']);
    $ingest = app(BankIngestionService::class);
    $ingest->ingest([$make('hierarchy-valid')], 'api');
    expect(BankTransaction::sole()->expense_type_id)->toBe($child->id);
    $child->update(['active' => false]);
    $ingest->ingest([$make('hierarchy-inactive')], 'api');
    expect(BankTransaction::where('operation_id', 'hierarchy-inactive')->sole()->expense_type_id)->toBeNull();
    $child->update(['active' => true]);
    // Simulate a corrupted/old saved pair: runtime rule matching must ignore it.
    DB::table('bank_categorization_rules')->where('id', $rule->id)->update(['expense_direction_id' => $this->dimensions->id('direction', 'therapy')]);
    expect(app(BankClassificationService::class)->context()['rules'])->toBeEmpty();
    expect(FinanceTransaction::count())->toBe(0);
});

test('hierarchy backfill preserves monetary rows legacy links and incomplete classifications', function () {
    $parent = $this->dimensions->id('direction', 'surgery');
    $legacy = $this->dimensions->id('type', 'materials');
    $record = FinanceTransaction::create(['type' => 'expense', 'transaction_date' => now(), 'payment_method' => 'bank_transfer',
        'amount' => 1200, 'currency' => 'GEL', 'expense_direction_id' => $parent, 'expense_type_id' => $legacy]);
    DB::table('finance_transactions')->where('id', $record->id)->update(['expense_type_id' => $legacy]);
    $unknown = FinanceTransaction::create(['type' => 'expense', 'transaction_date' => now(), 'payment_method' => 'bank_transfer',
        'amount' => 25, 'currency' => 'GEL', 'category' => 'other_expense', 'description' => 'Unmapped history']);
    $before = (array) DB::table('finance_transactions')->find($record->id);
    $unknownBefore = (array) DB::table('finance_transactions')->find($unknown->id);
    $totals = app(AccountingLedger::class)->pnlTotals(null, null)->toJson();
    $this->dimensions->backfillHierarchy();
    $after = (array) DB::table('finance_transactions')->find($record->id);
    expect($after['expense_type_id'])->not->toBe($legacy);
    $child = ExpenseCategory::findOrFail($after['expense_type_id']);
    expect($child->parent_id)->toBe($parent)->and($child->legacy_type_id)->toBe($legacy);
    unset($before['expense_type_id'], $after['expense_type_id']);
    expect($after)->toBe($before)->and((array) DB::table('finance_transactions')->find($unknown->id))->toBe($unknownBefore)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toJson())->toBe($totals);
    $this->dimensions->backfillHierarchy();
    expect(FinanceTransaction::count())->toBe(2)->and($record->fresh()->expense_type_id)->toBe($child->id);
});

test('reverse grouping combines custom subcategory names across parents without changing totals', function () {
    foreach (['surgery', 'therapy'] as $code) {
        $parent = $this->dimensions->id('direction', $code);
        $child = new ExpenseCategory(['name' => "Custom supplier's materials"]);
        $child->forceFill(['classification_dimension' => 'type', 'parent_id' => $parent])->save();
        FinanceTransaction::create(['type' => 'expense', 'transaction_date' => now(), 'payment_method' => 'bank_transfer',
            'amount' => 1200, 'currency' => 'GEL', 'expense_direction_id' => $parent, 'expense_type_id' => $child->id]);
    }
    $ledger = app(AccountingLedger::class);
    $byParent = $ledger->dimensionGroups($ledger->dimensionEntries(null, null));
    $entries = $ledger->dimensionEntries(null, null, grouping: 'type');
    $byName = $ledger->dimensionGroups($entries);
    expect($byParent)->toHaveCount(2)->and($byName)->toHaveCount(1)
        ->and((float) $byParent->sum('amount'))->toBe(2400.0)->and((float) $byName->sum('amount'))->toBe(2400.0)
        ->and($ledger->dimensionGroups($entries, $byName->sole()->category_key))->toHaveCount(2)
        ->and(ctype_digit($byName->sole()->category_key))->toBeTrue();
});
