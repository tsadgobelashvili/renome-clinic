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

        Schema::table('partner_finance_transactions', function (Blueprint $table): void {
            $table->string('source', 20)->default('israeli')->index();
            $table->string('recipient')->nullable();
            $table->foreignId('lab_salary_settlement_id')->nullable()
                ->constrained('lab_salary_settlements')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $this->createEntriesView();
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS partner_finance_entries');
        Schema::table('partner_finance_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('created_by');
            $table->dropConstrainedForeignId('lab_salary_settlement_id');
            $table->dropColumn(['source', 'recipient']);
        });
        $this->createEntriesView(false);
    }

    private function createEntriesView(bool $withAuditFields = true): void
    {
        $driver = DB::connection()->getDriverName();
        $paymentKey = $driver === 'pgsql' ? "'payment-' || id::text" : "'payment-' || CAST(id AS TEXT)";
        $transactionKey = $driver === 'pgsql' ? "'transaction-' || id::text" : "'transaction-' || CAST(id AS TEXT)";
        $paymentAudit = $withAuditFields ? ", 'israeli' AS source, NULL AS recipient, NULL AS lab_salary_settlement_id, NULL AS created_by" : '';
        $transactionAudit = $withAuditFields ? ', source, recipient, lab_salary_settlement_id, created_by' : '';

        DB::statement(<<<SQL
            CREATE VIEW partner_finance_entries AS
            SELECT {$paymentKey} AS entry_key, 'payment' AS source_type, id AS source_id,
                   'payment' AS transaction_type, paid_at AS transacted_at, patient_id, amount, currency,
                   CASE WHEN payment_method = 'cash' THEN 'cash' ELSE 'bank' END AS from_account,
                   NULL AS to_account, NULL AS category, payment_method, NULL AS from_amount,
                   NULL AS from_currency, NULL AS to_amount, NULL AS to_currency, NULL AS exchange_rate, notes
                   {$paymentAudit}
            FROM partner_patient_payments
            UNION ALL
            SELECT {$transactionKey} AS entry_key, 'transaction' AS source_type, id AS source_id,
                   type AS transaction_type, transacted_at, NULL AS patient_id, amount, currency,
                   from_account, to_account, category, NULL AS payment_method, from_amount,
                   from_currency, to_amount, to_currency, exchange_rate, notes
                   {$transactionAudit}
            FROM partner_finance_transactions
            SQL);
    }
};
