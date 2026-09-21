<?php

use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Services\PurchaseCatalog;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Exercise SQLite's table rebuild outside RefreshDatabase's outer transaction,
// as Laravel's real SQLite migrator does. Foreign key enforcement stays enabled.
uses(DatabaseTruncation::class);

test('product group schema migration preserves purchasing rows through rollback and reapply', function () {
    $supplier = Supplier::create(['name' => 'Migration supplier']);
    $product = app(PurchaseCatalog::class)->resolve($supplier->id, 'Material');
    $purchase = Purchase::create(['supplier_id' => $supplier->id, 'source' => 'rs', 'purchase_date' => today()]);
    $purchase->items()->create(['purchase_product_id' => $product->id, 'quantity' => 2, 'unit_price' => 10]);
    $before = DB::table('purchase_items')->orderBy('id')->get()->toJson();
    $identities = PurchaseProduct::orderBy('id')->pluck('identity_key', 'id')->all();
    $migration = require database_path('migrations/2026_09_19_120000_add_purchase_product_groups.php');
    $migration->down();
    expect(Schema::hasColumn('purchase_products', 'purchase_product_group_id'))->toBeFalse();
    $migration->up();
    expect(DB::table('purchase_items')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(PurchaseProduct::orderBy('id')->pluck('identity_key', 'id')->all())->toBe($identities)
        ->and((int) DB::scalar('PRAGMA foreign_keys'))->toBe(1);
});
