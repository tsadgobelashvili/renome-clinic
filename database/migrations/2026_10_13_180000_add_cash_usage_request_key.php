<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->uuid('cash_usage_key')->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->dropUnique(['cash_usage_key']);
            $table->dropColumn('cash_usage_key');
        });
    }
};
