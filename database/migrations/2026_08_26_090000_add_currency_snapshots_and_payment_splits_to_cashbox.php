<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cashbox_days', function (Blueprint $table): void {
            $table->decimal('opening_balance_usd', 14, 2)->default(0)->after('opening_balance');
            $table->decimal('expected_closing_balance_usd', 14, 2)->default(0)->after('expected_closing_balance');
            $table->decimal('actual_closing_balance_usd', 14, 2)->nullable()->after('actual_closing_balance');
            $table->decimal('cash_withdrawal_total_usd', 14, 2)->default(0)->after('cash_withdrawal_total');
            $table->decimal('carry_forward_balance_usd', 14, 2)->default(0)->after('carry_forward_balance');
        });

        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->dropUnique(['payment_id', 'currency']);
            $table->foreignId('payment_split_id')->nullable()->after('payment_id')
                ->constrained('payment_splits')->nullOnDelete();
        });

        DB::table('payment_splits')
            ->join('payments', 'payments.id', '=', 'payment_splits.payment_id')
            ->join('visits', 'visits.id', '=', 'payments.visit_id')
            ->join('cashbox_days', 'cashbox_days.date', '=', 'payments.payment_date')
            ->whereNull('payments.deleted_at')
            ->orderBy('payment_splits.id')
            ->select([
                'payment_splits.id as split_id', 'payment_splits.payment_id', 'payment_splits.amount',
                'payment_splits.currency', 'payment_splits.payment_method', 'payments.payment_date',
                'payments.created_at', 'payments.created_by', 'visits.id as visit_id',
                'visits.patient_id', 'cashbox_days.id as cashbox_day_id',
            ])
            ->each(function (object $split): void {
                $attributes = [
                    'cashbox_day_id' => $split->cashbox_day_id,
                    'type' => 'patient_payment',
                    'amount' => $split->amount,
                    'currency' => $split->currency,
                    'payment_method' => $split->payment_method,
                    'transaction_date' => $split->created_at ?? $split->payment_date.' 12:00:00',
                    'payment_id' => $split->payment_id,
                    'payment_split_id' => $split->split_id,
                    'patient_id' => $split->patient_id,
                    'visit_id' => $split->visit_id,
                    'created_by' => $split->created_by,
                    'created_at' => $split->created_at ?? now(),
                    'updated_at' => $split->created_at ?? now(),
                ];
                $existingId = DB::table('cashbox_transactions')
                    ->where('type', 'patient_payment')->where('payment_id', $split->payment_id)
                    ->where('currency', $split->currency)->where('payment_method', $split->payment_method)
                    ->whereNull('payment_split_id')->value('id');

                $existingId
                    ? DB::table('cashbox_transactions')->where('id', $existingId)->update($attributes)
                    : DB::table('cashbox_transactions')->insert($attributes);
            });

        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->unique('payment_split_id');
        });
    }

    public function down(): void
    {
        DB::table('cashbox_transactions')->whereNotNull('payment_split_id')->delete();

        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->dropUnique(['payment_split_id']);
            $table->dropConstrainedForeignId('payment_split_id');
            $table->unique(['payment_id', 'currency']);
        });

        Schema::table('cashbox_days', function (Blueprint $table): void {
            $table->dropColumn([
                'opening_balance_usd', 'expected_closing_balance_usd', 'actual_closing_balance_usd',
                'cash_withdrawal_total_usd', 'carry_forward_balance_usd',
            ]);
        });
    }
};
