<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP VIEW IF EXISTS partner_finance_entries');

        Schema::table('salary_settlements', function (Blueprint $table): void {
            $table->string('payment_currency', 3)->nullable()->after('currency');
            $table->decimal('payment_exchange_rate', 18, 6)->nullable()->after('payment_currency');
            $table->decimal('payment_amount', 14, 2)->nullable()->after('payment_exchange_rate');
        });

        Schema::table('partner_finance_transactions', function (Blueprint $table): void {
            $table->foreignId('doctor_id')->nullable()->after('recipient')
                ->constrained()->restrictOnDelete();
            $table->foreignId('salary_settlement_id')->nullable()->after('lab_salary_settlement_id')
                ->constrained('salary_settlements')->restrictOnDelete();
            $table->unique('salary_settlement_id', 'partner_finance_salary_settlement_unique');
        });

        $this->createEntriesView(true);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS partner_finance_entries');

        Schema::table('partner_finance_transactions', function (Blueprint $table): void {
            $table->dropUnique('partner_finance_salary_settlement_unique');
            $table->dropConstrainedForeignId('salary_settlement_id');
            $table->dropConstrainedForeignId('doctor_id');
        });

        Schema::table('salary_settlements', function (Blueprint $table): void {
            $table->dropColumn(['payment_currency', 'payment_exchange_rate', 'payment_amount']);
        });

        $this->createEntriesView(false);
    }

    private function createEntriesView(bool $withSalaryLinks): void
    {
        $driver = DB::connection()->getDriverName();
        $paymentKey = $driver === 'pgsql' ? "'payment-' || id::text" : "'payment-' || CAST(id AS TEXT)";
        $transactionKey = $driver === 'pgsql' ? "'transaction-' || id::text" : "'transaction-' || CAST(id AS TEXT)";
        $paymentSalaryLinks = $withSalaryLinks ? ', NULL AS doctor_id, NULL AS salary_settlement_id' : '';
        $transactionSalaryLinks = $withSalaryLinks ? ', doctor_id, salary_settlement_id' : '';

        DB::statement(<<<SQL
            CREATE VIEW partner_finance_entries AS
            SELECT {$paymentKey} AS entry_key, 'payment' AS source_type, id AS source_id,
                   'payment' AS transaction_type, paid_at AS transacted_at, patient_id, amount, currency,
                   CASE WHEN payment_method = 'cash' THEN 'cash' ELSE 'bank' END AS from_account,
                   NULL AS to_account, NULL AS category, payment_method, NULL AS from_amount,
                   NULL AS from_currency, NULL AS to_amount, NULL AS to_currency, NULL AS exchange_rate, notes,
                   'israeli' AS source, NULL AS recipient, NULL AS lab_salary_settlement_id, NULL AS created_by
                   {$paymentSalaryLinks}
            FROM partner_patient_payments
            UNION ALL
            SELECT {$transactionKey} AS entry_key, 'transaction' AS source_type, id AS source_id,
                   type AS transaction_type, transacted_at, NULL AS patient_id, amount, currency,
                   from_account, to_account, category, NULL AS payment_method, from_amount,
                   from_currency, to_amount, to_currency, exchange_rate, notes,
                   source, recipient, lab_salary_settlement_id, created_by
                   {$transactionSalaryLinks}
            FROM partner_finance_transactions
            SQL);
    }
};
