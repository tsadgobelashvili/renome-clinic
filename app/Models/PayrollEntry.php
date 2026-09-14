<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PayrollEntry extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $entry) {
            if ($entry->getOriginal('source') === 'clinic' && $entry->getOriginal('status') === 'finalized'
                && array_diff(array_keys($entry->getDirty()), ['payout_status', 'matching_status', 'updated_at'])) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.immutable')]);
            }
        });
        static::deleting(function (self $entry) {
            if ($entry->source === 'clinic' && $entry->status === 'finalized') {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.immutable')]);
            }
        });
    }

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
