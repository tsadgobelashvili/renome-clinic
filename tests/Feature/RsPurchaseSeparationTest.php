<?php

use App\Filament\Resources\ProductMaterials\Pages\ListProductMaterials;
use App\Filament\Resources\ProductMaterials\ProductMaterialResource;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Pages\PurchaseItems;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\ProductSaleService;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function rsImportRows(array $rows): array
{
    $path = tempnam(sys_get_temp_dir(), 'rs-');
    $csv = $path.'.csv';
    rename($path, $csv);
    $handle = fopen($csv, 'w');
    fputcsv($handle, ['Date', 'Supplier', 'Product', 'Quantity', 'Unit Price', 'Total', 'Document', 'RS Product Code', 'Supplier Item Code'], escape: '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, escape: '');
    }
    fclose($handle);
    try {
        return app(PurchaseImportService::class)->import($csv);
    } finally {
        unlink($csv);
    }
}

function rsLine(string $name = 'Unmapped material', int $amount = 100): PurchaseItem
{
    $supplier = Supplier::firstOrCreate(['name' => 'RS Supplier']);
    $product = app(PurchaseCatalog::class)->resolve($supplier->id, $name);
    $purchase = Purchase::create(['supplier_id' => $supplier->id, 'purchase_date' => '2026-09-17', 'source' => 'rs']);

    return $purchase->items()->create(['purchase_product_id' => $product->id, 'quantity' => 1, 'unit_price' => $amount]);
}

test('products and RS navigation are separate and the default products list is sellable only', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $retail = Product::create(['name' => 'Floss', 'selling_price' => 15, 'is_active' => true]);
    $legacy = Product::create(['name' => 'Ambiguous legacy item', 'selling_price' => 0, 'catalog_status' => 'review']);
    rsLine();
    expect(ProductMaterialResource::getNavigationLabel())->toBe('პროდუქტები')
        ->and(PurchaseResource::getNavigationLabel())->toBe('RS / შესყიდვები');
    Livewire::test(ListProductMaterials::class)->assertSuccessful()->assertCanSeeTableRecords([$retail])->assertCanNotSeeTableRecords([$legacy])
        ->set('activeTab', 'review')->assertCanSeeTableRecords([$legacy])->assertCanNotSeeTableRecords([$retail]);
});

test('retail create action stores a simple sellable product and unreviewed legacy products cannot be sold', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    Livewire::test(ListProductMaterials::class)->callAction('create', data: ['name' => 'Retail floss', 'selling_price' => 15, 'is_active' => true])
        ->assertHasNoActionErrors();
    expect(Product::firstOrFail()->catalog_status)->toBe('sellable')->and(PurchaseProduct::count())->toBe(0);
    $legacy = Product::create(['name' => 'Needs confirmation', 'selling_price' => 20, 'catalog_status' => 'review', 'is_active' => true]);
    expect(fn () => app(ProductSaleService::class)->create([
        'items' => [['product_id' => $legacy->id, 'quantity' => 1]], 'payment_method' => 'cash',
    ]))->toThrow(ValidationException::class);
});

test('imports do not create financial movements or sellable products and exact reimports are safe', function () {
    $tables = ['products', 'finance_transactions', 'partner_finance_transactions', 'direct_expenses', 'cashbox_transactions', 'bank_transactions', 'payments', 'product_sales'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $rows = [['2026-09-17', 'RS Supplier', 'Implant material', 1, 100, 100, 'RS-1', '', '']];
    expect(rsImportRows($rows))->toMatchArray(['imported' => 1, 'needs_review' => 1, 'errors' => []]);
    expect(rsImportRows($rows))->toMatchArray(['imported' => 0, 'skipped' => 1, 'errors' => []]);
    $product = PurchaseProduct::firstOrFail();
    app(PurchaseCatalog::class)->assignDirection($product, app(ExpenseDimensions::class)->id('direction', 'surgery'));
    expect(collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all())->toBe($before)
        ->and(Purchase::count())->toBe(1)->and(PurchaseItem::count())->toBe(1)
        ->and(PurchaseItem::first()->product_id)->toBeNull();
});

test('inline direction classification is remembered for new imports and can be changed or cleared', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $line = rsLine('Composite');
    $therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    $page = Livewire::test(PurchaseItems::class)->assertCanSeeTableRecords([$line]);
    $page->call('updateTableColumnState', 'direction', (string) $line->id, (string) $therapy);
    expect($line->purchaseProduct->fresh()->expense_direction_id)->toBe($therapy);
    expect(rsImportRows([['2026-09-18', 'RS Supplier', '  COMPOSITE ', 2, 10, 20, 'RS-2', '', '']]))
        ->toMatchArray(['imported' => 1, 'needs_review' => 0, 'errors' => []]);
    expect(PurchaseProduct::count())->toBe(1)->and(PurchaseItem::latest('id')->first()->purchase_product_id)->toBe($line->purchase_product_id);
    $surgery = app(ExpenseDimensions::class)->id('direction', 'surgery');
    $page->call('updateTableColumnState', 'direction', (string) $line->id, (string) $surgery);
    expect($line->purchaseProduct->fresh()->expense_direction_id)->toBe($surgery);
    $page->call('updateTableColumnState', 'direction', (string) $line->id, null);
    expect($line->purchaseProduct->fresh()->expense_direction_id)->toBeNull();
});

