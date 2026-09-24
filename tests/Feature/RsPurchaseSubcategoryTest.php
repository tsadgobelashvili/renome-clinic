<?php

use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Filament\Resources\Purchases\Pages\PurchaseItems;
use App\Filament\Resources\Purchases\Pages\UncategorizedProducts;
use App\Models\ExpenseCategory;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->catalog = app(PurchaseCatalog::class);
    $this->surgery = app(ExpenseDimensions::class)->id('direction', 'surgery');
    $this->therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
});

test('RS classification filters children and rejects a child of another category', function () {
    $supplier = Supplier::create(['name' => 'Supplier']);
    $product = $this->catalog->resolve($supplier->id, 'Material');
    $child = $this->catalog->createSubcategory($this->surgery, 'Surgical material');
    expect($this->catalog->subcategoryOptions($this->surgery))->toHaveKey($child)
        ->and($this->catalog->subcategoryOptions($this->therapy))->not->toHaveKey($child)
        ->and($this->catalog->subcategoryOptions(null))->toBe([]);
    expect(fn () => $this->catalog->assignClassification($product, $this->therapy, $child))->toThrow(ValidationException::class);
    expect(fn () => $this->catalog->assignClassification($product, null, $child))->toThrow(ValidationException::class);
    $this->catalog->assignClassification($product, $this->surgery, $child);
    expect(ExpenseCategory::findOrFail($child)->isUsed())->toBeTrue();
    $this->catalog->assignDirection($product, $this->surgery);
    expect($product->fresh()->expense_type_id)->toBe($child);
    $this->catalog->assignDirection($product, $this->therapy);
    expect($product->fresh()->expense_direction_id)->toBe($this->therapy)->and($product->fresh()->expense_type_id)->toBeNull();
});

test('RS category and subcategory mappings retain all existing supplier scoped identity rules', function (?string $rs, ?string $supplierCode) {
    $supplier = Supplier::create(['name' => 'Supplier']);
    $other = Supplier::create(['name' => 'Other supplier']);
    $product = $this->catalog->resolve($supplier->id, ' Test Material ', $rs, $supplierCode);
    $child = $this->catalog->createSubcategory($this->surgery, 'Material');
    $this->catalog->assignClassification($product, $this->surgery, $child);
    $again = $this->catalog->resolve($supplier->id, 'test material', $rs, $supplierCode);
    expect($again->id)->toBe($product->id)->and($again->expense_type_id)->toBe($child)
        ->and($again->expense_direction_id)->toBe($this->surgery)
        ->and($this->catalog->resolve($other->id, 'test material', $rs, $supplierCode)->expense_type_id)->toBeNull();
})->with([['RS-1', 'SUP-1'], [null, 'SUP-1'], [null, null]]);

test('inline RS subcategory creation selects it and future imports reuse the pair without financial writes', function () {
    $tables = ['finance_transactions', 'partner_finance_transactions', 'cashbox_transactions', 'bank_transactions', 'payments'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $snapshot();
    $fixture = base_path('tests/fixtures/rs-items-georgian.csv');
    app(PurchaseImportService::class)->import($fixture);
    $line = PurchaseItem::firstOrFail();
    Livewire::test(PurchaseItems::class)
        ->call('updateTableColumnState', 'direction', (string) $line->id, (string) $this->surgery)
        ->callTableAction('createSubcategory', $line, data: ['name' => 'RS inline child'])->assertHasNoTableActionErrors();
    $product = $line->purchaseProduct->fresh();
    $child = $product->expense_type_id;
    expect($child)->not->toBeNull()->and($product->subcategory->parent_id)->toBe($this->surgery);
    Livewire::test(UncategorizedProducts::class)
        ->call('updateTableColumnState', 'subcategory', (string) $product->id, null);
    expect($product->fresh()->expense_type_id)->toBeNull();
    Livewire::test(PurchaseItems::class)->call('updateTableColumnState', 'subcategory', (string) $line->id, (string) $child);
    $path = tempnam(sys_get_temp_dir(), 'rs-subcategory');
    rename($path, $path .= '.csv');
    try {
        file_put_contents($path, str_replace('RS-TEST-1', 'RS-SUBCATEGORY', file_get_contents($fixture)));
        app(PurchaseImportService::class)->import($path);
        $next = Purchase::where('document_number', 'RS-SUBCATEGORY')->firstOrFail();
        $nextLine = $next->items()->where('purchase_product_id', $product->id)->firstOrFail();
        expect($nextLine->purchaseProduct->expense_type_id)->toBe($child)
            ->and($nextLine->purchaseProduct->expense_direction_id)->toBe($this->surgery)
            ->and(PurchaseProduct::count())->toBe(3);
    } finally {
        unlink($path);
    }
    expect($snapshot())->toBe($before);
});

test('RS edit form creates and synchronizes subcategories but only saves the mapping on submit', function () {
    app(PurchaseImportService::class)->import(base_path('tests/fixtures/rs-items-georgian.csv'));
    $line = PurchaseItem::firstOrFail();
    $this->catalog->assignDirection($line->purchaseProduct, $this->surgery);
    $second = $line->purchase->items()->create(['purchase_product_id' => $line->purchase_product_id, 'quantity' => 1, 'unit_price' => 15]);
    $page = Livewire::test(EditPurchase::class, ['record' => $line->purchase_id])
        ->callFormComponentAction('items.record-'.$line->id.'.expense_type_id', 'createOption', ['name' => 'Form child'])
        ->assertHasNoFormErrors();
    $child = ExpenseCategory::where('name', 'Form child')->firstOrFail()->id;
    expect((int) $page->get('data.items.record-'.$line->id.'.expense_type_id'))->toBe($child)
        ->and((int) $page->get('data.items.record-'.$second->id.'.expense_type_id'))->toBe($child)
        ->and($line->purchaseProduct->fresh()->expense_type_id)->toBeNull();
    $page->call('save')->assertHasNoFormErrors();
    expect($line->purchaseProduct->fresh()->expense_type_id)->toBe($child);
    $page = Livewire::test(EditPurchase::class, ['record' => $line->purchase_id]);
    $page->call('save')->assertHasNoFormErrors();
    expect($line->purchaseProduct->fresh()->expense_type_id)->toBe($child);
    $page->set('data.items.record-'.$line->id.'.expense_direction_id', $this->therapy);
    expect($page->get('data.items.record-'.$second->id.'.expense_type_id'))->toBeNull();
    $page->call('save')->assertHasNoFormErrors();
    expect($line->purchaseProduct->fresh()->expense_type_id)->toBeNull()
        ->and($line->purchaseProduct->fresh()->expense_direction_id)->toBe($this->therapy);
});
