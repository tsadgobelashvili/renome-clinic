<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_splits', function (Blueprint $table): void {
            $table->decimal('exchange_rate', 14, 6)->nullable()->after('currency');
        });

        Schema::table('visit_treatment_cases', function (Blueprint $table): void {
            $table->string('currency', 3)->nullable()->after('unit_price');
            $table->decimal('exchange_rate', 14, 6)->nullable()->after('currency');
        });

        DB::table('visit_treatment_cases')->orderBy('id')->each(function (object $item): void {
            $currency = DB::table('visits')->where('id', $item->visit_id)->value('currency') ?: 'GEL';
            DB::table('visit_treatment_cases')->where('id', $item->id)->update(['currency' => $currency]);
        });

        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->dropUnique(['payment_id']);
            $table->unique(['payment_id', 'currency']);
        });
    }

    public function down(): void
    {
        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->dropUnique(['payment_id', 'currency']);
            $table->unique('payment_id');
        });

        Schema::table('visit_treatment_cases', function (Blueprint $table): void {
            $table->dropColumn(['currency', 'exchange_rate']);
        });
        Schema::table('payment_splits', function (Blueprint $table): void {
            $table->dropColumn('exchange_rate');
        });
    }
};
