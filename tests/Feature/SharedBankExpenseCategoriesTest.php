<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Bank;
use App\Filament\Pages\BankCategories;
use App\Filament\Pages\BankRules;
use App\Filament\Pages\ExpenseCategories;
use App\Filament\Pages\Finance;
use App\Models\BankCategorizationRule;
use App\Models\BankTransaction;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\Bank\BankClassificationService;
use App\Services\Bank\BankExpenseAssignment;
use App\Services\Bank\BankIngestionService;
use App\Services\Bank\BankRuleMatcher;
use App\Services\Finance\AccountingLedger;
use App\Support\ExpenseCategoryForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-09-13 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
});

function sharedBankRow(string $company = 'Krosi 2', string $purpose = 'Monthly rent', array $overrides = []): BankTransaction
{
    $data = new BankTransactionData(array_replace(['transaction_date' => '2026-09-13 10:00:00', 'direction' => 'outflow', 'amount' => '100.00',
        'currency' => 'GEL', 'operation_type' => 'PMD', 'operation_id' => (string) Str::uuid(), 'counterparty_name' => $company, 'description' => $purpose], $overrides));
    app(BankIngestionService::class)->ingest([$data], 'import');

    return BankTransaction::where('deduplication_key', $data->deduplicationKey())->sole();
}

function sharedBankRule(ExpenseCategory $category, ?string $company = null, ?string $keyword = null, ?ExpenseSubcategory $child = null, array $extra = []): BankCategorizationRule
{
    return app(BankExpenseAssignment::class)->saveRule(array_replace(['counterparty' => $company, 'purpose_keyword' => $keyword,
        'expense_category_id' => $category->id, 'expense_subcategory_id' => $child?->id, 'active' => true, 'confirm_company_default' => true], $extra), auth()->user());
}

test('Cash and Bank share independent dimension IDs with lazy nested totals and live renamed labels', function () {
    $category = ExpenseCategory::where('classification_code', 'laboratory')->sole();
    $child = ExpenseCategory::where('classification_code', 'materials')->where('parent_id', $category->id)->sole();
    FinanceTransaction::create(['type' => 'expense', 'amount' => 120, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'withdrawn_cash',
        'transaction_date' => now(), 'expense_direction_id' => $category->id, 'expense_type_id' => $child->id, 'description' => 'Cash materials']);
    $bank = sharedBankRow('Supplier', 'Bank materials');
    app(BankExpenseAssignment::class)->assign($bank->id, ['expense_direction_id' => $category->id, 'expense_type_id' => $child->id], auth()->user());
    $ledger = app(AccountingLedger::class);
    expect((float) $ledger->dimensionGroups($ledger->dimensionEntries('2026-09-13', '2026-09-13'))->sole()->amount)->toBe(220.0);
    $page = Livewire::test(Finance::class)->call('selectOverviewCard', 'expenses')->call('selectExpenseCategory', (string) $category->id)
        ->assertViewHas('overviewDetails', null)->assertSee('მასალები')->call('selectExpenseSubcategory', (string) $child->id)
        ->assertSee('Cash materials')->assertSee('Bank materials')->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 2 && (float) $rows->sum('amount') === 220.0);
    Livewire::test(BankCategories::class)->call('edit', $category->id)->set('name', 'Renamed Laboratory')->call('save');
    Livewire::test(ExpenseCategories::class)->call('edit', $child->id, $category->id)->set('name', 'Renamed Materials')->call('save');
    $page->call('$refresh')->assertSee('Renamed Laboratory')->assertSee('Renamed Materials');
    Livewire::test(Bank::class)->assertSee('Renamed Laboratory')->assertSee('Renamed Materials');
    expect(ExpenseCategoryForm::categories()[$category->id])->toBe('Renamed Laboratory')
        ->and($bank->fresh()->expense_type_id)->toBe($child->id);
});

