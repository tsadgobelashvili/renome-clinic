<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_advances', function (Blueprint $table): void {
            $table->boolean('is_salary_advance')->default(false);
        });
        Schema::table('payroll_entries', function (Blueprint $table): void {
            $table->decimal('salary_advance_applied', 14, 2)->default(0);
        });
        Schema::table('employee_advance_entries', function (Blueprint $table): void {
            $table->foreignId('payroll_entry_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['employee_advance_id', 'payroll_entry_id'], 'advance_payroll_unique');
        });
    }

    public function down(): void
    {
        Schema::table('employee_advance_entries', function (Blueprint $table): void {
            $table->dropUnique('advance_payroll_unique');
            $table->dropConstrainedForeignId('payroll_entry_id');
        });
        Schema::table('payroll_entries', fn (Blueprint $table) => $table->dropColumn('salary_advance_applied'));
        Schema::table('employee_advances', fn (Blueprint $table) => $table->dropColumn('is_salary_advance'));
    }
};
