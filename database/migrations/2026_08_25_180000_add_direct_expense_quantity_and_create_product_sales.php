<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('direct_expenses', function (Blueprint $table): void {
            $table->unsignedInteger('quantity')->default(1)->after('name');
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('selling_price', 14, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'name']);
        });

        Schema::create('product_sales', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('sold_at');
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('total', 14, 2);
            $table->string('currency', 3)->default('GEL');
            $table->string('payment_method');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['sold_at', 'payment_method']);
            $table->index(['patient_id', 'sold_at']);
            $table->index(['visit_id', 'sold_at']);
        });

        Schema::create('product_sale_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->timestamps();

            $table->index(['product_sale_id', 'product_id']);
        });

        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->foreignId('product_sale_id')->nullable()->unique()
                ->after('finance_transaction_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_sale_id');
        });

        Schema::dropIfExists('product_sale_items');
        Schema::dropIfExists('product_sales');
        Schema::dropIfExists('products');

        Schema::table('direct_expenses', function (Blueprint $table): void {
            $table->dropColumn('quantity');
        });
    }
};
