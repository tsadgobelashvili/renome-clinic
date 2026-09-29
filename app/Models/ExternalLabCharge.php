<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ExternalLabCharge extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(function (self $charge) {
            if ($charge->isDirty(['unit_rate', 'doctor_id', 'lab_main_work_id']) || $charge->deductions()->exists()) {
                throw ValidationException::withMessages(['external_work' => __('salary-payout.immutable')]);
            }
        });
        static::deleting(function (self $charge) {
            if ($charge->deductions()->exists()) {
                throw ValidationException::withMessages(['external_work' => __('salary-payout.immutable')]);
            }
        });
    }

    public function work()
    {
        return $this->belongsTo(LabMainWork::class, 'lab_main_work_id');
    }

    public function deductions()
    {
        return $this->hasMany(ExternalLabDeduction::class);
    }

    public function remaining(): float
    {
        return max(0, round((float) $this->amount - (float) ($this->deducted ?? $this->deductions()->sum('amount')), 2));
    }
}
