<?php

use App\Filament\Resources\ProductMaterials\Pages\ListProductMaterials;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Models\CashboxTransaction;
use App\Models\FinanceTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

test('required purchase categories exist and unknown products default to needs review', function () {
    expect(ProductCategory::query()->pluck('name')->all())->toContain(
        'Surgery', 'Orthopedics', 'Therapy', 'General Consumables', 'Office',
        'Laboratory', 'Sterilization', 'Other', 'Needs Review',
    );

    $product = Product::create(['name' => 'Unknown material', 'selling_price' => 0, 'is_active' => true]);

    expect($product->category->slug)->toBe(ProductCategory::NEEDS_REVIEW_SLUG);
});

test('manual purchase stores reusable supplier product quantities prices and calculated total', function () {
    $supplier = Supplier::create(['name' => 'Dental Supplier', 'tax_id' => '123456789']);
    $product = Product::create([
        'name' => 'Implant fixture',
        'product_category_id' => ProductCategory::query()->where('slug', 'surgery')->value('id'),
        'selling_price' => 0,
        'is_active' => true,
    ]);
    $purchase = Purchase::create([
        'purchase_date' => '2026-08-30',
        'supplier_id' => $supplier->id,
        'document_number' => 'INV-100',
        'notes' => 'RS-ready structured purchase',
    ]);
    $item = $purchase->items()->create(['product_id' => $product->id, 'quantity' => 3, 'unit_price' => 125.50]);

    expect((float) $item->line_total)->toBe(376.5)
        ->and((float) $purchase->fresh()->total_amount)->toBe(376.5)
        ->and($purchase->supplier->is($supplier))->toBeTrue()
        ->and($item->product->is($product))->toBeTrue()
        ->and($item->product->category->slug)->toBe('surgery');
});

test('purchases stay separate from cashier finance transactions and direct expenses', function () {
    $supplier = Supplier::create(['name' => 'Independent Supplier']);
    $product = Product::create(['name' => 'Gloves', 'selling_price' => 0, 'is_active' => true]);
    $purchase = Purchase::create(['purchase_date' => today(), 'supplier_id' => $supplier->id]);
    $purchase->items()->create(['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 2]);

    expect(CashboxTransaction::query()->count())->toBe(0)
        ->and(FinanceTransaction::query()->count())->toBe(0)
        ->and($purchase->fresh()->items)->toHaveCount(1);
});

test('product category remains editable without duplicating the shared product', function () {
    $product = Product::create(['name' => 'Shared catalog item', 'selling_price' => 10, 'is_active' => true]);
    $therapy = ProductCategory::query()->where('slug', 'therapy')->firstOrFail();
    $product->update(['product_category_id' => $therapy->id]);

    expect($product->fresh()->category->is($therapy))->toBeTrue()
        ->and(Product::query()->where('name', 'Shared catalog item')->count())->toBe(1);
});

test('rs csv import preserves structured values categorizes conservatively and skips repeated file rows', function () {
    $path = tempnam(sys_get_temp_dir(), 'rs-purchase-').'.csv';
    file_put_contents($path, implode("\n", [
        'Date,Supplier,Product,Quantity,Unit,Unit Price,Total Amount,VAT,Invoice Number',
        '30.08.2026,RS Supplier,Implant fixture,2,pcs,100,200,30,RS-100',
        '30.08.2026,RS Supplier,Nitrile gloves,5,box,12,60,9,RS-100',
        '30.08.2026,RS Supplier,Mystery dental item,1,pcs,25,25,3.75,RS-100',
    ]));

    try {
        $service = app(PurchaseImportService::class);
        $first = $service->import($path);
        $second = $service->import($path);
    } finally {
        @unlink($path);
    }

    expect($first)->toMatchArray(['imported' => 3, 'skipped' => 0, 'needs_review' => 1, 'errors' => []])
        ->and($second)->toMatchArray(['imported' => 0, 'skipped' => 3, 'needs_review' => 0, 'errors' => []])
        ->and(Purchase::query()->count())->toBe(1)
        ->and(Supplier::query()->count())->toBe(1)
        ->and(Product::query()->where('name', 'Implant fixture')->firstOrFail()->category->slug)->toBe('surgery')
        ->and(Product::query()->where('name', 'Nitrile gloves')->firstOrFail()->category->slug)->toBe('general-consumables')
        ->and(Product::query()->where('name', 'Mystery dental item')->firstOrFail()->category->slug)->toBe(ProductCategory::NEEDS_REVIEW_SLUG);

    $item = Purchase::first()->items()->whereHas('product', fn ($query) => $query->where('name', 'Implant fixture'))->firstOrFail();
    expect($item->unit)->toBe('pcs')->and((float) $item->vat_amount)->toBe(30.0)->and((float) $item->line_total)->toBe(200.0);
});

test('saved product mapping is reused and xlsx rows import through the same flow', function () {
    $therapy = ProductCategory::query()->where('slug', 'therapy')->firstOrFail();
    Product::create(['name' => 'Known Resin', 'product_category_id' => $therapy->id, 'selling_price' => 0, 'is_active' => true]);
    $path = tempnam(sys_get_temp_dir(), 'rs-purchase-').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Date', 'Supplier', 'Product', 'Quantity', 'Unit', 'Unit Price', 'Total Amount', 'Invoice Number']));
    $writer->addRow(Row::fromValues(['2026-08-30', 'Excel Supplier', '  KNOWN   RESIN ', 2, 'pcs', 15, 30, 'XLSX-1']));
    $writer->close();

    try {
        $summary = app(PurchaseImportService::class)->import($path);
    } finally {
        @unlink($path);
    }

    expect($summary)->toMatchArray(['imported' => 1, 'skipped' => 0, 'needs_review' => 0, 'errors' => []])
        ->and(Product::query()->where('normalized_name', Product::normalizeName('Known Resin'))->count())->toBe(1)
        ->and(Product::query()->where('normalized_name', Product::normalizeName('Known Resin'))->firstOrFail()->category->slug)->toBe('therapy');
});

test('purchase and product catalog filament pages render', function () {
    $user = User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]);

    Livewire::actingAs($user)->test(ListPurchases::class)->assertSuccessful();
    Livewire::actingAs($user)->test(CreatePurchase::class)->assertSuccessful();
    Livewire::actingAs($user)->test(ListProductMaterials::class)->assertSuccessful();
});
