<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bog_transactions', function (Blueprint $table): void {
            $table->id();
            $table->string('entry_id')->unique();
            $table->string('account_number');
            $table->string('currency', 3);
            $table->date('operation_date');
            $table->date('value_date')->nullable();
            $table->decimal('debit', 18, 2)->default(0);
            $table->decimal('credit', 18, 2)->default(0);
            $table->text('description')->nullable();
            $table->string('counterparty_name')->nullable();
            $table->string('counterparty_account')->nullable();
            $table->string('counterparty_bank')->nullable();
            $table->string('operation_type')->nullable();
            $table->string('status')->default('unreviewed');
            $table->json('raw_payload');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bog_transactions');
    }
};
