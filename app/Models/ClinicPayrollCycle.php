<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ClinicPayrollCycle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['payroll_date' => 'immutable_date', 'snapshot' => 'array', 'cutoff_at' => 'immutable_datetime', 'finalized_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $cycle) {
            if ($cycle->getOriginal('status') === 'finalized' && $cycle->isDirty(['payroll_date', 'status', 'snapshot', 'cutoff_at', 'finalized_at', 'finalized_by'])) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.immutable')]);
            }
        });
        static::deleting(function (self $cycle) {
            if ($cycle->status === 'finalized') {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.immutable')]);
            }
        });
    }

    public function doctorSettlements(): HasMany
    {
        return $this->hasMany(SalarySettlement::class);
    }

    public function employeeEntries(): HasMany
    {
        return $this->hasMany(PayrollEntry::class);
    }
}
