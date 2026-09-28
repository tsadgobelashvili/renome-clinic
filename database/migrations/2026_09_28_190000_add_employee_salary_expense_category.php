<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $categories = DB::table('expense_categories');
            $parent = (clone $categories)->where('classification_dimension', 'direction')->where('classification_code', 'employee_salaries')->whereNull('parent_id')->value('id');
            if (! $parent) {
                $parent = $categories->insertGetId(['name' => 'თანამშრომლების ხელფასები', 'classification_dimension' => 'direction',
                    'classification_code' => 'employee_salaries', 'active' => true, 'sort_order' => 70, 'created_at' => now(), 'updated_at' => now()]);
            }
            $child = (clone $categories)->where('parent_id', $parent)->where('classification_code', 'salary')->value('id');
            if (! $child) {
                $legacy = (clone $categories)->whereNull('parent_id')->where('classification_dimension', 'type')->where('classification_code', 'salary')->value('id');
                $child = $categories->insertGetId(['name' => 'ხელფასი', 'classification_dimension' => 'type', 'classification_code' => 'salary',
                    'parent_id' => $parent, 'legacy_type_id' => $legacy, 'active' => true, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
            // Reclassify only linked non-technician employee payroll; monetary fields stay untouched.
            foreach (['payroll_entry_id' => 'payroll_entries', 'employee_salary_settlement_id' => 'employee_salary_settlements'] as $key => $table) {
                $ids = DB::table($table.' as payroll')->join('employees as e', 'e.id', '=', 'payroll.employee_id')
                    ->join('employee_positions as p', 'p.id', '=', 'e.position_id')->where('p.is_technician', false)->select('payroll.id');
                DB::table('finance_transactions')->whereIn($key, $ids)->update(['expense_direction_id' => $parent, 'expense_type_id' => $child]);
            }
        });
        app(\App\Services\ExpenseDimensions::class)->reset();
    }

    public function down(): void
    {
        // Retain the category and historical classifications; their prior values cannot be inferred safely.
    }
};
