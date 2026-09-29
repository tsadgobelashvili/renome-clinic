<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', function (Blueprint $table) {
            $table->decimal('external_lab_zircon_rate', 12, 2)->nullable();
            $table->decimal('external_lab_pmma_rate', 12, 2)->nullable();
        });
        Schema::table('lab_cases', fn (Blueprint $table) => $table->foreignId('external_billing_doctor_id')->nullable()->constrained('doctors')->restrictOnDelete());
        Schema::create('external_lab_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->restrictOnDelete();
            $table->foreignId('lab_main_work_id')->unique()->constrained()->restrictOnDelete();
            $table->decimal('unit_rate', 12, 2);
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });
        Schema::table('salary_payouts', fn (Blueprint $table) => $table->decimal('external_deduction_gel', 14, 2)->default(0));
        Schema::create('external_lab_deductions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_lab_charge_id')->constrained()->restrictOnDelete();
            $table->foreignId('salary_payout_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
            $table->unique(['external_lab_charge_id', 'salary_payout_id']);
        });
    }

    public function down(): void
    {
        // Financial history is intentionally retained.
    }
};
