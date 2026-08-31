<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_finance_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->dateTime('transacted_at');
            $table->string('category', 32)->nullable();
            $table->string('from_account', 32)->nullable();
            $table->string('to_account', 32)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->decimal('from_amount', 14, 2)->nullable();
            $table->string('from_currency', 3)->nullable();
            $table->decimal('to_amount', 14, 2)->nullable();
            $table->string('to_currency', 3)->nullable();
            $table->decimal('exchange_rate', 18, 6)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['type', 'transacted_at']);
            $table->index(['from_account', 'from_currency']);
            $table->index(['to_account', 'to_currency']);
        });

        $driver = DB::connection()->getDriverName();
        $paymentKey = $driver === 'pgsql' ? "'payment-' || id::text" : "'payment-' || CAST(id AS TEXT)";
        $transactionKey = $driver === 'pgsql' ? "'transaction-' || id::text" : "'transaction-' || CAST(id AS TEXT)";

        DB::statement(<<<SQL
            CREATE VIEW partner_finance_entries AS
            SELECT {$paymentKey} AS entry_key,
                   'payment' AS source_type,
                   id AS source_id,
                   'payment' AS transaction_type,
                   paid_at AS transacted_at,
                   patient_id,
                   amount,
                   currency,
                   CASE WHEN payment_method = 'cash' THEN 'cash' ELSE 'bank' END AS from_account,
                   NULL AS to_account,
                   NULL AS category,
                   payment_method,
                   NULL AS from_amount,
                   NULL AS from_currency,
                   NULL AS to_amount,
                   NULL AS to_currency,
                   NULL AS exchange_rate,
                   notes
            FROM partner_patient_payments
            UNION ALL
            SELECT {$transactionKey} AS entry_key,
                   'transaction' AS source_type,
                   id AS source_id,
                   type AS transaction_type,
                   transacted_at,
                   NULL AS patient_id,
                   amount,
                   currency,
                   from_account,
                   to_account,
                   category,
                   NULL AS payment_method,
                   from_amount,
                   from_currency,
                   to_amount,
                   to_currency,
                   exchange_rate,
                   notes
            FROM partner_finance_transactions
            SQL);
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS partner_finance_entries');
        Schema::dropIfExists('partner_finance_transactions');
    }
};
