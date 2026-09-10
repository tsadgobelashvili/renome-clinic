<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_settlement_items', function (Blueprint $table): void {
            // Null preserves the unknown approval state of pre-existing history.
            $table->boolean('is_full_discount_snapshot')->nullable();
            $table->decimal('potential_doctor_share_snapshot', 14, 2)->nullable();
            $table->boolean('salary_approved')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('salary_settlement_items', function (Blueprint $table): void {
            $table->dropColumn(['is_full_discount_snapshot', 'potential_doctor_share_snapshot', 'salary_approved']);
        });
    }
};
