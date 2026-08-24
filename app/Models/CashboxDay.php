<?php

namespace App\Models;

use App\Support\CashboxManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashboxDay extends Model
{
    protected $fillable = [
        'date', 'opening_balance', 'expected_closing_balance', 'actual_closing_balance',
        'cash_withdrawal_total', 'carry_forward_balance', 'status', 'automatically_closed',
        'opened_at', 'closed_at', 'closed_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date', 'opening_balance' => 'decimal:2', 'expected_closing_balance' => 'decimal:2',
            'actual_closing_balance' => 'decimal:2', 'cash_withdrawal_total' => 'decimal:2',
            'carry_forward_balance' => 'decimal:2', 'automatically_closed' => 'boolean',
            'opened_at' => 'datetime', 'closed_at' => 'datetime',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashboxTransaction::class);
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
