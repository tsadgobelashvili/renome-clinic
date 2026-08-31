<?php

namespace App\Models;

use App\Support\CashboxManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashboxDay extends Model
{
    protected $fillable = [
        'date', 'opening_balance', 'opening_balance_usd', 'expected_closing_balance', 'expected_closing_balance_usd',
        'actual_closing_balance', 'actual_closing_balance_usd', 'cash_withdrawal_total', 'cash_withdrawal_total_usd',
        'carry_forward_balance', 'carry_forward_balance_usd', 'status', 'automatically_closed',
        'opened_at', 'closed_at', 'closed_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'opening_balance' => 'decimal:2', 'opening_balance_usd' => 'decimal:2',
            'expected_closing_balance' => 'decimal:2', 'expected_closing_balance_usd' => 'decimal:2',
            'actual_closing_balance' => 'decimal:2', 'actual_closing_balance_usd' => 'decimal:2',
            'cash_withdrawal_total' => 'decimal:2', 'cash_withdrawal_total_usd' => 'decimal:2',
            'carry_forward_balance' => 'decimal:2', 'carry_forward_balance_usd' => 'decimal:2', 'automatically_closed' => 'boolean',
            'opened_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashboxTransaction::class);
    }

    public function outgoingCashTransfers(): HasMany
    {
        return $this->hasMany(CashTransfer::class, 'source_cashbox_day_id');
    }

    public function incomingCashTransfers(): HasMany
    {
        return $this->hasMany(CashTransfer::class, 'destination_cashbox_day_id');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function summary(): array
    {
        return app(CashboxManager::class)->summary($this);
    }
}
