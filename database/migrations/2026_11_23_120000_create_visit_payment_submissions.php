<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visit_payment_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->string('payload_hash', 64);
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
        Schema::create('visit_payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('submission_id')->constrained('visit_payment_submissions')->restrictOnDelete();
            $table->foreignId('visit_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('partner_patient_payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('bucket', 16);
            $table->decimal('settled_amount', 14, 2);
            $table->string('currency', 3);
            $table->json('obligation_snapshot');
            $table->timestamp('created_at')->useCurrent();
            $table->unique('payment_id');
            $table->unique('partner_patient_payment_id');
            $table->index(['visit_id', 'bucket']);
        });
    }

    public function down(): void
    {
        // Retain payment identity and financial allocation history on application rollback.
    }
};
