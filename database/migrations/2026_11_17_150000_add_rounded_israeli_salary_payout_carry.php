<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_payouts', function (Blueprint $table): void {
            $table->decimal('actual_paid_gel', 14, 2)->nullable();
            $table->decimal('advance_applied_gel', 14, 2)->nullable();
            $table->decimal('advance_created_gel', 14, 2)->nullable();
            $table->json('rounding_snapshot')->nullable();
        });
        Schema::create('israeli_salary_payout_carry_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_payout_id')->constrained()->restrictOnDelete();
            $table->string('kind', 16);
            $table->decimal('amount_gel', 14, 2);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['salary_payout_id', 'kind'], 'israeli_payout_carry_once');
            $table->index(['doctor_id', 'id'], 'israeli_payout_carry_recipient');
        });
    }

    public function down(): void
    {
        // Retain immutable payout/carry history on an application rollback.
    }
};
