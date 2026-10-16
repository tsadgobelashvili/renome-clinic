<?php

use App\Filament\Pages\Finance;
use App\Filament\Resources\Purchases\Pages\PurchaseProductGroups;
use App\Filament\Resources\Purchases\Pages\PurchaseProducts;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\CashboxTransaction;
use App\Models\ExpenseCategory;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use App\Models\User;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\FinanceExpenseEntry;
use App\Services\FinanceManager;
use App\Services\PurchaseCashPayment;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseExpenseAllocation;
use App\Services\PurchaseImportService;
use App\Support\CashboxManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-16 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'en']));
    $this->dimensions = app(ExpenseDimensions::class);
    $this->catalog = app(PurchaseCatalog::class);
    $this->entry = app(FinanceExpenseEntry::class);
    $this->ledger = app(AccountingLedger::class);
});

function entryProduct(): PurchaseProduct
{
    $group = PurchaseProductGroup::firstOrCreate(['name' => 'Filling material']);

    return app(PurchaseCatalog::class)->createManual(['name' => 'Estelite', 'purchase_product_group_id' => $group->id,
        'expense_direction_id' => app(ExpenseDimensions::class)->id('direction', 'therapy')]);
}

function entryData(string $type, array $extra = []): array
{
    return array_replace(['entry_expense_type_id' => app(ExpenseDimensions::class)->id('type', $type),
        'transaction_date' => now(), 'amount' => 300, 'currency' => 'GEL', 'payment_method' => 'bank_transfer'], $extra);
}

function entryImport(string $document, string $name = 'Estelite'): Purchase
{
    $path = tempnam(sys_get_temp_dir(), 'entry-rs');
    rename($path, $path .= '.csv');
    $file = fopen($path, 'w');
    fputcsv($file, ['Date', 'Supplier', 'Product', 'RS Product Code', 'Quantity', 'Unit Price', 'Total', 'Document', 'Unit'], escape: '');
    fputcsv($file, ['2026-10-16', 'Dental Supplier', $name, 'E1', 3, 100, 300, $document, 'pcs'], escape: '');
    fclose($file);
    try {
        expect(app(PurchaseImportService::class)->import($path)['errors'])->toBeEmpty();
    } finally {
        unlink($path);
    }

    return Purchase::where('document_number', $document)->sole();
}