test('stable codes take precedence over display names and mappings stay supplier scoped', function () {
    $catalog = app(PurchaseCatalog::class);
    $a = Supplier::create(['name' => 'Supplier A']);
    $b = Supplier::create(['name' => 'Supplier B']);
    $product = $catalog->resolve($a->id, 'Old name', 'RS001', 'S1');
    $catalog->assignDirection($product, app(ExpenseDimensions::class)->id('direction', 'laboratory'));
    expect($catalog->resolve($a->id, 'New display name', 'RS001', 'S2')->id)->toBe($product->id)
        ->and($catalog->resolve($b->id, 'Old name', 'RS001', 'S1')->expense_direction_id)->toBeNull()
        ->and($catalog->resolve($a->id, 'Old name', 'RS002', 'S1')->expense_direction_id)->toBeNull();
    $supplierCode = $catalog->resolve($a->id, 'Original', null, 'CODE99');
    expect($catalog->resolve($a->id, 'Renamed', null, 'CODE99')->id)->toBe($supplierCode->id);
});

test('actual code headers import with remembered direction and preserve each line display name', function () {
    $supplier = Supplier::create(['name' => 'Coded Supplier']);
    $product = app(PurchaseCatalog::class)->resolve($supplier->id, 'Old label', '123');
    app(PurchaseCatalog::class)->assignDirection($product, app(ExpenseDimensions::class)->id('direction', 'laboratory'));
    $result = rsImportRows([['2026-09-17', 'Coded Supplier', 'New label', 1, 300, 300, 'DOC', '123', '456']]);
    expect($result)->toMatchArray(['imported' => 1, 'needs_review' => 0, 'errors' => []])
        ->and(PurchaseItem::first()->item_name)->toBe('New label')
        ->and(PurchaseItem::first()->purchase_product_id)->toBe($product->id);
});

