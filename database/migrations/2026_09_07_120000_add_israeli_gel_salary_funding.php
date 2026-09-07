<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_settlements', function (Blueprint $table): void {
            $table->decimal('total_paid_gel', 14, 2)->nullable();
            $table->decimal('israeli_gel_used', 14, 2)->nullable();
            $table->decimal('clinic_gel_used', 14, 2)->nullable();
        });
        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->foreignId('salary_settlement_id')->nullable()->unique()
                ->constrained('salary_settlements')->nullOnDelete();
            $table->foreignId('reversal_of_finance_transaction_id')->nullable()->unique('finance_reversal_unique')
                ->constrained('finance_transactions')->restrictOnDelete();
        });

        // Existing posted GEL salaries keep their historical funding; never fund them again.
        DB::table('partner_finance_transactions')->whereNotNull('salary_settlement_id')
            ->where('source', 'israeli')->where('currency', 'GEL')->where('category', 'doctor_salary')
            ->orderBy('id')->each(function (object $movement): void {
                DB::table('salary_settlements')->where('id', $movement->salary_settlement_id)
                    ->where('payment_currency', 'GEL')->update([
                        'total_paid_gel' => $movement->amount,
                        'israeli_gel_used' => $movement->amount,
                        'clinic_gel_used' => 0,
                    ]);
            });
    }

    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->dropUnique('finance_reversal_unique');
            $table->dropConstrainedForeignId('reversal_of_finance_transaction_id');
            $table->dropUnique(['salary_settlement_id']);
            $table->dropConstrainedForeignId('salary_settlement_id');
        });
        Schema::table('salary_settlements', function (Blueprint $table): void {
            $table->dropColumn(['total_paid_gel', 'israeli_gel_used', 'clinic_gel_used']);
        });
    }
};
