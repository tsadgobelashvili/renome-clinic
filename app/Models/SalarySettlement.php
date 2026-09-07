<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SalarySettlement extends Model
{
    protected $fillable = [
        'doctor_id', 'period_start', 'period_end', 'settled_at', 'currency',
        'payment_currency', 'payment_exchange_rate', 'payment_amount',
        'calculated_usd', 'actual_paid_usd', 'difference_usd', 'opening_carry_usd',
        'closing_carry_usd', 'converted_salary_usd', 'gel_salary_basis',
        'total_paid_gel', 'israeli_gel_used', 'clinic_gel_used',
        'performed_total', 'paid_amount', 'outstanding_amount', 'direct_expense_total', 'base_total', 'percentage',
        'normal_salary_total', 'owner_split_received_total', 'salary_total',
        'status', 'created_by', 'patient_group_slug',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'settled_at' => 'datetime',
            'payment_exchange_rate' => 'decimal:6',
            'payment_amount' => 'decimal:2',
            'calculated_usd' => 'decimal:2',
            'actual_paid_usd' => 'decimal:2',
            'difference_usd' => 'decimal:2',
            'opening_carry_usd' => 'decimal:2',
            'closing_carry_usd' => 'decimal:2',
            'converted_salary_usd' => 'decimal:2',
            'gel_salary_basis' => 'decimal:2',
            'total_paid_gel' => 'decimal:2',
            'israeli_gel_used' => 'decimal:2',
            'clinic_gel_used' => 'decimal:2',
            'performed_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'direct_expense_total' => 'decimal:2',
            'base_total' => 'decimal:2',
            'percentage' => 'decimal:2',
            'normal_salary_total' => 'decimal:2',
            'owner_split_received_total' => 'decimal:2',
            'salary_total' => 'decimal:2',
        ];
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalarySettlementItem::class);
    }

    public function partnerFinanceTransaction(): HasOne
    {
        return $this->hasOne(PartnerFinanceTransaction::class);
    }

    public function clinicFinanceTransaction(): HasOne
    {
        return $this->hasOne(FinanceTransaction::class);
    }

    public function outgoingOwnerShares(): HasMany
    {
        return $this->hasMany(OwnerSalaryShare::class, 'source_salary_settlement_id');
    }

    public function incomingOwnerShares(): HasMany
    {
        return $this->hasMany(OwnerSalaryShare::class, 'recipient_salary_settlement_id');
    }

    public function getLastIncludedItemAttribute(): ?SalarySettlementItem
    {
        $items = $this->relationLoaded('items')
            ? $this->items
            : $this->items()->with('visit.patient')->get();

        return $items
            ->sortByDesc(fn (SalarySettlementItem $item): string => implode('|', [
                $item->visit?->visit_date?->format('Y-m-d') ?? $item->labMainWork?->labCase?->case_date?->format('Y-m-d') ?? '0000-00-00',
                str_pad((string) ($item->visit_id ?? $item->labMainWork?->lab_case_id), 20, '0', STR_PAD_LEFT),
                str_pad((string) $item->visit_treatment_case_id, 20, '0', STR_PAD_LEFT),
            ]))
            ->first();
    }
}
