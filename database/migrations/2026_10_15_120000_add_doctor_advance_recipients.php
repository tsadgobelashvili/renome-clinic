<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $view = $this->detachView();
        Schema::table('employee_advances', function (Blueprint $table): void {
            $table->unsignedBigInteger('employee_id')->nullable()->change();
            $table->foreignId('doctor_id')->nullable()->constrained()->restrictOnDelete();
        });
        Schema::table('employee_advance_entries', function (Blueprint $table): void {
            $table->foreignId('salary_settlement_id')->nullable()->constrained()->restrictOnDelete();
            $table->unique(['employee_advance_id', 'salary_settlement_id'], 'advance_doctor_salary_unique');
        });
        Schema::table('salary_settlements', function (Blueprint $table): void {
            $table->decimal('salary_advance_applied', 14, 2)->default(0);
        });
        if ($view) {
            DB::statement($view);
        }
    }

    public function down(): void
    {
        if (DB::table('employee_advances')->whereNotNull('doctor_id')->exists()) {
            throw new RuntimeException('Doctor advances must be preserved; this migration cannot be rolled back with Doctor advances present.');
        }
        $view = $this->detachView();
        Schema::table('employee_advance_entries', function (Blueprint $table): void {
            $table->dropUnique('advance_doctor_salary_unique');
            $table->dropConstrainedForeignId('salary_settlement_id');
        });
        Schema::table('salary_settlements', fn (Blueprint $table) => $table->dropColumn('salary_advance_applied'));
        Schema::table('employee_advances', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('doctor_id');
            $table->unsignedBigInteger('employee_id')->nullable(false)->change();
        });
        if ($view) {
            DB::statement($view);
        }
    }

    private function detachView(): ?string
    {
        if (DB::getDriverName() !== 'sqlite') {
            return null;
        }
        $sql = DB::table('sqlite_master')->where('type', 'view')->where('name', 'partner_finance_entries')->value('sql');
        if ($sql) {
            DB::statement('DROP VIEW partner_finance_entries');
        }

        return $sql;
    }
};
