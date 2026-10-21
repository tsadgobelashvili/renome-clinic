<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Bank;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\Bank\BankClassificationDiagnostic;
use App\Services\Bank\BankClassificationRepair;
use App\Services\Bank\BankClassificationService;
use App\Services\Bank\BankExpenseAssignment;
use App\Services\Bank\BankIngestionService;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\EntryDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    app()->setLocale('ka');
    $this->sterilization = new ExpenseCategory;
    $this->sterilization->forceFill(['name' => 'სტერილიზაცია', 'classification_dimension' => 'direction', 'active' => true, 'sort_order' => 0])->save();
    $this->cleaning = new ExpenseCategory;
    $this->cleaning->forceFill(['name' => 'დასუფთავება', 'classification_dimension' => 'type', 'active' => true, 'sort_order' => 0])->save();
    $this->child = app(ExpenseDimensions::class)->childForType($this->sterilization->id, $this->cleaning->id);
});

function fallbackBankRow(string $vendor, string $amount = '100.00'): BankTransaction
{
    $data = new BankTransactionData(['operation_id' => $vendor, 'transaction_date' => '2026-10-21 10:00:00',
        'direction' => 'outflow', 'currency' => 'GEL', 'amount' => $amount, 'counterparty_name' => $vendor, 'description' => 'Unrelated payment']);
    app(BankIngestionService::class)->ingest([$data], 'api');

    return BankTransaction::where('operation_id', $vendor)->sole();
}

function fallbackEvidence(BankTransaction $row): array
{
    return [$row->id => ['snapshot_sha256' => BankClassificationRepair::snapshot(DB::table('bank_transactions')->find($row->id)),
        'mechanism' => 'buggy_null_code_fallback', 'reference' => 'test-fixture: observed inference trace with NULL codes',
        'before' => ['expense_direction_id' => null, 'expense_type_id' => null]]];
}

test('null and empty inference codes never resolve the first custom direction or type', function () {
    $dimensions = app(ExpenseDimensions::class);
    foreach (['direction', 'type'] as $dimension) {
        expect($dimensions->id($dimension, null))->toBeNull()
            ->and($dimensions->id($dimension, ''))->toBeNull();
    }
    expect($dimensions->infer([]))->toBe(['expense_direction_id' => null, 'expense_type_id' => null]);
    // Explicit legacy mappings and deliberate custom IDs still work.
    expect($dimensions->id('direction', 'general'))->not->toBeNull();
});

test('different imported vendors remain unclassified through display edit save and duplicate import', function () {
    foreach (['Unknown A' => '4000.00', 'Unknown B' => '5.00', 'Unknown C' => '2440.00', 'Unknown D' => '5.00'] as $vendor => $amount) {
        $row = fallbackBankRow($vendor, $amount);
        $row->update(['description' => 'Saved notes']);
        fallbackBankRow($vendor, $amount);
        expect($row->fresh()->expense_direction_id)->toBeNull()->and($row->fresh()->expense_type_id)->toBeNull()
            ->and(BankTransaction::classificationMechanism($row))->toBe('none');
        $entry = app(EntryDetails::class)->query(app(AccountingLedger::class)->movements(null, null, 'bank'))
            ->where('entry_key', 'bank:'.$row->id)->sole();
        expect($entry->detail_bank_classification)->toBe('none')->and($entry->detail_category)->toBeNull();
        Livewire::test(Bank::class)->call('showTransaction', $row->id)
            ->assertSet('expenseDirectionId', '')->assertSet('expenseTypeId', '')
            ->assertSee('დასაზუსტებელია')->assertSee('კლასიფიკაციის გარეშე')
            ->assertSeeHtml('<option value="">დასაზუსტებელია</option>')
            ->assertViewHas('typeOptions', []);
    }
    expect(BankTransaction::count())->toBe(4);
    $trace = app(BankClassificationDiagnostic::class)->trace();
    expect($trace['scanned_outflows'])->toBe(4)->and($trace['suspect_pair_count'])->toBe(0);
    foreach ($trace['records'] as $record) {
        expect($record['origin'])->toBe('no_classification')
            ->and($record['stored_ids']['expense_direction_id'])->toBeNull()
            ->and($record['stored_ids']['expense_type_id'])->toBeNull()
            ->and($record['finance_display']['direction'])->toBe('დასაზუსტებელია')
            ->and($record['finance_display']['classification'])->toBe('დასაზუსტებელია');
    }
});

