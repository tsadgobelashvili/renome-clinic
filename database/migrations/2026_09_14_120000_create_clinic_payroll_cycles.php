<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinic_payroll_cycles', function (Blueprint $table) {
            $table->id();
            $table->date('payroll_date')->unique();
            $table->string('status')->default('draft');
            $table->string('payment_status')->default('pending');
            $table->json('snapshot')->nullable();
            $table->timestamp('cutoff_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('finalized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        foreach (['salary_settlements', 'payroll_entries'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('clinic_payroll_cycle_id')->nullable()->constrained('clinic_payroll_cycles')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['salary_settlements', 'payroll_entries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropConstrainedForeignId('clinic_payroll_cycle_id'));
        }
        Schema::dropIfExists('clinic_payroll_cycles');
    }
};
