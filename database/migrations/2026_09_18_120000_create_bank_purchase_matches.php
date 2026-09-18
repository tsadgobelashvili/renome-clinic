<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_purchase_matches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 18, 2);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['bank_transaction_id', 'purchase_id']);
            $table->index('purchase_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_purchase_matches');
    }
};
