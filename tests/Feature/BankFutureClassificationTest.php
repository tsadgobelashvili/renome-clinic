<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Bank;
use App\Models\BankCategorizationRule;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use App\Models\User;
use App\Services\Bank\BankExpenseAssignment;
use App\Services\Bank\BankIngestionService;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\EntryDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-21 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    app()->setLocale('ka');
    // The exact production IDs, plus a legacy root whose child used to be inferred.
    foreach ([44 => ['სტერილიზაცია', 'direction', null, null], 87 => ['დასუფთავება', 'type', null, null],
        88 => ['დასუფთავება', 'type', 44, 87]] as $id => [$name, $dimension, $parent, $legacy]) {
        DB::table('expense_categories')->updateOrInsert(['id' => $id], ['name' => $name, 'classification_dimension' => $dimension,
            'classification_code' => null, 'parent_id' => $parent, 'legacy_type_id' => $legacy, 'active' => true, 'sort_order' => 0]);
    }
    app(ExpenseDimensions::class)->reset();
    // A shared registry/category link must not become evidence about this vendor.
    BankCategory::whereIn('code', ['uncategorized', 'operating_expense'])->update(['expense_category_id' => 44]);
});

function futureBankDto(string $id, array $extra = []): BankTransactionData
{
    return new BankTransactionData(array_replace(['operation_id' => $id, 'transaction_date' => '2026-10-21 10:00:00',
        'direction' => 'outflow', 'amount' => '100.00', 'currency' => 'GEL', 'operation_type' => 'PMD',
        'counterparty_name' => 'Unknown vendor', 'description' => 'Ordinary outgoing payment'], $extra));
}

function importFutureBank(string $id, array $extra = [], string $source = 'api'): BankTransaction
{
    $dto = futureBankDto($id, $extra);
    app(BankIngestionService::class)->ingest([$dto], $source);

    return BankTransaction::where('deduplication_key', $dto->deduplicationKey())->sole();
}

test('unknown new payment persists null IDs and shows review without shared category fallback', function () {
    $row = importFutureBank('unknown');
    expect($row->expense_direction_id)->toBeNull()->and($row->expense_type_id)->toBeNull()
        ->and($row->expense_category_id)->toBeNull()->and($row->expense_subcategory_id)->toBeNull()
        ->and($row->classification_source)->toBeNull()->and($row->categorization_rule_id)->toBeNull();
    Livewire::test(Bank::class)->set('uncategorizedExpenses', true)->call('showTransaction', $row->id)
        ->assertSet('expenseDirectionId', '')->assertSet('expenseTypeId', '')
        ->assertSee('დასაზუსტებელია')->assertSeeHtml('<option value="">დასაზუსტებელია</option>');
    $ledger = app(AccountingLedger::class);
    $entry = app(EntryDetails::class)->query($ledger->dimensionEntries(null, null))->sole();
    expect($entry->expense_direction_id)->toBeNull()->and($entry->expense_type_id)->toBeNull()
        ->and($entry->detail_category)->toBeNull()->and($entry->category_name)->toBeNull();
});

test('unknown import never invokes shared expense inference at the Bank save boundary', function () {
    $this->partialMock(ExpenseDimensions::class, function ($mock) {
        $mock->shouldNotReceive('infer');
        $mock->shouldNotReceive('apply');
    });
    $row = importFutureBank('no-shared-inference');
    expect($row->expense_direction_id)->toBeNull()->and($row->expense_type_id)->toBeNull();
});

