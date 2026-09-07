<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class EmployeeSalarySettlement extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['period_from' => 'date', 'period_until' => 'date', 'settled_at' => 'datetime', 'undone_at' => 'datetime', 'total_gel' => 'decimal:2', 'current_salary_gel' => 'decimal:2', 'opening_carry_gel' => 'decimal:2', 'closing_carry_gel' => 'decimal:2', 'actual_paid_gel' => 'decimal:2', 'clinic_cash_gel' => 'decimal:2', 'israeli_cash_gel' => 'decimal:2', 'settings_snapshot' => 'array'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function financeExpense(): HasOne
    {
        return $this->hasOne(FinanceTransaction::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EmployeeSalarySettlementItem::class);
    }
}
