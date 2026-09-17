<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('catalog_status', 20)->default('sellable')->index();
        });
        // A sale or an explicitly configured sale price is evidence of retail use.
        // Preserve ambiguous old records for review, including all their old links.
        DB::table('products')->where('selling_price', '<=', 0)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('product_sale_items')
                ->whereColumn('product_sale_items.product_id', 'products.id'))
            ->update(['catalog_status' => 'review']);

        Schema::create('purchase_products', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('identity_key', 64)->unique();
            $table->string('name');
            $table->string('normalized_name')->index();
            $table->string('rs_product_code')->nullable();
            $table->string('supplier_product_code')->nullable();
            $table->foreignId('expense_direction_id')->nullable()->constrained('expense_categories')->restrictOnDelete();
            $table->timestamps();
        });
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->unsignedBigInteger('product_id')->nullable()->change();
            $table->foreignId('purchase_product_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('item_name')->nullable();
        });

        DB::table('purchase_items as i')->join('purchases as p', 'p.id', '=', 'i.purchase_id')
            ->join('products as product', 'product.id', '=', 'i.product_id')
            ->select('i.id', 'p.supplier_id', 'product.name')->orderBy('i.id')
            ->chunkById(250, function ($items): void {
                foreach ($items as $item) {
                    $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $item->name)));
                    $identity = hash('sha256', $item->supplier_id.'|name|'.$normalized);
                    DB::table('purchase_products')->insertOrIgnore([
                        'supplier_id' => $item->supplier_id, 'identity_key' => $identity,
                        'name' => $item->name, 'normalized_name' => $normalized,
                        // Previous keyword-based guesses are not confirmed Direction mappings.
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('purchase_items')->where('id', $item->id)->update([
                        'purchase_product_id' => DB::table('purchase_products')->where('identity_key', $identity)->value('id'),
                        'item_name' => $item->name,
                    ]);
                }
            }, 'i.id', 'id');
    }

    public function down(): void
    {
        // Rolling back after imports would discard the only identity/classification of new lines.
        if (DB::table('purchase_products')->exists()) {
            throw new RuntimeException('Purchase catalog contains data. Export and reconcile it before rolling back this migration.');
        }
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('purchase_product_id');
            $table->dropColumn('item_name');
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
        });
        Schema::dropIfExists('purchase_products');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropIndex(['catalog_status']);
            $table->dropColumn('catalog_status');
        });
    }
};