test('explicit vendor rule and manual sterilization survive import and unrelated vendors inherit neither', function () {
    app(BankExpenseAssignment::class)->saveRule(['counterparty' => 'Known utility', 'active' => true, 'confirm_company_default' => true,
        'expense_direction_id' => app(ExpenseDimensions::class)->id('direction', 'general'),
        'expense_type_id' => app(ExpenseDimensions::class)->id('type', 'utilities', app(ExpenseDimensions::class)->id('direction', 'general'))], auth()->user());
    $known = fallbackBankRow('Known utility');
    $manual = fallbackBankRow('Intentional cleaning');
    app(BankExpenseAssignment::class)->assign($manual->id, ['expense_direction_id' => $this->sterilization->id, 'expense_type_id' => $this->child], auth()->user());
    fallbackBankRow('Intentional cleaning');
    expect($known->classification_source)->toBe('rule')->and($known->expense_type_id)->not->toBe($this->child)
        ->and($manual->fresh()->classification_source)->toBe('manual')->and($manual->fresh()->expense_type_id)->toBe($this->child)
        ->and(fallbackBankRow('Other vendor')->expense_direction_id)->toBeNull();
});

test('shared inference leaves unknown manual finance expenses unclassified', function () {
    $row = FinanceTransaction::create(['type' => 'expense', 'category' => 'transport', 'amount' => 42, 'currency' => 'GEL',
        'transaction_date' => now(), 'payment_method' => 'cash', 'cash_source' => 'withdrawn_cash']);
    expect($row->expense_direction_id)->toBeNull()->and($row->expense_type_id)->toBeNull();
});

test('dry run and apply require evidence and preserve intentional classifications and every monetary field', function () {
    $bad = fallbackBankRow('Observed fallback', '125.00');
    $manual = fallbackBankRow('Manual cleaning', '75.00');
    foreach ([$bad, $manual] as $row) {
        DB::table('bank_transactions')->where('id', $row->id)->update(['expense_direction_id' => $this->sterilization->id,
            'expense_type_id' => $this->child, 'bank_category_id' => BankCategory::where('code', 'operating_expense')->value('id')]);
    }
    DB::table('bank_transactions')->where('id', $manual->id)->update(['classification_source' => 'manual']);
    $repair = app(BankClassificationRepair::class);
    expect(array_filter($repair->inspect(), fn ($r) => $r['eligible']))->toBe([]);
    $evidence = fallbackEvidence($bad) + fallbackEvidence($manual);
    $original = (array) DB::table('bank_transactions')->find($bad->id);
    $totals = app(AccountingLedger::class)->pnlTotals(null, null)->toJson();
    $preview = $repair->inspect($evidence);
    expect(array_column(array_filter($preview, fn ($r) => $r['eligible']), 'id'))->toBe([$bad->id])
        ->and($bad->fresh()->expense_type_id)->toBe($this->child);
    $this->artisan('bank:repair-classification', ['--dry-run' => true])->assertSuccessful();
    $this->artisan('bank:repair-classification', ['--dry-run' => true, '--until' => '2026-10-21'])->assertSuccessful();
    $repair->apply($evidence, auth()->user());
    $expected = array_replace($original, ['expense_direction_id' => null, 'expense_type_id' => null, 'classification_source' => 'repair']);
    expect((array) DB::table('bank_transactions')->find($bad->id))->toBe($expected)
        ->and($manual->fresh()->expense_type_id)->toBe($this->child)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toJson())->toBe($totals);
    $groups = app(AccountingLedger::class)->dimensionGroups(app(AccountingLedger::class)->dimensionEntries(null, null));
    expect((float) $groups->where('category_key', 'review')->sole()->amount)->toBe(125.0)
        ->and((float) $groups->where('category_key', (string) $this->sterilization->id)->sole()->amount)->toBe(75.0);
});

