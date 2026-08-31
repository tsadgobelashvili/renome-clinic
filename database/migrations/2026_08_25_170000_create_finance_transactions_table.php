<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('type');
            $table->dateTime('transaction_date');
            $table->string('category');
            $table->string('description')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('GEL');
            $table->string('payment_method');
            $table->string('cash_source')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['transaction_date', 'type']);
            $table->index(['category', 'transaction_date']);
            $table->index(['payment_method', 'transaction_date']);
        });

        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->foreignId('finance_transaction_id')->nullable()->unique()
                ->after('payment_id')->constrained('finance_transactions')->nullOnDelete();
        });

        DB::table('cashbox_transactions')->where('type', 'patient_payment')->orderBy('id')
            ->each(function (object $cashbox): void {
                $splits = DB::table('payment_splits')->where('payment_id', $cashbox->payment_id);
                $cashAmount = (clone $splits)->where('payment_method', 'cash')->sum('amount');

                if (! (clone $splits)->exists() && $cashbox->payment_method === 'cash') {
                    $cashAmount = $cashbox->amount;
                }

                if ((float) $cashAmount <= 0) {
                    DB::table('cashbox_transactions')->where('id', $cashbox->id)->delete();

                    return;
                }

                DB::table('cashbox_transactions')->where('id', $cashbox->id)->update([
                    'amount' => $cashAmount,
                    'payment_method' => 'cash',
                    'updated_at' => now(),
                ]);
            });

        DB::table('cashbox_transactions')
            ->where('type', 'expense')
            ->orderBy('id')
            ->each(function (object $cashbox): void {
                $financeId = DB::table('finance_transactions')->insertGetId([
                    'type' => 'expense',
                    'transaction_date' => $cashbox->transaction_date,
                    'category' => $cashbox->expense_category ?: 'other',
                    'description' => $cashbox->description,
                    'amount' => $cashbox->amount,
                    'currency' => $cashbox->currency,
                    'payment_method' => $cashbox->payment_method ?: 'cash',
                    'cash_source' => $cashbox->payment_method === 'cash' ? 'current_cashier' : null,
                    'created_by' => $cashbox->created_by,
                    'created_at' => $cashbox->created_at,
                    'updated_at' => $cashbox->updated_at,
                ]);

                DB::table('cashbox_transactions')->where('id', $cashbox->id)->update([
                    'finance_transaction_id' => $financeId,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('finance_transaction_id');
        });

        Schema::dropIfExists('finance_transactions');
    }
};
