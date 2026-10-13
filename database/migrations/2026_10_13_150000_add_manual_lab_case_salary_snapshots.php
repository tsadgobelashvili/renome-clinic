<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_settlement_items', function (Blueprint $table): void {
            $table->foreignId('manual_lab_case_id')->nullable()->unique()->constrained('lab_cases')->restrictOnDelete();
            $table->json('manual_case_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve manual salary case membership and historical snapshots.');
    }
};
