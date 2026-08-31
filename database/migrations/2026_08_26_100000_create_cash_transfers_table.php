<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_cashbox_day_id')->constrained('cashbox_days')->restrictOnDelete();
            $table->foreignId('destination_cashbox_day_id')->constrained('cashbox_days')->restrictOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3);
            $table->timestamp('transferred_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['source_cashbox_day_id', 'currency']);
            $table->index(['destination_cashbox_day_id', 'currency']);
        });

        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->foreignId('cash_transfer_id')->nullable()->after('product_sale_id')
                ->constrained('cash_transfers')->restrictOnDelete();
            $table->unique(['cash_transfer_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::table('cashbox_transactions', function (Blueprint $table): void {
            $table->dropUnique(['cash_transfer_id', 'type']);
            $table->dropConstrainedForeignId('cash_transfer_id');
        });
        Schema::dropIfExists('cash_transfers');
    }
};
