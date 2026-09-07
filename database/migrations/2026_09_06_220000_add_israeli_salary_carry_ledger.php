<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_settlements', function (Blueprint $table): void {
            foreach (['calculated_usd', 'actual_paid_usd', 'difference_usd', 'opening_carry_usd', 'closing_carry_usd', 'converted_salary_usd', 'gel_salary_basis'] as $column) {
                $table->decimal($column, 14, 2)->nullable();
            }
        });

        Schema::create('israeli_salary_carry_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            // Historical reference deliberately survives settlement undo/deletion.
            $table->unsignedBigInteger('salary_settlement_id');
            $table->string('kind', 16);
            $table->decimal('amount_usd', 14, 2);
            $table->json('snapshot');
            $table->timestamp('created_at');
            $table->unique(['salary_settlement_id', 'kind'], 'israeli_salary_carry_unique');
            $table->index(['doctor_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('israeli_salary_carry_entries');
        Schema::table('salary_settlements', function (Blueprint $table): void {
            $table->dropColumn(['calculated_usd', 'actual_paid_usd', 'difference_usd', 'opening_carry_usd', 'closing_carry_usd', 'converted_salary_usd', 'gel_salary_basis']);
        });
    }
};