test('many unrelated API and statement vendors cannot inherit the production pair or catalog classifications', function () {
    $group = PurchaseProductGroup::create(['name' => 'დასუფთავება', 'expense_direction_id' => 44]);
    PurchaseProduct::create(['name' => 'Ordinary outgoing payment', 'normalized_name' => 'ordinary outgoing payment',
        'identity_key' => 'catalog-only', 'expense_direction_id' => 44,
        'expense_type_id' => 88, 'purchase_product_group_id' => $group->id]);
    foreach (['api', 'import'] as $source) {
        $rows = [];
        foreach (['4000.00', '5.00', '2440.00', '5.00', '21.00', '50.00', '77.77', '125.00'] as $index => $amount) {
            $rows[] = futureBankDto($source.'-'.$index, ['amount' => $amount, 'counterparty_name' => 'Supplier '.$source.' '.$index,
                'expense_direction_id' => 44, 'expense_type_id' => 88, 'classification_source' => 'manual', 'categorization_rule_id' => 88,
                'raw_data' => ['expense_direction_id' => 44, 'expense_type_id' => 88]]);
        }
        app(BankIngestionService::class)->ingest($rows, $source);
    }
    expect(BankTransaction::count())->toBe(16)
        ->and(BankTransaction::whereNotNull('expense_direction_id')->count())->toBe(0)
        ->and(BankTransaction::whereNotNull('expense_type_id')->count())->toBe(0)
        ->and(BankTransaction::whereNotNull('classification_source')->count())->toBe(0)
        ->and(BankTransaction::whereNotNull('categorization_rule_id')->count())->toBe(0);
});

test('a matched active rule supplies both dimensions and exact existing provenance while inactive and ambiguous rules do not', function () {
    $dimensions = app(ExpenseDimensions::class);
    $general = $dimensions->id('direction', 'general');
    $utilities = $dimensions->id('type', 'utilities', $general);
    $rule = app(BankExpenseAssignment::class)->saveRule(['counterparty' => 'Known power vendor', 'active' => true,
        'confirm_company_default' => true, 'expense_direction_id' => $general, 'expense_type_id' => $utilities], auth()->user());
    $row = importFutureBank('rule', ['counterparty_name' => 'Known power vendor']);
    expect($row->expense_direction_id)->toBe($general)->and($row->expense_type_id)->toBe($utilities)
        ->and($row->classification_source)->toBe('rule')->and($row->categorization_rule_id)->toBe($rule->id);
    app(BankExpenseAssignment::class)->saveRule(['counterparty' => 'Known power vendor', 'active' => true,
        'confirm_company_default' => true, 'expense_direction_id' => 44, 'expense_type_id' => 88], auth()->user());
    $ambiguous = importFutureBank('ambiguous', ['counterparty_name' => 'Known power vendor']);
    expect($ambiguous->expense_direction_id)->toBeNull()->and($ambiguous->expense_type_id)->toBeNull()
        ->and($ambiguous->classification_source)->toBeNull()->and($ambiguous->categorization_rule_id)->toBeNull();
    BankCategorizationRule::query()->update(['active' => false]);
    $inactive = importFutureBank('inactive', ['counterparty_name' => 'Known power vendor']);
    expect($inactive->expense_direction_id)->toBeNull()->and($inactive->expense_type_id)->toBeNull();
});

test('only deterministic BOG commissions receive the explicit system business classification', function () {
    $row = importFutureBank('commission', ['operation_type' => 'COM']);
    $dimensions = app(ExpenseDimensions::class);
    $general = $dimensions->id('direction', 'general');
    expect($row->expense_direction_id)->toBe($general)
        ->and($row->expense_type_id)->toBe($dimensions->id('type', 'bank_fee', $general))
        ->and($row->classification_source)->toBe('default')->and($row->categorization_rule_id)->toBeNull();
    $otherBank = importFutureBank('other-bank', ['operation_type' => 'COM', 'bank' => 'TBC']);
    expect($otherBank->expense_direction_id)->toBeNull()->and($otherBank->expense_type_id)->toBeNull();
    $deposit = importFutureBank('deposit', ['operation_type' => 'PBS', 'direction' => 'inflow']);
    expect($deposit->expense_direction_id)->toBeNull()->and($deposit->expense_type_id)->toBeNull();
});

