<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('salary_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->timestamp('settled_at');
            $table->string('currency', 3)->default('GEL');
            $table->decimal('performed_total', 14, 2);
            $table->decimal('direct_expense_total', 14, 2);
            $table->decimal('base_total', 14, 2);
            $table->decimal('percentage', 5, 2);
            $table->decimal('salary_total', 14, 2);
            $table->string('status')->default('confirmed');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['doctor_id', 'settled_at']);
            $table->index(['doctor_id', 'period_start', 'period_end']);
        });

        Schema::create('salary_settlement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('salary_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_treatment_case_id')->unique()->constrained('visit_treatment_cases')->restrictOnDelete();
            $table->decimal('revenue', 14, 2);
            $table->decimal('direct_expense', 14, 2);
            $table->decimal('salary_base', 14, 2);
            $table->decimal('doctor_share', 14, 2);
            $table->timestamps();

            $table->index(['salary_settlement_id', 'visit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('salary_settlement_items');
        Schema::dropIfExists('salary_settlements');
    }
};