test('reimport of a legacy row still skips it when its original export contains a product code', function () {
    $supplier = Supplier::create(['name' => 'Legacy Supplier']);
    $product = Product::create(['name' => 'Legacy material', 'selling_price' => 0]);
    $purchase = Purchase::create(['supplier_id' => $supplier->id, 'purchase_date' => '2026-09-17', 'document_number' => 'OLD']);
    $hash = hash('sha256', implode('|', [$supplier->normalized_name, 'OLD', '2026-09-17', $product->normalized_name, 1, null, 100, 100, null]));
    $purchase->items()->create(['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'source_row_hash' => $hash]);
    expect(rsImportRows([['2026-09-17', 'Legacy Supplier', 'Legacy material', 1, 100, 100, 'OLD', 'CODE1', '']]))
        ->toMatchArray(['imported' => 0, 'skipped' => 1, 'errors' => []]);
    expect(PurchaseItem::count())->toBe(1)->and(Purchase::count())->toBe(1)->and(PurchaseItem::first()->source_row_hash)->toBe($hash);
});

test('uncategorized date supplier and direction filters combine on purchase lines', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $unknown = rsLine('Unmapped');
    $known = rsLine('Classified');
    $old = rsLine('Old');
    $old->purchase->update(['purchase_date' => '2026-01-01']);
    $therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    app(PurchaseCatalog::class)->assignDirection($known->purchaseProduct, $therapy);
    Livewire::test(PurchaseItems::class)->filterTable('date', ['from' => '2026-09-01', 'until' => '2026-09-30'])
        ->filterTable('supplier', $unknown->purchase->supplier_id)->filterTable('uncategorized')
        ->assertCanSeeTableRecords([$unknown])->assertCanNotSeeTableRecords([$known, $old]);
    Livewire::test(PurchaseItems::class)->filterTable('direction', $therapy)->searchTable('Classified')
        ->assertCanSeeTableRecords([$known])->assertCanNotSeeTableRecords([$unknown, $old]);
});

test('one document has an exact multi direction SQL breakdown without affecting Finance', function () {
    $supplier = Supplier::create(['name' => 'RS Supplier']);
    foreach (['Implants' => ['surgery', 1200], 'Composite' => ['therapy', 500], 'Lab material' => ['laboratory', 300]] as $name => [$direction, $amount]) {
        $product = app(PurchaseCatalog::class)->resolve($supplier->id, $name);
        app(PurchaseCatalog::class)->assignDirection($product, app(ExpenseDimensions::class)->id('direction', $direction));
        $rows[] = ['2026-09-17', 'RS Supplier', $name, 1, $amount, $amount, 'MULTI-1', '', ''];
    }
    rsImportRows($rows);
    $purchase = Purchase::firstOrFail();
    $breakdown = app(PurchaseCatalog::class)->breakdown($purchase->id);
    expect($breakdown)->toHaveCount(3)->and((float) $breakdown->sum('amount'))->toBe(2000.0)
        ->and((float) $purchase->total_amount)->toBe(2000.0);
    foreach (['surgery' => 1200, 'therapy' => 500, 'laboratory' => 300] as $direction => $amount) {
        expect((float) $breakdown->firstWhere('id', app(ExpenseDimensions::class)->id('direction', $direction))->amount)->toBe((float) $amount);
    }
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $page = Livewire::test(ListPurchases::class)->assertCanSeeTableRecords([$purchase])->mountTableAction('breakdown', $purchase)
        ->assertActionMounted(TestAction::make('breakdown')->table($purchase));
    expect($page->instance()->getMountedAction()->getModalContent()->render())->toContain('1,200.00', '500.00', '300.00');
});

test('manual purchase form creates purchasing lines without creating a sellable product', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $supplier = Supplier::create(['name' => 'Manual supplier']);
    $product = app(PurchaseCatalog::class)->resolve($supplier->id, 'Material');
    Livewire::test(CreatePurchase::class)->fillForm([
        'purchase_date' => '2026-09-17', 'supplier_id' => $supplier->id, 'document_number' => 'MANUAL',
        'items' => [['purchase_product_id' => $product->id, 'quantity' => 2, 'unit_price' => 25, 'line_total' => 50]],
    ])->call('create')->assertHasNoFormErrors();
    expect(PurchaseItem::first()->purchase_product_id)->toBe($product->id)
        ->and(PurchaseItem::first()->item_name)->toBe('Material')->and(Product::count())->toBe(0);
});

test('RS views preserve owner access restrictions', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
    Livewire::test(PurchaseItems::class)->assertForbidden();
})->with([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('purchase direction rejects an expense type and prevents deletion of a used direction', function () {
    $line = rsLine();
    expect(fn () => app(PurchaseCatalog::class)->assignDirection($line->purchaseProduct, app(ExpenseDimensions::class)->id('type', 'materials')))
        ->toThrow(ValidationException::class);
    app(PurchaseCatalog::class)->assignDirection($line->purchaseProduct, app(ExpenseDimensions::class)->id('direction', 'therapy'));
    expect($line->purchaseProduct->fresh()->direction->isUsed())->toBeTrue();
    expect(fn () => $line->purchaseProduct->fresh()->direction->delete())->toThrow(ValidationException::class);
});

test('separation migration preserves legacy records original foreign keys totals and duplicate hashes', function () {
    // Return only this empty test schema to the pre-separation shape.
    $migration = require database_path('migrations/2026_09_17_180000_separate_purchase_catalog_from_sellable_products.php');
    $migration->down();
    $supplier = Supplier::create(['name' => 'Legacy Supplier']);
    $legacy = Product::create(['name' => 'Uncertain material', 'selling_price' => 0, 'is_active' => true]);
    $retail = Product::create(['name' => 'Retail brush', 'selling_price' => 10, 'is_active' => true]);
    $purchase = Purchase::create(['supplier_id' => $supplier->id, 'purchase_date' => '2026-09-01']);
    $item = $purchase->items()->create(['product_id' => $legacy->id, 'quantity' => 2, 'unit_price' => 12, 'source_row_hash' => str_repeat('a', 64)]);
    $before = (array) DB::table('purchase_items')->where('id', $item->id)->first();
    $migration->up();
    expect(Product::count())->toBe(2)->and(Purchase::count())->toBe(1)->and(PurchaseItem::count())->toBe(1)
        ->and((array) DB::table('purchase_items')->where('id', $item->id)->first(array_keys($before)))->toBe($before)
        ->and($legacy->fresh()->catalog_status)->toBe('review')->and($retail->fresh()->catalog_status)->toBe('sellable')
        ->and($item->fresh()->purchaseProduct->name)->toBe($legacy->name)
        ->and($item->fresh()->purchaseProduct->expense_direction_id)->toBeNull()
        ->and((float) $purchase->fresh()->total_amount)->toBe(24.0);
});