test('stale evidence and unauthorized actors cannot apply repairs', function () {
    $row = fallbackBankRow('Ambiguous fallback');
    DB::table('bank_transactions')->where('id', $row->id)->update(['expense_direction_id' => $this->sterilization->id, 'expense_type_id' => $this->child]);
    $repair = app(BankClassificationRepair::class);
    $evidence = fallbackEvidence($row);
    expect($repair->inspect($evidence)[0]['eligible'])->toBeTrue();
    DB::table('bank_transactions')->where('id', $row->id)->update(['bank_category_id' => BankCategory::where('code', 'operating_expense')->value('id')]);
    expect($repair->inspect($evidence)[0]['eligible'])->toBeFalse();
    expect(fn () => $repair->apply($evidence, auth()->user()))->toThrow(RuntimeException::class, 'Stale repair plan');
    DB::table('bank_transactions')->where('id', $row->id)->update(['classification_source' => 'rule']);
    expect($repair->inspect(fallbackEvidence($row))[0]['eligible'])->toBeFalse();
    expect(fn () => $repair->apply(fallbackEvidence($row), User::factory()->create(['role' => User::ROLE_ADMINISTRATOR])))
        ->toThrow(HttpException::class);
});

test('historical fallback on an uncategorized debit repairs to unclassified without losing any expenses or movements', function () {
    $row = fallbackBankRow('Historical default only', '4000.00');
    DB::table('bank_transactions')->where('id', $row->id)->update(['expense_direction_id' => $this->sterilization->id, 'expense_type_id' => $this->child]);
    $beforeRow = (array) DB::table('bank_transactions')->find($row->id);
    $historicalTrace = app(BankClassificationDiagnostic::class)->trace([$row->id])['records'][0];
    expect($historicalTrace['origin'])->toBe('historical_persisted_value_origin_unproven')
        ->and($historicalTrace['stored_rule_id'])->toBeNull()->and($historicalTrace['current_matching_rule_id'])->toBeNull()
        ->and($historicalTrace['finance_display']['direction'])->toBe('სტერილიზაცია')
        ->and($historicalTrace['finance_display']['classification'])->toBe('დასუფთავება')
        ->and($historicalTrace['classification_if_imported_today']['classification_source'])->toBeNull();
    $ledger = app(AccountingLedger::class);
    $before = $ledger->pnlTotals(null, null)->toJson();
    $movements = $ledger->movementTotals(null, null)->toJson();
    $repair = app(BankClassificationRepair::class);
    $evidence = fallbackEvidence($row);
    $preview = $repair->inspect($evidence)[0];
    expect($preview['eligible'])->toBeTrue()->and($preview['preserve_expense_recognition'])->toBeTrue();
    $repair->apply($evidence, auth()->user());
    expect((array) DB::table('bank_transactions')->find($row->id))->toBe(array_replace($beforeRow, [
        'expense_direction_id' => null, 'expense_type_id' => null, 'classification_source' => 'repair',
    ]))->and($ledger->pnlTotals(null, null)->toJson())->toBe($before)
        ->and($ledger->movementTotals(null, null)->toJson())->toBe($movements)
        ->and((float) $ledger->dimensionGroups($ledger->dimensionEntries(null, null))->sole()->amount)->toBe(4000.0);
    $row->refresh()->update(['description' => 'Later notes']);
    expect($row->expense_direction_id)->toBeNull()->and($row->expense_type_id)->toBeNull();
    expect(app(ExpenseDimensions::class)->infer($row->getAttributes()))->toBe(['expense_direction_id' => null, 'expense_type_id' => null]);
    $trace = app(BankClassificationDiagnostic::class)->trace([$row->id]);
    expect($trace['records'][0]['finance_display']['direction'])->toBe('დასაზუსტებელია')
        ->and($trace['records'][0]['finance_expense_display'][0]['classification'])->toBe('დასაზუსტებელია');
    $general = app(ExpenseDimensions::class)->id('direction', 'general');
    $rule = app(BankExpenseAssignment::class)->saveRule(['counterparty' => 'Historical default only', 'active' => true,
        'confirm_company_default' => true, 'expense_direction_id' => $general,
        'expense_type_id' => app(ExpenseDimensions::class)->id('type', 'utilities', $general)], auth()->user());
    expect(app(BankClassificationService::class)->applyToUncategorized($rule->id))->toBe(1)
        ->and($row->fresh()->classification_source)->toBe('rule')
        ->and($ledger->pnlTotals(null, null)->toJson())->toBe($before);
});

