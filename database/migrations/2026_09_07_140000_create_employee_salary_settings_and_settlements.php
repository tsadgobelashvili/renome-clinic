<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('salary_type', 20)->nullable();
            $table->boolean('salary_active')->default(false);
            $table->date('salary_effective_from')->nullable();
            $table->decimal('monthly_salary_gel', 12, 2)->nullable();
        });
        Schema::table('lab_main_works', function (Blueprint $table): void {
            $table->foreignId('technician_id')->nullable()->constrained('employees')->restrictOnDelete();
        });
        Schema::create('employee_salary_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('work_type', 40);
            $table->decimal('amount', 12, 2);
            $table->string('basis', 20)->default('per_unit');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['employee_id', 'work_type']);
        });
        Schema::create('employee_salary_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('salary_type', 20);
            $table->date('period_from')->nullable();
            $table->date('period_until')->nullable();
            $table->string('salary_month', 7)->nullable();
            $table->string('active_month', 7)->nullable();
            $table->decimal('total_gel', 12, 2);
            $table->json('settings_snapshot');
            $table->string('status', 20)->default('confirmed');
            $table->timestamp('settled_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'active_month']);
            $table->index(['employee_id', 'settled_at']);
        });
        Schema::create('employee_salary_settlement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('employee_salary_settlement_id')->constrained()->restrictOnDelete();
            $table->foreignId('lab_main_work_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('lab_additional_work_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('active_source_key')->nullable()->unique();
            $table->date('work_date');
            $table->string('patient_name');
            $table->string('work_type', 40);
            $table->unsignedInteger('quantity');
            $table->decimal('rate_amount', 12, 2);
            $table->string('rate_basis', 20);
            $table->decimal('amount_gel', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_salary_settlement_items');
        Schema::dropIfExists('employee_salary_settlements');
        Schema::dropIfExists('employee_salary_rates');
        Schema::table('lab_main_works', fn (Blueprint $table) => $table->dropConstrainedForeignId('technician_id'));
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['salary_type', 'salary_active', 'salary_effective_from', 'monthly_salary_gel']));
    }
};
