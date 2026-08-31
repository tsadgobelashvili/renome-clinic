<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'implantology' => ['Surgery', 'surgery'],
            'orthopedics' => ['Orthopedics', 'orthopedics'],
            'therapy' => ['Therapy', 'therapy'],
            'disposables' => ['General Consumables', 'general-consumables'],
            'office' => ['Office', 'office'],
            'lab' => ['Laboratory', 'laboratory'],
            'sterilization' => ['Sterilization', 'sterilization'],
            'other' => ['Other', 'other'],
            'uncategorized' => ['Needs Review', 'needs-review'],
        ] as $oldSlug => [$name, $slug]) {
            DB::table('product_categories')->where('slug', $oldSlug)->update(['name' => $name, 'slug' => $slug, 'updated_at' => now()]);
        }

        Schema::table('products', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable()->index()->after('name');
        });
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('normalized_name')->nullable()->index()->after('name');
        });
        Schema::table('purchases', function (Blueprint $table): void {
            $table->string('source', 20)->nullable()->index();
            $table->string('source_document_id')->nullable()->index();
            $table->uuid('import_batch_id')->nullable()->index();
        });
        Schema::table('purchase_items', function (Blueprint $table): void {
            $table->string('unit', 50)->nullable();
            $table->decimal('vat_amount', 14, 2)->nullable();
            $table->string('source_row_hash', 64)->nullable()->unique();
        });

        DB::table('products')->orderBy('id')->each(fn (object $product) => DB::table('products')->where('id', $product->id)->update([
            'normalized_name' => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $product->name))),
        ]));
        DB::table('suppliers')->orderBy('id')->each(fn (object $supplier) => DB::table('suppliers')->where('id', $supplier->id)->update([
            'normalized_name' => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $supplier->name))),
        ]));
    }

    public function down(): void
    {
        Schema::table('purchase_items', fn (Blueprint $table) => $table->dropColumn(['unit', 'vat_amount', 'source_row_hash']));
        Schema::table('purchases', fn (Blueprint $table) => $table->dropColumn(['source', 'source_document_id', 'import_batch_id']));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('normalized_name'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('normalized_name'));
    }
};
