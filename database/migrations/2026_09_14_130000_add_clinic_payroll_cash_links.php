<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctors', fn (Blueprint $table) => $table->string('clinic_salary_payment_method')->default('bank_transfer'));
        Schema::table('salary_settlements', fn (Blueprint $table) => $table->string('clinic_payment_method')->nullable());
        Schema::table('finance_transactions', fn (Blueprint $table) => $table->foreignId('payroll_entry_id')->nullable()->unique()->constrained()->restrictOnDelete());
    }

    public function down(): void
    {
        Schema::table('finance_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('payroll_entry_id'));
        Schema::table('salary_settlements', fn (Blueprint $table) => $table->dropColumn('clinic_payment_method'));
        Schema::table('doctors', fn (Blueprint $table) => $table->dropColumn('clinic_salary_payment_method'));
    }
};
