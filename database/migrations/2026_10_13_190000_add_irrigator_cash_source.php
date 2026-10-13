<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            // NULL keeps every historical record's original current-cashbox behavior.
            $table->string('cash_usage_source')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->dropColumn('cash_usage_source');
        });
    }
};
