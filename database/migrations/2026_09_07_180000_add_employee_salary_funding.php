<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $view = $this->detachSqliteView();
        Schema::table('employee_salary_settlements', function (Blueprint $table) {
            $table->decimal('actual_paid_gel', 14, 2)->nullable();
            $table->decimal('clinic_cash_gel', 14, 2)->nullable();
            $table->decimal('israeli_cash_gel', 14, 2)->nullable();
        });
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('employee_salary_settlement_id')->nullable()->unique('finance_employee_salary_unique')->constrained()->restrictOnDelete();
            $table->decimal('clinic_cash_gel', 14, 2)->nullable();
            $table->decimal('israeli_cash_gel', 14, 2)->nullable();
        });
        Schema::table('partner_finance_transactions', function (Blueprint $table) {
            $table->foreignId('finance_transaction_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        if ($view) {
            DB::statement($view);
        }
    }

    public function down(): void
    {
        $view = $this->detachSqliteView();
        Schema::table('partner_finance_transactions', function (Blueprint $table) {
            $table->dropUnique(['finance_transaction_id']);
            $table->dropConstrainedForeignId('finance_transaction_id');
        });
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropUnique('finance_employee_salary_unique');
            $table->dropConstrainedForeignId('employee_salary_settlement_id');
            $table->dropColumn(['clinic_cash_gel', 'israeli_cash_gel']);
        });
        Schema::table('employee_salary_settlements', fn (Blueprint $table) => $table->dropColumn(['actual_paid_gel', 'clinic_cash_gel', 'israeli_cash_gel']));
        if ($view) {
            DB::statement($view);
        }
    }

    private function detachSqliteView(): ?string
    {
        if (DB::getDriverName() !== 'sqlite') {
            return null;
        }
        $sql = DB::table('sqlite_master')->where('type', 'view')->where('name', 'partner_finance_entries')->value('sql');
        DB::statement('DROP VIEW IF EXISTS partner_finance_entries');

        return $sql;
    }
};
