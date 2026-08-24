<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('created_by')->nullable()->after('visit_id')
                ->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->index(['payment_date', 'created_at']);
        });

        Schema::create('payment_splits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->string('payment_method');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index(['payment_method', 'created_at']);
            $table->index(['payment_id', 'payment_method']);
        });

        Schema::create('payment_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnUpdate()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['payment_id', 'created_at']);
        });

        DB::table('payments')->orderBy('id')->each(function (object $payment): void {
            DB::table('payment_splits')->insert([
                'payment_id' => $payment->id,
                'payment_method' => $payment->payment_method,
                'amount' => $payment->amount,
                'created_at' => $payment->created_at,
                'updated_at' => $payment->updated_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_audits');
        Schema::dropIfExists('payment_splits');

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropIndex(['payment_date', 'created_at']);
            $table->dropConstrainedForeignId('created_by');
            $table->dropSoftDeletes();
        });
    }
};
