<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class LabSalarySettlement extends Model
{
    protected $fillable = ['technician_id', 'period_start', 'period_end', 'salary_total', 'actual_paid_gel', 'israeli_cash_gel', 'status', 'settled_at', 'undone_at', 'created_by', 'undone_by'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'salary_total' => 'decimal:2', 'actual_paid_gel' => 'decimal:2', 'israeli_cash_gel' => 'decimal:2', 'settled_at' => 'datetime', 'undone_at' => 'datetime'];
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(LabSalarySettlementItem::class);
    }

    public function financeExpense(): HasOne
    {
        return $this->hasOne(FinanceTransaction::class);
    }
}
