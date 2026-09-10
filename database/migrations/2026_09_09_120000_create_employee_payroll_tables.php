<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_payroll_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('salary_model', 30);
            $table->string('currency', 3)->default('GEL');
            $table->string('default_payment_method', 20)->default('bank_transfer');
            $table->date('effective_from')->nullable();
            $table->boolean('is_active')->default(true);
            $table->decimal('net_amount', 14, 2)->nullable();
            $table->decimal('gross_amount', 14, 2)->nullable();
            $table->decimal('percentage_rate', 7, 4)->nullable();
            $table->decimal('per_unit_amount', 14, 2)->nullable();
            $table->string('category', 60)->nullable();
            $table->foreignId('treatment_case_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('taxable')->default(false);
            $table->decimal('employee_deductions', 14, 2)->default(0);
            $table->decimal('employer_cost', 14, 2)->default(0);
            $table->string('tax_settings_reference')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'source']);
            $table->index(['source', 'is_active', 'effective_from']);
        });

        Schema::create('payroll_runs', function (Blueprint $table): void {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('source', 20);
            $table->string('status', 20)->default('finalized');
            $table->timestamp('finalized_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['source', 'period_start', 'period_end']);
        });

        Schema::create('payroll_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('employee_payroll_setting_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('source', 20);
            $table->string('salary_model', 30);
            $table->json('settings_snapshot');
            $table->json('calculation_details');
            $table->decimal('base_amount', 14, 2)->default(0);
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->decimal('deductions', 14, 2)->default(0);
            $table->decimal('employer_cost', 14, 2)->default(0);
            $table->string('currency', 3);
            $table->string('payment_method', 20);
            $table->string('payout_status', 20)->default('pending');
            $table->string('matching_status', 20)->default('unmatched');
            $table->unsignedBigInteger('bank_transaction_id')->nullable();
            $table->string('status', 20)->default('finalized');
            $table->timestamp('finalized_at');
            $table->timestamps();

            $table->unique(['employee_id', 'source', 'period_start', 'period_end'], 'payroll_entry_period_unique');
            $table->index(['employee_id', 'finalized_at']);
            $table->index(['bank_transaction_id', 'matching_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_entries');
        Schema::dropIfExists('payroll_runs');
        Schema::dropIfExists('employee_payroll_settings');
    }
};
