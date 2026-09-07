<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalaryRate extends Model
{
    protected $fillable = ['employee_id', 'work_type', 'amount', 'basis', 'is_active'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public static function workTypes(): array
    {
        return [
            'zircon' => __('employees.salary.zircon_main'), 'pmma' => __('employees.salary.pmma_main'), 'main_other' => __('employees.salary.main_other'),
            'zircon_modeling' => __('employees.salary.zircon_modeling'), 'pmma_modeling' => __('employees.salary.pmma_modeling'),
            'abutment_modeling' => __('employees.salary.abutment_modeling'),
            'milling' => __('lab.additional_types.milling'),
            'individual_abutment' => __('lab.additional_types.individual_abutment'),
            'titanium_bar_modeling' => __('lab.additional_types.titanium_bar_modeling'),
            'other' => __('lab.additional_types.other'),
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
