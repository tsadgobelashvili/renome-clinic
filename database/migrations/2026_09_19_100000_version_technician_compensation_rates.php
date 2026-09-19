<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing rates had no date boundary: retain that behaviour, amounts and flags.
        // This is a baseline, not an inferred date when a person started employment.
        foreach (['employee_salary_rates', 'lab_technician_rates'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->date('effective_from')->default('1900-01-01'));
        }
        Schema::table('employee_salary_rates', function (Blueprint $table): void {
            $table->dropUnique(['employee_id', 'work_type']);
            $table->unique(['employee_id', 'work_type', 'effective_from'], 'employee_salary_rate_period_unique');
        });
        Schema::table('lab_technician_rates', function (Blueprint $table): void {
            $table->dropUnique('lab_technician_rate_unique');
            $table->unique(['technician_id', 'work_type', 'component_type', 'effective_from'], 'lab_technician_rate_period_unique');
        });
    }

    public function down(): void
    {
        foreach (['employee_salary_rates' => ['employee_id', 'work_type'], 'lab_technician_rates' => ['technician_id', 'work_type', 'component_type']] as $table => $keys) {
            if (DB::table($table)->select($keys)->groupBy($keys)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException('Cannot remove technician rate history while multiple versions exist. No historical rates were deleted.');
            }
        }
        Schema::table('employee_salary_rates', function (Blueprint $table): void {
            $table->dropUnique('employee_salary_rate_period_unique');
            $table->unique(['employee_id', 'work_type']);
            $table->dropColumn('effective_from');
        });
        Schema::table('lab_technician_rates', function (Blueprint $table): void {
            $table->dropUnique('lab_technician_rate_period_unique');
            $table->unique(['technician_id', 'work_type', 'component_type'], 'lab_technician_rate_unique');
            $table->dropColumn('effective_from');
        });
    }
};
