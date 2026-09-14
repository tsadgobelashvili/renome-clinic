<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class SalaryPayoutAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'gel_equivalent' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        $guard = fn () => throw ValidationException::withMessages(['allocations' => __('salary-payout.immutable')]);
        static::updating($guard);
        static::deleting($guard);
    }
}