test('Krosi purpose-specific rules outrank company defaults without fuzzy company matches', function () {
    expect(BankRuleMatcher::company('შპს „კროსო“'))->toBe('კროსო');
    $rent = ExpenseCategory::create(['name' => 'Rent test', 'active' => true]);
    $utilities = ExpenseCategory::create(['name' => 'Utilities test', 'active' => true]);
    $other = ExpenseCategory::create(['name' => 'Other test', 'active' => true]);
    sharedBankRule($other, 'Krosi 2');
    sharedBankRule($rent, 'Krosi 2', 'rent');
    sharedBankRule($utilities, 'Krosi 2', 'utilities');
    expect(sharedBankRow('  LLC "KROSI  2" ', 'Monthly RENT')->expense_category_id)->toBe($rent->id)
        ->and(sharedBankRow('შპს „Krosi 2“', 'utilities September')->expense_category_id)->toBe($utilities->id)
        ->and(sharedBankRow('Krosi 2', 'maintenance')->expense_category_id)->toBe($other->id)
        ->and(sharedBankRow('Another Krosi 2', 'Monthly rent')->expense_category_id)->toBeNull();
    $ambiguous = sharedBankRow('Krosi 2', 'rent and utilities');
    expect($ambiguous->expense_category_id)->toBeNull()->and($ambiguous->classification_source)->toBeNull();
});

test('TELASI company default needs explicit confirmation and remembers both dimensions', function () {
    $category = ExpenseCategory::where('classification_code', 'general')->sole();
    $electricity = ExpenseCategory::where('classification_code', 'utilities')->where('parent_id', $category->id)->sole();
    $page = Livewire::test(BankRules::class)->call('edit')->set('counterparty', 'TELASI')->set('directionId', $category->id)->set('typeId', $electricity->id)
        ->call('save')->assertHasErrors('confirm_company_default');
    expect(BankCategorizationRule::count())->toBe(0);
    $page->set('confirmCompanyDefault', true)->call('save')->assertHasNoErrors();
    $row = sharedBankRow('LLC Telasi', 'Electricity bill');
    expect($row->expense_direction_id)->toBe($category->id)->and($row->expense_type_id)->toBe($electricity->id)
        ->and($row->classification_source)->toBe('rule');
    $category->update(['active' => false]);
    expect(sharedBankRow('TELASI', 'Next bill')->expense_category_id)->toBeNull();
});

test('remember and optional backfill protect manual assignments and saved rules change only explicitly', function () {
    $rent = ExpenseCategory::create(['name' => 'Rent remembered', 'active' => true]);
    $utilities = ExpenseCategory::create(['name' => 'Utilities override', 'active' => true]);
    $row = sharedBankRow();
    $old = sharedBankRow();
    $manual = sharedBankRow();
    app(BankExpenseAssignment::class)->assign($manual->id, ['expense_category_id' => $utilities->id], auth()->user());
    $page = Livewire::test(Bank::class)->call('showTransaction', $row->id)->set('expenseCategoryId', $rent->id)
        ->set('rememberRule', true)->set('ruleKeyword', 'rent')->set('applyExisting', true)->call('saveExpenseClassification')->assertHasNoErrors();
    $rule = BankCategorizationRule::sole();
    expect($old->fresh()->expense_category_id)->toBe($rent->id)->and($manual->fresh()->expense_category_id)->toBe($utilities->id)
        ->and(sharedBankRow()->expense_category_id)->toBe($rent->id);
    $page->set('expenseCategoryId', $utilities->id)->call('saveExpenseClassification');
    expect($rule->fresh()->expense_category_id)->toBe($rent->id)->and(sharedBankRow()->expense_category_id)->toBe($rent->id);
    $page->set('updateSavedRule', true)->call('saveExpenseClassification')->assertHasNoErrors();
    expect($rule->fresh()->expense_category_id)->toBe($utilities->id)->and(sharedBankRow()->expense_category_id)->toBe($utilities->id)
        ->and($old->fresh()->expense_category_id)->toBe($rent->id);
});

