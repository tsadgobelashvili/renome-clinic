<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class IsraeliSalaryPayoutCarryEntry extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_gel' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        $immutable = fn () => throw ValidationException::withMessages(['allocations' => __('salary-payout.immutable')]);
        static::updating($immutable);
        static::deleting($immutable);
    }
}
