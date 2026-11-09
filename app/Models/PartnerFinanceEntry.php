<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerFinanceEntry extends Model
{
    protected $table = 'partner_finance_entries';

    protected $primaryKey = 'entry_key';

    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected static function booted(): void
    {
        static::addGlobalScope('active_receipts', fn ($query) => $query->where(fn ($q) => $q
            ->where('source_type', '!=', 'payment')
            ->orWhereIn('source_id', PartnerPatientPayment::query()->select('id'))));
    }

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'transacted_at' => 'datetime',
            'amount' => 'decimal:2',
            'from_amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
        ];
    }

    public function scopeWithCreationTimestamp(Builder $query): Builder
    {
        // The union view exposes business dates only. Read the original creation
        // timestamps without changing the view or falling back to business dates.
        return $query->selectRaw("CASE partner_finance_entries.source_type
            WHEN 'payment' THEN (SELECT created_at FROM partner_patient_payments
                WHERE id = partner_finance_entries.source_id)
            WHEN 'transaction' THEN (SELECT created_at FROM partner_finance_transactions
                WHERE id = partner_finance_entries.source_id)
            END AS created_at");
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function salarySettlement(): BelongsTo
    {
        return $this->belongsTo(SalarySettlement::class);
    }
}
