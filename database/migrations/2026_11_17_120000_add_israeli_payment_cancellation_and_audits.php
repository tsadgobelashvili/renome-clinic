<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_patient_payments', function (Blueprint $table): void {
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
        });
        Schema::create('partner_payment_audits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_patient_payment_id')->constrained('partner_patient_payments')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name');
            $table->string('action', 20);
            $table->uuid('operation_key')->unique();
            $table->string('payload_hash', 64);
            $table->json('old_values');
            $table->json('new_values');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['partner_patient_payment_id', 'id']);
        });
    }

    public function down(): void
    {
        // Audit history must survive an application rollback. Keep this additive schema.
    }
};