test('dependent selectors and service reject mismatched subcategories atomically', function () {
    $first = ExpenseCategory::create(['name' => 'First', 'active' => true]);
    $second = ExpenseCategory::create(['name' => 'Second', 'active' => true]);
    $child = $first->subcategories()->create(['name' => 'Child', 'active' => true]);
    $row = sharedBankRow();
    $page = Livewire::test(Bank::class)->call('showTransaction', $row->id)->set('expenseCategoryId', $first->id)->set('expenseSubcategoryId', $child->id)
        ->set('expenseCategoryId', $second->id)->assertSet('expenseSubcategoryId', null);
    $page->set('expenseSubcategoryId', $child->id)->call('saveExpenseClassification')->assertHasErrors('expense_subcategory_id');
    expect($row->fresh()->expense_category_id)->toBeNull();
});

test('used shared categories and children cannot be deleted or moved and unused records can be deleted', function () {
    $category = ExpenseCategory::create(['name' => 'Used by Bank', 'active' => true]);
    $child = $category->subcategories()->create(['name' => 'Used child', 'active' => true]);
    $unused = ExpenseCategory::create(['name' => 'Unused', 'active' => true]);
    $category->forceFill(['classification_dimension' => 'direction'])->save();
    $unused->forceFill(['classification_dimension' => 'direction'])->save();
    app(BankExpenseAssignment::class)->assign(sharedBankRow()->id, ['expense_category_id' => $category->id, 'expense_subcategory_id' => $child->id], auth()->user());
    expect(fn () => $child->delete())->toThrow(ValidationException::class)
        ->and(fn () => $child->update(['expense_category_id' => $unused->id]))->toThrow(ValidationException::class);
    Livewire::test(ExpenseCategories::class)->call('deleteRecord', $category->id)->call('deleteRecord', $unused->id);
    expect($category->fresh()->active)->toBeFalse()->and(ExpenseCategory::find($unused->id))->toBeNull();
});

test('unmatched debits remain visible in Bank but are excluded from expenses alongside non-expense movements', function () {
    $row = sharedBankRow('Unknown supplier', 'Unknown expense');
    sharedBankRow('Cash', 'Deposit', ['operation_type' => 'PBS']);
    sharedBankRow('Terminal', 'POS settlement', ['operation_type' => 'TRN', 'direction' => 'inflow']);
    $groups = app(AccountingLedger::class)->expenseGroups('2026-09-13', '2026-09-13');
    expect($groups)->toBeEmpty();
    Livewire::test(Finance::class)->call('selectOverviewCard', 'expenses')->assertDontSee('Unknown expense');
    Livewire::test(Bank::class)->set('uncategorizedExpenses', true)->assertSee('Unknown expense');
});

test('description fallback and supporting account are exact and ambiguous backfill does not pick the selected rule blindly', function () {
    $one = ExpenseCategory::create(['name' => 'One', 'active' => true]);
    $two = ExpenseCategory::create(['name' => 'Two', 'active' => true]);
    sharedBankRule($one, null, 'implant');
    expect(sharedBankRow('Supplier', 'Implant delivery')->expense_category_id)->toBe($one->id);
    sharedBankRule($two, 'Supplier', 'implant', null, ['counterparty_account' => 'GE 123']);
    expect(sharedBankRow('Supplier', 'Implant delivery', ['counterparty_account' => 'GE123'])->expense_category_id)->toBe($two->id);
    $old = sharedBankRow('Conflict', 'rent');
    $rule = sharedBankRule($one, 'Conflict', 'rent');
    sharedBankRule($two, 'Conflict', 'rent');
    expect(app(BankClassificationService::class)->applyToUncategorized($rule->id))->toBe(0)->and($old->fresh()->expense_category_id)->toBeNull();
});

