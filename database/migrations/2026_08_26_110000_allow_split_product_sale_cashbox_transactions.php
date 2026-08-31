<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->dropUnique(['product_sale_id']);
            $table->unique(['product_sale_id', 'payment_method', 'currency'], 'cashbox_product_sale_method_currency_unique');
        });
    }

    public function down(): void
    {
        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->dropUnique('cashbox_product_sale_method_currency_unique');
            $table->unique('product_sale_id');
        });
    }
};