test('backend save refuses the exact 44 88 assignment without usable provenance on new and unclassified imported rows', function (string $source, ?string $classificationSource) {
    $dto = futureBankDto('model-'.$source);
    $row = BankTransaction::create([...$dto->attributes, 'source' => $source, 'fingerprint' => $dto->fingerprint(),
        'deduplication_key' => $dto->deduplicationKey(), 'expense_direction_id' => 44, 'expense_type_id' => 88,
        'classification_source' => $classificationSource, 'categorization_rule_id' => null]);
    expect($row->fresh()->expense_direction_id)->toBeNull()->and($row->fresh()->expense_type_id)->toBeNull();
    $row->update(['expense_direction_id' => 44, 'expense_type_id' => 88]);
    expect($row->fresh()->expense_direction_id)->toBeNull()->and($row->fresh()->expense_type_id)->toBeNull();
})->with([['api', null], ['import', null], ['api', 'rule'], ['import', 'default']]);

test('reimport and unrelated note edits do not rewrite stored historical choices even when their provenance is missing', function () {
    $row = importFutureBank('historical-duplicate');
    DB::table('bank_transactions')->where('id', $row->id)->update(['expense_direction_id' => 44, 'expense_type_id' => 88]);
    $before = (array) DB::table('bank_transactions')->find($row->id);
    importFutureBank('historical-duplicate');
    expect((array) DB::table('bank_transactions')->find($row->id))->toBe($before);
    $row->refresh()->update(['description' => 'Owner note']);
    expect($row->fresh()->expense_direction_id)->toBe(44)->and($row->fresh()->expense_type_id)->toBe(88);
});

test('manual Owner choice and duplicate reimport preserve classification and all expense profit and movement totals', function () {
    $row = importFutureBank('manual');
    $ledger = app(AccountingLedger::class);
    $before = $ledger->pnlTotals(null, null)->toJson();
    $movements = $ledger->movementTotals(null, null)->toJson();
    expect((float) $ledger->pnlTotals(null, null)->sole()->expenses)->toBe(100.0)
        ->and((float) $ledger->pnlTotals(null, null)->sole()->profit)->toBe(-100.0);
    Livewire::test(Bank::class)->call('showTransaction', $row->id)->set('expenseDirectionId', 44)
        ->set('expenseTypeId', 88)->call('saveInlineClassification')->assertHasNoErrors();
    importFutureBank('manual');
    expect($row->fresh()->expense_direction_id)->toBe(44)->and($row->fresh()->expense_type_id)->toBe(88)
        ->and($row->fresh()->classification_source)->toBe('manual')->and(BankTransaction::count())->toBe(1)
        ->and(BankCategorizationRule::count())->toBe(0)
        ->and($ledger->pnlTotals(null, null)->toJson())->toBe($before)
        ->and($ledger->movementTotals(null, null)->toJson())->toBe($movements);
    $other = importFutureBank('same-vendor-without-remember');
    expect($other->expense_direction_id)->toBeNull()->and($other->expense_type_id)->toBeNull();
});

test('explicit remember creates vendor memory and can apply it to previously unclassified payments', function () {
    $old = importFutureBank('remember-old');
    $row = importFutureBank('remember-owner');
    app(BankExpenseAssignment::class)->assign($row->id, ['expense_direction_id' => 44, 'expense_type_id' => 88,
        'remember' => true, 'confirm_company_default' => true, 'apply_existing' => true], auth()->user());
    $rule = BankCategorizationRule::sole();
    $future = importFutureBank('remember-future');
    expect($old->fresh()->categorization_rule_id)->toBe($rule->id)->and($old->fresh()->expense_type_id)->toBe(88)
        ->and($future->classification_source)->toBe('rule')->and($future->categorization_rule_id)->toBe($rule->id)
        ->and($future->expense_direction_id)->toBe(44)->and($future->expense_type_id)->toBe(88);
    $unrelated = importFutureBank('unrelated', ['counterparty_name' => 'Different supplier']);
    expect($unrelated->expense_direction_id)->toBeNull()->and($unrelated->expense_type_id)->toBeNull();
});
