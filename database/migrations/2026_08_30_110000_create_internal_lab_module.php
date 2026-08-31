<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('role', 30)->default('owner')->index();
            $table->string('locale', 5)->default('ka');
        });

        Schema::create('lab_cases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained()->restrictOnDelete();
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();
            $table->date('case_date')->index();
            $table->string('status', 30)->default('open')->index();
            $table->string('exocad_project_reference')->nullable()->index();
            $table->text('notes')->nullable();
            $table->foreignId('related_case_id')->nullable()->constrained('lab_cases')->nullOnDelete();
            $table->string('case_relationship', 20)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['patient_id', 'case_date']);
            $table->index(['doctor_id', 'case_date']);
        });

        Schema::create('lab_technician_rates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technician_id')->constrained('users')->cascadeOnDelete();
            $table->string('work_type', 40);
            $table->string('component_type', 20);
            $table->decimal('rate_per_unit', 10, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['technician_id', 'work_type', 'component_type'], 'lab_technician_rate_unique');
        });

        Schema::create('lab_work_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_case_id')->constrained()->cascadeOnDelete();
            $table->string('work_type', 40)->index();
            $table->string('component_type', 20)->index();
            $table->unsignedInteger('quantity');
            $table->foreignId('technician_id')->constrained('users')->restrictOnDelete();
            $table->date('work_date')->index();
            $table->string('status', 20)->default('completed')->index();
            $table->decimal('rate_snapshot', 10, 2);
            $table->decimal('salary_amount', 12, 2);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['technician_id', 'status', 'work_date'], 'lab_work_salary_lookup');
        });

        Schema::create('lab_salary_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('technician_id')->constrained('users')->restrictOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('salary_total', 12, 2);
            $table->string('status', 20)->default('confirmed')->index();
            $table->timestamp('settled_at');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['technician_id', 'period_start', 'period_end'], 'lab_salary_period_lookup');
        });

        Schema::create('lab_salary_settlement_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lab_salary_settlement_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lab_work_item_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_snapshot');
            $table->decimal('rate_snapshot', 10, 2);
            $table->decimal('salary_snapshot', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_salary_settlement_items');
        Schema::dropIfExists('lab_salary_settlements');
        Schema::dropIfExists('lab_work_items');
        Schema::dropIfExists('lab_technician_rates');
        Schema::dropIfExists('lab_cases');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'locale']));
    }
};