test('Owner creates an independent expense type inline without moving money', function () {
    $before = $this->ledger->pnlTotals(null, null)->toArray();
    Livewire::test(Finance::class)->mountAction('add_expense')
        ->callAction(TestAction::make('createOption')->schemaComponent('entry_expense_type_id'), ['name' => 'Plumber repairs'])
        ->assertHasNoActionErrors();
    $type = ExpenseCategory::where('name', 'Plumber repairs')->sole();
    expect($type->classification_dimension)->toBe('type')->and($type->parent_id)->toBeNull()
        ->and($this->entry->createType('Plumber repairs'))->toBe($type->id)
        ->and(FinanceTransaction::count())->toBe(0)->and(PartnerFinanceTransaction::count())->toBe(0)
        ->and(CashboxTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(0)
        ->and($this->ledger->pnlTotals(null, null)->toArray())->toEqual($before);
});

test('Rent and Service require no product and default to General', function (string $type) {
    Livewire::test(Finance::class)->mountAction('add_expense')->fillForm(entryData($type, ['description' => 'Business cost']))
        ->assertFormFieldIsHidden('purchase_product_id')->assertFormFieldIsHidden('purchase_product_group_id')
        ->callMountedAction()->assertHasNoActionErrors();
    $expense = FinanceTransaction::sole();
    expect($expense->expense_direction_id)->toBe($this->dimensions->id('direction', 'general'))
        ->and($expense->expenseType->classification_code)->toBe($type)
        ->and($expense->purchase_product_id)->toBeNull()->and($expense->purchase_product_group_id)->toBeNull()
        ->and((float) $expense->amount)->toBe(300.0);
})->with(['rent', 'services']);

test('product selection populates shared group and direction and posts one expense', function () {
    $product = entryProduct();
    Livewire::test(Finance::class)->mountAction('add_expense')
        ->fillForm(entryData('materials'))->assertFormFieldIsVisible('purchase_product_id')
        ->set('mountedActions.0.data.purchase_product_id', $product->id)
        ->assertSet('mountedActions.0.data.purchase_product_group_id', $product->purchase_product_group_id)
        ->assertSet('mountedActions.0.data.expense_direction_id', $product->expense_direction_id)
        ->callMountedAction()->assertHasNoActionErrors();
    $expense = FinanceTransaction::sole();
    expect($expense->purchase_product_id)->toBe($product->id)
        ->and($expense->purchase_product_group_id)->toBe($product->purchase_product_group_id)
        ->and($expense->expense_direction_id)->toBe($product->expense_direction_id)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe(300.0);
    Livewire::test(PurchaseProducts::class)->assertCanSeeTableRecords([$product]);
    $this->get(PurchaseResource::getUrl('catalog'))->assertOk()->assertSee('Estelite');
});

test('product creation inline is catalog only and returns populated classification', function () {
    $direction = $this->dimensions->id('direction', 'therapy');
    $group = PurchaseProductGroup::create(['name' => 'Filling material', 'expense_direction_id' => $direction]);
    Livewire::test(Finance::class)->mountAction('add_expense')->fillForm(entryData('materials'))
        ->callAction(TestAction::make('createOption')->schemaComponent('purchase_product_id'), [
            'name' => 'Estelite', 'expense_direction_id' => $direction, 'purchase_product_group_id' => $group->id,
        ])->assertHasNoActionErrors()
        ->assertSet('mountedActions.0.data.purchase_product_group_id', $group->id)
        ->assertSet('mountedActions.0.data.expense_direction_id', $direction);
    expect(PurchaseProduct::sole()->supplier_id)->toBeNull()
        ->and(FinanceTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
});

test('cash product purchase deducts existing withdrawn cash exactly once', function () {
    FinanceOpeningBalance::create(['source' => 'cash', 'account_identifier' => 'cash', 'currency' => 'GEL', 'effective_date' => today(), 'amount' => 1000]);
    $product = entryProduct();
    $before = app(CashboxManager::class)->physicalCashBalances();
    Livewire::test(Finance::class)->callAction('add_expense', entryData('materials', [
        'purchase_product_id' => $product->id, 'expense_direction_id' => $product->expense_direction_id,
        'payment_method' => 'cash', 'cash_source' => 'withdrawn_cash',
    ]))->assertHasNoActionErrors();
    expect(FinanceTransaction::count())->toBe(1)
        ->and(app(CashboxManager::class)->physicalCashBalances()['GEL'])->toBe($before['GEL'] - 300)
        ->and(FinanceTransaction::sole()->cash_source)->toBe('withdrawn_cash')
        ->and(CashboxTransaction::count())->toBe(0)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe(300.0);
});

test('bank product expense follows the existing Finance bank path without creating a second bank debit', function () {
    $product = entryProduct();
    $plain = app(FinanceManager::class)->create(['type' => 'expense', 'category' => 'materials',
        'transaction_date' => now(), 'amount' => 300, 'currency' => 'GEL', 'payment_method' => 'bank_transfer']);
    $before = app(CashboxManager::class)->physicalCashBalances();
    Livewire::test(Finance::class)->callAction('add_expense', entryData('materials', ['purchase_product_id' => $product->id]))->assertHasNoActionErrors();
    $expense = FinanceTransaction::where('id', '!=', $plain->id)->sole();
    expect($expense->payment_method)->toBe($plain->payment_method)->and($expense->cash_source)->toBeNull()
        ->and(BankTransaction::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0)
        ->and(app(CashboxManager::class)->physicalCashBalances())->toEqual($before)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe(600.0);
});

test('ordinary types discard forged or stale product classification', function () {
    $product = entryProduct();
    $data = $this->entry->prepare(entryData('rent', ['purchase_product_id' => $product->id, 'purchase_product_group_id' => $product->purchase_product_group_id]));
    expect($data['purchase_product_id'])->toBeNull()->and($data['purchase_product_group_id'])->toBeNull();
});

test('catalog metadata changes preserve historical expense snapshots and totals', function () {
    $product = entryProduct();
    $expense = app(FinanceManager::class)->create($this->entry->prepare(entryData('materials', [
        'purchase_product_id' => $product->id, 'expense_direction_id' => $product->expense_direction_id,
    ])) + ['type' => 'expense']);
    $historical = $expense->fresh()->getRawOriginal();
    $totals = $this->ledger->pnlTotals(null, null)->toArray();
    $this->catalog->assignGroup($product, PurchaseProductGroup::create(['name' => 'Different group'])->id);
    $this->catalog->assignDirection($product, $this->dimensions->id('direction', 'surgery'));
    expect($expense->fresh()->getRawOriginal())->toEqual($historical)
        ->and($this->ledger->pnlTotals(null, null)->toArray())->toEqual($totals);
    foreach (['direction', 'type', 'product_group', 'product'] as $grouping) {
        expect((float) $this->ledger->dimensionEntries(null, null, grouping: $grouping)->sum('amount'))->toBe(300.0);
    }
    expect($this->ledger->dimensionEntries(null, null, grouping: 'product_group')->sole()->dimension_name)->toBe('Filling material');
});

test('manual and RS products share the catalog and preserve exact RS raw data', function () {
    $product = entryProduct();
    $purchase = entryImport('SHARED-1');
    $item = $purchase->items()->sole();
    expect($item->purchase_product_id)->toBe($product->id)->and($item->item_name)->toBe('Estelite')
        ->and(PurchaseProduct::count())->toBe(1)->and($this->catalog->productDirection($item->purchaseProduct))->toBe($product->expense_direction_id);
    $raw = $purchase->rs_import_payload;
    $this->catalog->assignGroup($product, PurchaseProductGroup::create(['name' => 'Another group'])->id);
    expect($purchase->fresh()->rs_import_payload)->toEqual($raw);
    $later = entryImport('SHARED-2');
    expect($later->items()->sole()->purchase_product_id)->toBe($product->id)
        ->and($later->items()->sole()->purchaseProduct->purchase_product_group_id)->toBe($product->purchase_product_group_id);
    $renamed = entryImport('SHARED-3', 'RS changed description');
    expect($renamed->items()->sole()->purchase_product_id)->toBe($product->id)
        ->and($renamed->items()->sole()->item_name)->toBe('RS changed description')
        ->and($product->fresh()->name)->toBe('Estelite');
});

test('RS-derived products are selectable for manual purchases without duplicates', function () {
    $purchase = entryImport('RS-FIRST');
    $product = $purchase->items()->sole()->purchaseProduct;
    $this->catalog->assignGroup($product, PurchaseProductGroup::create(['name' => 'Filling material'])->id);
    $this->catalog->assignDirection($product, $this->dimensions->id('direction', 'therapy'));
    Livewire::test(Finance::class)->callAction('add_expense', entryData('materials', ['purchase_product_id' => $product->id]))->assertHasNoActionErrors();
    expect(PurchaseProduct::count())->toBe(1)->and(FinanceTransaction::sole()->purchase_product_id)->toBe($product->id);
});

test('confirmed RS rows and accounting are not rewritten by catalog or type creation', function () {
    $product = entryProduct();
    $purchase = entryImport('CONFIRMED');
    FinanceOpeningBalance::create(['source' => 'cash', 'account_identifier' => 'cash', 'currency' => 'GEL', 'effective_date' => today(), 'amount' => 1000]);
    $posting = app(PurchaseCashPayment::class)->post($purchase->id, auth()->user());
    $original = [$purchase->fresh()->getRawOriginal(), $purchase->items()->sole()->getRawOriginal(), $posting->fresh()->getRawOriginal()];
    $totals = $this->ledger->pnlTotals(null, null)->toArray();
    $this->entry->createType('New service type');
    $this->catalog->assignDirection($product, $this->dimensions->id('direction', 'surgery'));
    expect([$purchase->fresh()->getRawOriginal(), $purchase->items()->sole()->getRawOriginal(), $posting->fresh()->getRawOriginal()])->toEqual($original)
        ->and($this->ledger->pnlTotals(null, null)->toArray())->toEqual($totals)
        ->and((float) $this->ledger->dimensionEntries(null, null, grouping: 'product_group')->sum('amount'))->toBe(300.0);
});

test('a group resolves one direction without overwriting product overrides', function () {
    $product = entryProduct();
    $other = $this->catalog->createManual(['name' => 'Filtek', 'purchase_product_group_id' => $product->purchase_product_group_id]);
    expect($other->expense_direction_id)->toBe($product->expense_direction_id);
    $this->catalog->assignDirection($other, $this->dimensions->id('direction', 'surgery'));
    expect($this->catalog->groupDirection($product->purchase_product_group_id))->toBeNull()
        ->and($product->fresh()->expense_direction_id)->toBe($this->dimensions->id('direction', 'therapy'));
});

test('non Owners cannot create products or types or access the new shared catalog', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    Livewire::test(Finance::class)->assertForbidden();
    Livewire::test(PurchaseProducts::class)->assertForbidden();
    expect(fn () => $this->entry->createType('Unauthorized'))->toThrow(HttpException::class);
    expect(fn () => $this->catalog->createManual(['name' => 'Unauthorized']))->toThrow(AuthorizationException::class);
})->with([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('new catalog grouping retains an unmatched bank residual and the original debit total', function () {
    $product = entryProduct();
    $purchase = entryImport('BANK-CATALOG');
    $bank = BankTransaction::create(['transaction_date' => today(), 'direction' => 'outflow', 'amount' => 310, 'currency' => 'GEL',
        'operation_type' => 'PMD', 'source' => 'api', 'bank_category_id' => BankCategory::where('code', 'uncategorized')->value('id'),
        'fingerprint' => str_repeat('a', 64), 'deduplication_key' => str_repeat('b', 64)]);
    app(BankPurchaseMatching::class)->confirm($bank->id, [['purchase_id' => $purchase->id, 'amount' => 300]], auth()->user());
    $original = $bank->fresh()->getRawOriginal();
    foreach (['product_group', 'product'] as $grouping) {
        $entries = $this->ledger->dimensionEntries(null, null, source: 'bank', grouping: $grouping);
        expect((float) (clone $entries)->sum('amount'))->toBe(310.0)
            ->and((float) (clone $entries)->where('dimension_group', 'review')->sum('amount'))->toBe(10.0);
    }
    expect($bank->fresh()->getRawOriginal())->toEqual($original)->and(BankTransaction::count())->toBe(1)
        ->and(FinanceTransaction::count())->toBe(0)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe(310.0);
});

test('catalog drilldown allocates fractional RS shares to cents without multiplying totals', function () {
    $purchase = entryImport('ROUNDING');
    $first = $purchase->items()->sole();
    $first->update(['quantity' => 1, 'unit_price' => 1, 'line_total' => 1]);
    foreach (['Filtek', 'Charisma'] as $name) {
        $product = $this->catalog->resolve($purchase->supplier_id, $name);
        $purchase->items()->create(['purchase_product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1, 'line_total' => 1]);
    }
    $allocation = app(PurchaseExpenseAllocation::class)->shares(DB::query()->selectRaw('? AS id, ? AS entry_id, ? AS purchase_id, ? AS amount', [1, 1, $purchase->id, 1]), catalog: true)->get();
    expect($allocation)->toHaveCount(3)->and(round($allocation->sum('amount'), 2))->toBe(1.0)
        ->and($allocation->pluck('amount')->sort()->values()->all())->toEqual([0.33, 0.33, 0.34]);
});

test('existing custom child types stay selectable independently without moving historical categories', function () {
    $surgery = $this->dimensions->id('direction', 'surgery');
    $child = $this->catalog->createSubcategory($surgery, 'Special repair');
    expect($this->entry->typeOptions())->toHaveKey($child);
    $data = $this->entry->prepare(['entry_expense_type_id' => $child]);
    expect($data['expense_direction_id'])->toBe($this->dimensions->id('direction', 'general'))
        ->and(ExpenseCategory::find($child)->parent_id)->toBe($surgery)
        ->and(ExpenseCategory::find($data['expense_type_id'])->name)->toBe('Special repair')
        ->and(FinanceTransaction::count())->toBe(0);
});

test('unclassified stays a null state and cannot be created as a business group or type', function () {
    Livewire::test(PurchaseProductGroups::class)
        ->callTableAction('create', data: ['name' => 'Unclassified', 'expense_direction_id' => $this->dimensions->id('direction', 'general')])->assertHasTableActionErrors(['name']);
    expect(fn () => $this->entry->createType('Unclassified'))->toThrow(ValidationException::class);
    $product = $this->catalog->createManual(['name' => 'Unmapped manual material']);
    expect($product->purchase_product_group_id)->toBeNull()->and($product->expense_direction_id)->toBeNull()
        ->and(PurchaseProductGroup::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0);
});

test('old unclassified finance rows remain byte for byte unchanged and retain their report totals', function () {
    $id = DB::table('finance_transactions')->insertGetId(['type' => 'expense', 'category' => 'rent', 'transaction_date' => now(),
        'amount' => 333.33, 'currency' => 'GEL', 'payment_method' => 'bank_transfer', 'created_at' => now(), 'updated_at' => now()]);
    $raw = DB::table('finance_transactions')->where('id', $id)->first();
    $totals = $this->ledger->pnlTotals(null, null)->toArray();
    entryProduct();
    $this->entry->createType('New metadata only');
    foreach (['direction', 'type', 'product_group', 'product'] as $grouping) {
        expect((float) $this->ledger->dimensionEntries(null, null, grouping: $grouping)->sum('amount'))->toBe(333.33);
    }
    expect(DB::table('finance_transactions')->where('id', $id)->first())->toEqual($raw)
        ->and($this->ledger->pnlTotals(null, null)->toArray())->toEqual($totals);
});
