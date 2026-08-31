<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_settlements', function (Blueprint $table): void {
            $table->decimal('paid_amount', 14, 2)->nullable()->after('performed_total');
            $table->decimal('outstanding_amount', 14, 2)->nullable()->after('paid_amount');
        });

        Schema::table('salary_settlement_items', function (Blueprint $table): void {
            $table->decimal('total_value_snapshot', 14, 2)->nullable()->after('doctor_share');
            $table->decimal('paid_amount_snapshot', 14, 2)->nullable()->after('total_value_snapshot');
            $table->decimal('outstanding_amount_snapshot', 14, 2)->nullable()->after('paid_amount_snapshot');
            $table->decimal('expense_snapshot', 14, 2)->nullable()->after('outstanding_amount_snapshot');
            $table->decimal('base_snapshot', 14, 2)->nullable()->after('expense_snapshot');
            $table->decimal('doctor_share_snapshot', 14, 2)->nullable()->after('base_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('salary_settlement_items', function (Blueprint $table): void {
            $table->dropColumn([
                'total_value_snapshot', 'paid_amount_snapshot', 'outstanding_amount_snapshot',
                'expense_snapshot', 'base_snapshot', 'doctor_share_snapshot',
            ]);
        });

        Schema::table('salary_settlements', function (Blueprint $table): void {
            $table->dropColumn(['paid_amount', 'outstanding_amount']);
        });
    }
};
