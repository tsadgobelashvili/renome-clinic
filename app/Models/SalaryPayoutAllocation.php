<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class SalaryPayoutAllocation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'gel_equivalent' => 'decimal:2'];
    }

    public function financeTransaction(): HasOne
    {
        return $this->hasOne(FinanceTransaction::class);
    }

    public function partnerFinanceTransaction(): HasOne
    {
        return $this->hasOne(PartnerFinanceTransaction::class);
    }

    protected static function booted(): void
    {
        $guard = fn () => throw ValidationException::withMessages(['allocations' => __('salary-payout.immutable')]);
        static::updating($guard);
        static::deleting($guard);
    }
}