test('a current reliable vendor rule protects a candidate even when historical provenance is missing', function () {
    $row = fallbackBankRow('Rule protected cleaning');
    DB::table('bank_transactions')->where('id', $row->id)->update(['expense_direction_id' => $this->sterilization->id,
        'expense_type_id' => $this->child, 'bank_category_id' => BankCategory::where('code', 'operating_expense')->value('id')]);
    app(BankExpenseAssignment::class)->saveRule(['counterparty' => 'Rule protected cleaning', 'active' => true,
        'confirm_company_default' => true, 'expense_direction_id' => $this->sterilization->id, 'expense_type_id' => $this->child], auth()->user());
    $repair = app(BankClassificationRepair::class);
    $evidence = fallbackEvidence($row);
    expect($repair->inspect($evidence)[0]['eligible'])->toBeFalse();
    $repair->apply($evidence, auth()->user());
    expect($row->fresh()->expense_type_id)->toBe($this->child);
});

test('legacy-only historical pair is traced and requires separate evidence before clearing legacy links', function () {
    $row = fallbackBankRow('Legacy historical', '2440.00');
    $manual = fallbackBankRow('Legitimate legacy cleaning', '5.00');
    $legacyChild = ExpenseSubcategory::create(['expense_category_id' => $this->sterilization->id, 'name' => 'დასუფთავება', 'active' => true]);
    $category = BankCategory::where('code', 'operating_expense')->sole();
    $category->update(['expense_category_id' => $this->sterilization->id]);
    foreach ([$row, $manual] as $record) {
        DB::table('bank_transactions')->where('id', $record->id)->update(['expense_category_id' => $this->sterilization->id,
            'expense_subcategory_id' => $legacyChild->id, 'bank_category_id' => $category->id]);
    }
    DB::table('bank_transactions')->where('id', $manual->id)->update(['classification_source' => 'manual']);
    $trace = app(BankClassificationDiagnostic::class)->trace([$row->id])['records'][0];
    expect($trace['display_path'])->toBe('stored_legacy_links')->and($trace['suspect_pair'])->toBeTrue()
        ->and($trace['stored_ids']['expense_direction_id'])->toBeNull()
        ->and($trace['finance_expense_display'][0]['direction'])->toBe('სტერილიზაცია')
        ->and($trace['finance_expense_display'][0]['classification'])->toBe('დასუფთავება');
    $repair = app(BankClassificationRepair::class);
    $evidence = fallbackEvidence($row) + fallbackEvidence($manual);
    expect(array_filter($repair->inspect($evidence), fn ($r) => $r['eligible']))->toBe([]);
    $evidence[$row->id]['before'] += ['expense_category_id' => null, 'expense_subcategory_id' => null];
    $preview = $repair->inspect($evidence);
    expect(array_column(array_filter($preview, fn ($r) => $r['eligible']), 'id'))->toBe([$row->id]);
    $before = app(AccountingLedger::class)->pnlTotals(null, null)->toJson();
    $repair->apply($evidence, auth()->user());
    expect($row->fresh()->expense_category_id)->toBeNull()->and($row->fresh()->expense_subcategory_id)->toBeNull()
        ->and($manual->fresh()->expense_subcategory_id)->toBe($legacyChild->id)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toJson())->toBe($before);
    $trace = app(BankClassificationDiagnostic::class)->trace([$row->id])['records'][0];
    expect($trace['finance_expense_display'][0]['direction'])->toBe('დასაზუსტებელია')
        ->and($trace['finance_expense_display'][0]['classification'])->toBe('დასაზუსტებელია');
});
