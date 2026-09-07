<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $employees = DB::table('employees')->where('salary_modeler', true)->where('salary_main_technician', false)->pluck('id');
            foreach ($employees as $employeeId) {
                foreach (['zircon' => 'zircon_modeling', 'pmma' => 'pmma_modeling', 'individual_abutment' => 'abutment_modeling'] as $old => $new) {
                    if (! DB::table('employee_salary_rates')->where('employee_id', $employeeId)->where('work_type', $new)->exists()) {
                        DB::table('employee_salary_rates')->where('employee_id', $employeeId)->where('work_type', $old)->update(['work_type' => $new]);
                    }
                }
            }
        });
    }

    public function down(): void
    {
        // Retain valid role-specific settings; reversing would conflate main and modeling rates.
    }
};
