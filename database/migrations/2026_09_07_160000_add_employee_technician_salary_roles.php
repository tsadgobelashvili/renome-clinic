<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            foreach (['salary_main_technician', 'salary_modeler', 'salary_milling_eligible', 'salary_abutment_eligible', 'salary_balk_eligible'] as $field) {
                $table->boolean($field)->default(false);
            }
        });
        // Preserve settled work under the new employee/rule-specific identity.
        DB::table('employee_salary_settlement_items')->whereNotNull('active_source_key')->orderBy('id')->each(function ($item): void {
            $employee = DB::table('employee_salary_settlements')->where('id', $item->employee_salary_settlement_id)->value('employee_id');
            DB::table('employee_salary_settlement_items')->where('id', $item->id)->update([
                'active_source_key' => $item->active_source_key.'-employee-'.$employee.'-'.$item->work_type,
            ]);
        });
    }

    public function down(): void
    {
        // New settlements can legitimately share a source. Keep their audit identities intact.
        Schema::table('employees', fn (Blueprint $table) => $table->dropColumn(['salary_main_technician', 'salary_modeler', 'salary_milling_eligible', 'salary_abutment_eligible', 'salary_balk_eligible']));
    }
};
