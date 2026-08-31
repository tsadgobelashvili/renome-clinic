<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_sales', function (Blueprint $table): void {
            $table->decimal('base_total', 14, 2)->nullable()->after('total');
            $table->decimal('exchange_rate', 14, 6)->nullable()->after('currency');
        });

        DB::table('product_sales')->whereNull('base_total')->update(['base_total' => DB::raw('total')]);
    }

    public function down(): void
    {
        Schema::table('product_sales', function (Blueprint $table): void {
            $table->dropColumn(['base_total', 'exchange_rate']);
        });
    }
};
