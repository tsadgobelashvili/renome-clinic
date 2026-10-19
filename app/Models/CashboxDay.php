<?php

namespace App\Models;

use App\Support\CashboxManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashboxDay extends Model
{
    /** Activity that belongs to the drawer/card reconciliation, not setup or held cash. */
    public function scopeOperational(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereHas('transactions', fn (Builder $transactions) => $transactions->forCashier()
                ->whereIn('payment_method', ['cash', 'card'])
                ->whereIn('type', ['patient_payment', 'other_income', 'product_sale', 'expense', 'cash_withdrawal', 'cash_transfer_in', 'cash_transfer_out', 'irrigator_withdrawal'])
                ->where(fn ($q) => $q->where('type', '!=', 'cash_withdrawal')->orWhereNull('description')->orWhere('description', '!=', CashboxManager::CLOSING_HANDOVER_DESCRIPTION)))
                // Canonical patient card splits remain activity even if a legacy mirror is absent.
                ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('payments')->join('payment_splits', 'payment_splits.payment_id', '=', 'payments.id')
                    ->whereRaw('DATE(payments.payment_date) = DATE(cashbox_days.date)')->whereNull('payments.deleted_at')
                    ->where('payments.is_historical', false)->where('payment_splits.payment_method', 'card'));
        });
    }

    /** Existing closing carries (including zero) remain authoritative between activity days. */
    public function scopeCashContinuity(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->operational()->orWhere('status', 'closed'));
    }

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
