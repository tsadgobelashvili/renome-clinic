<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lab_salary_settlements', function (Blueprint $table): void {
            $table->decimal('actual_paid_gel', 12, 2)->nullable()->after('salary_total');
            $table->decimal('israeli_cash_gel', 12, 2)->nullable()->after('actual_paid_gel');
            $table->timestamp('undone_at')->nullable()->after('settled_at');
            $table->foreignId('undone_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
        });

        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->foreignId('lab_salary_settlement_id')->nullable()->after('employee_salary_settlement_id')
                ->constrained('lab_salary_settlements')->nullOnDelete();
            $table->unique('lab_salary_settlement_id', 'finance_lab_salary_settlement_unique');
        });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->dropUnique('finance_lab_salary_settlement_unique');
            $table->dropConstrainedForeignId('lab_salary_settlement_id');
        });

        Schema::table('lab_salary_settlements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('undone_by');
            $table->dropColumn(['actual_paid_gel', 'israeli_cash_gel', 'undone_at']);
        });
    }
};
