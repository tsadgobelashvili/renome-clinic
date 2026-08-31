<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $now = now();
        DB::table('product_categories')->insert(collect([
            'Implantology', 'Therapy', 'Orthopedics', 'Lab', 'Disposables',
            'Sterilization', 'Office', 'Other', 'Uncategorized',
        ])->map(fn (string $name): array => [
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all());

        Schema::table('products', function (Blueprint $table): void {
            $table->foreignId('product_category_id')->nullable()->after('name')
                ->constrained()->nullOnDelete();
            $table->index(['product_category_id', 'is_active']);
        });

        DB::table('products')->update([
            'product_category_id' => DB::table('product_categories')->where('slug', 'uncategorized')->value('id'),
        ]);

        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->string('tax_id')->nullable()->index();
            $table->string('phone')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('purchases', function (Blueprint $table): void {
            $table->id();
            $table->date('purchase_date')->index();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->string('document_number')->nullable()->index();
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['supplier_id', 'purchase_date']);
        });

        Schema::create('purchase_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();
            $table->index(['product_id', 'purchase_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('suppliers');
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_category_id');
        });
        Schema::dropIfExists('product_categories');
    }
};
