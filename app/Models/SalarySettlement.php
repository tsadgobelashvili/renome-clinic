<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalarySettlement extends Model
{
    protected $fillable = [
        'doctor_id', 'period_start', 'period_end', 'settled_at', 'currency',
        'performed_total', 'direct_expense_total', 'base_total', 'percentage',
        'salary_total', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'settled_at' => 'datetime',
            'performed_total' => 'decimal:2',
            'direct_expense_total' => 'decimal:2',
            'base_total' => 'decimal:2',
            'percentage' => 'decimal:2',
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

    public function getLastIncludedItemAttribute(): ?SalarySettlementItem
    {
        $items = $this->relationLoaded('items')
            ? $this->items
            : $this->items()->with('visit.patient')->get();

        return $items
            ->sortByDesc(fn (SalarySettlementItem $item): string => implode('|', [
                $item->visit?->visit_date?->format('Y-m-d') ?? '0000-00-00',
                str_pad((string) $item->visit_id, 20, '0', STR_PAD_LEFT),
                str_pad((string) $item->visit_treatment_case_id, 20, '0', STR_PAD_LEFT),
            ]))
            ->first();
    }
}
