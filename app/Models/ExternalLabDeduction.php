<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class ExternalLabDeduction extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        $guard = fn () => throw ValidationException::withMessages(['external_work' => __('salary-payout.immutable')]);
        static::updating($guard);
        static::deleting($guard);
    }

    public function charge()
    {
        return $this->belongsTo(ExternalLabCharge::class, 'external_lab_charge_id');
    }
}