test('rule matching loads shared targets once and does not query per imported row', function () {
    $category = ExpenseCategory::create(['name' => 'Query target', 'active' => true]);
    sharedBankRule($category, 'Supplier', 'materials');
    $classifier = app(BankClassificationService::class);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $context = $classifier->context();
    for ($i = 0; $i < 100; $i++) {
        $result = $classifier->classify(['bank' => 'BOG', 'direction' => 'outflow', 'operation_type' => 'PMD', 'counterparty_name' => 'LLC Supplier', 'description' => 'Materials purchase'], $context);
        expect($result['expense_category_id'])->toBe($category->id);
    }
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(3); // Categories, active rules and one scoped dimension registry read.
});

test('existing inactive classification remains readable while new inactive assignments are rejected', function () {
    $category = ExpenseCategory::create(['name' => 'Historical expense', 'active' => true]);
    $child = $category->subcategories()->create(['name' => 'Historical child', 'active' => true]);
    $row = sharedBankRow();
    $service = app(BankExpenseAssignment::class);
    $service->assign($row->id, ['expense_category_id' => $category->id, 'expense_subcategory_id' => $child->id], auth()->user());
    $category->update(['active' => false]);
    $child->update(['active' => false]);
    $service->assign($row->id, ['expense_category_id' => $category->id, 'expense_subcategory_id' => $child->id], auth()->user());
    expect(fn () => $service->assign(sharedBankRow()->id, ['expense_category_id' => $category->id, 'expense_subcategory_id' => $child->id], auth()->user()))->toThrow(ValidationException::class);
    Livewire::test(Bank::class)->assertSee('Historical expense')->assertSee('Historical child');
});

test('Bank categorization expands directly below one row and toggles without a bottom editor', function () {
    $first = sharedBankRow('First supplier');
    $second = sharedBankRow('Second supplier');
    $page = Livewire::test(Bank::class)->call('toggleTransaction', $first->id)->assertSet('transactionId', $first->id);
    $dom = new DOMDocument;
    @$dom->loadHTML(mb_convert_encoding($page->html(), 'HTML-ENTITIES', 'UTF-8'));
    $xpath = new DOMXPath($dom);
    $editor = $xpath->query('//tr[@id="bank-editor-'.$first->id.'"]')->item(0);
    expect($editor)->not->toBeNull();
    $previous = $xpath->query('preceding-sibling::tr[1]', $editor)->item(0);
    expect($previous->getAttribute('wire:key'))->toBe('bank-transaction-'.$first->id);
    expect(substr_count($page->html(), 'wire:submit="saveInlineClassification"'))->toBe(1);
    $page->assertDontSee('bank-detail-', false)->assertDontSee('wire:model="ruleKeyword"', false)
        ->call('toggleTransaction', $second->id)->assertSet('transactionId', $second->id)
        ->assertDontSee('id="bank-editor-'.$first->id.'"', false)
        ->call('toggleTransaction', $second->id)->assertSet('transactionId', null)
        ->assertDontSee('wire:submit="saveInlineClassification"', false);
});

test('inline save updates its row and remembers through the existing rules service', function () {
    $category = ExpenseCategory::where('classification_code', 'laboratory')->sole();
    $child = ExpenseCategory::where('classification_code', 'materials')->where('parent_id', $category->id)->sole();
    $record = sharedBankRow('Inline supplier');
    $page = Livewire::test(Bank::class)->call('toggleTransaction', $record->id)
        ->set('expenseDirectionId', $category->id)->set('expenseTypeId', $child->id)
        ->set('rememberRule', true)->call('saveInlineClassification')->assertHasNoErrors()
        ->assertSet('transactionId', $record->id)->assertSee('ლაბორატორია')->assertSee('მასალები');
    expect($record->fresh()->expense_direction_id)->toBe($category->id)
        ->and($record->fresh()->expense_type_id)->toBe($child->id)
        ->and(sharedBankRow('Inline supplier')->expense_direction_id)->toBe($category->id);
    expect(BankCategorizationRule::where('counterparty', 'Inline supplier')->count())->toBe(1);
});
