<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'period_start' => 'date', 'period_end' => 'date', 'finalized_at' => 'datetime',
            'settings_snapshot' => 'array', 'calculation_details' => 'array',
            'base_amount' => 'decimal:2', 'gross_amount' => 'decimal:2',
            'net_amount' => 'decimal:2', 'deductions' => 'decimal:2', 'employer_cost' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(EmployeePayrollSetting::class, 'employee_payroll_setting_id');
    }
}
