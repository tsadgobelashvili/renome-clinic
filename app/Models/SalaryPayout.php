<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class SalaryPayout extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['total_gel' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        $guard = fn () => throw ValidationException::withMessages(['allocations' => __('salary-payout.immutable')]);
        static::updating($guard);
        static::deleting($guard);
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SalarySettlement::class, 'salary_settlement_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(SalaryPayoutAllocation::class);
    }
}
