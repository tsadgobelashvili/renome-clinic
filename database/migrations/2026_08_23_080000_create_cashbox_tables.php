<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashbox_days', function (Blueprint $table): void {
            $table->id();
            $table->date('date')->unique();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->decimal('expected_closing_balance', 14, 2)->default(0);
            $table->decimal('actual_closing_balance', 14, 2)->nullable();
            $table->decimal('cash_withdrawal_total', 14, 2)->default(0);
            $table->decimal('carry_forward_balance', 14, 2)->default(0);
            $table->string('status')->default('open');
            $table->boolean('automatically_closed')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status', 'date']);
        });

        Schema::create('cashbox_transactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cashbox_day_id')->constrained()->restrictOnDelete();
            $table->string('type');
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('GEL');
            $table->string('payment_method')->nullable();
            $table->timestamp('transaction_date');
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('expense_category')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['transaction_date', 'type']);
            $table->index(['payment_method', 'transaction_date']);
            $table->index(['cashbox_day_id', 'type', 'currency']);
        });

        DB::table('payments')
            ->whereNull('deleted_at')
            ->selectRaw('payment_date, MIN(created_at) AS opened_at')
            ->groupBy('payment_date')
            ->orderBy('payment_date')
            ->get()
            ->each(function (object $row): void {
                DB::table('cashbox_days')->insertOrIgnore([
                    'date' => $row->payment_date,
                    'opening_balance' => 0,
                    'status' => $row->payment_date < now()->toDateString() ? 'closed' : 'open',
                    'automatically_closed' => $row->payment_date < now()->toDateString(),
                    'opened_at' => $row->opened_at,
                    'closed_at' => $row->payment_date < now()->toDateString() ? now() : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });

        DB::table('payments')
            ->whereNull('payments.deleted_at')
            ->join('visits', 'visits.id', '=', 'payments.visit_id')
            ->join('cashbox_days', 'cashbox_days.date', '=', 'payments.payment_date')
            ->orderBy('payments.id')
            ->select([
                'payments.id', 'payments.amount', 'payments.currency', 'payments.payment_method',
                'payments.payment_date', 'payments.created_at', 'payments.created_by',
                'visits.id as visit_id', 'visits.patient_id', 'cashbox_days.id as cashbox_day_id',
            ])
            ->each(function (object $payment): void {
                DB::table('cashbox_transactions')->insertOrIgnore([
                    'cashbox_day_id' => $payment->cashbox_day_id,
                    'type' => 'patient_payment',
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'payment_method' => $payment->payment_method,
                    'transaction_date' => $payment->payment_date.' 12:00:00',
                    'payment_id' => $payment->id,
                    'patient_id' => $payment->patient_id,
                    'visit_id' => $payment->visit_id,
                    'created_by' => $payment->created_by,
                    'created_at' => $payment->created_at,
                    'updated_at' => $payment->created_at,
                ]);
            });

        DB::table('cashbox_days')->where('status', 'closed')->get()->each(function (object $day): void {
            $cashIncome = DB::table('cashbox_transactions')
                ->join('payment_splits', 'payment_splits.payment_id', '=', 'cashbox_transactions.payment_id')
                ->where('cashbox_transactions.cashbox_day_id', $day->id)
                ->where('payment_splits.currency', 'GEL')
                ->where('payment_splits.payment_method', 'cash')
                ->sum('payment_splits.amount');

            DB::table('cashbox_days')->where('id', $day->id)->update([
                'expected_closing_balance' => $cashIncome,
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashbox_transactions');
        Schema::dropIfExists('cashbox_days');
    }
};
