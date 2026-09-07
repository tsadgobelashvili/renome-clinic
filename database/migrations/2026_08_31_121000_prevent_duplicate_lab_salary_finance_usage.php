<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_finance_transactions', function (Blueprint $table): void {
            $table->unique('lab_salary_settlement_id', 'partner_finance_lab_salary_unique');
        });
    }

    public function down(): void
    {
        Schema::table('partner_finance_transactions', function (Blueprint $table): void {
            $table->dropUnique('partner_finance_lab_salary_unique');
        });
    }
};
