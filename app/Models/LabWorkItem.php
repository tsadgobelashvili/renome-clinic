<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class LabWorkItem extends Model
{
    public const WORK_TYPES = [
        'zirconia' => 'Zirconia', 'pmma' => 'PMMA', 'milling' => 'Milling',
        'titanium_bar_modeling' => 'Titanium Bar Modeling', 'custom_abutment' => 'Custom Abutment',
    ];

    public const COMPONENT_TYPES = ['production' => 'Production', 'design' => 'Design', 'additional' => 'Additional'];

    protected $fillable = ['lab_case_id', 'work_type', 'component_type', 'quantity', 'technician_id', 'work_date', 'status', 'rate_snapshot', 'salary_amount', 'notes'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'rate_snapshot' => 'decimal:2', 'salary_amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->quantity < 1) {
                throw ValidationException::withMessages(['quantity' => 'Quantity must be at least 1.']);
            }
            if ($item->rate_snapshot === null || $item->isDirty(['technician_id', 'work_type', 'component_type'])) {
                $rate = LabTechnicianRate::query()->where([
                    'technician_id' => $item->technician_id,
                    'work_type' => $item->work_type,
                    'component_type' => $item->component_type,
                    'is_active' => true,
                ])->value('rate_per_unit');
                if ($rate === null) {
                    throw ValidationException::withMessages(['rate_snapshot' => 'No active technician rate is configured for this work.']);
                }
                $item->rate_snapshot = $rate;
            }
            $item->salary_amount = round((float) $item->rate_snapshot * (int) $item->quantity, 2);
        });
    }

    public function labCase(): BelongsTo
    {
        return $this->belongsTo(LabCase::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function settlementItem(): HasOne
    {
        return $this->hasOne(LabSalarySettlementItem::class);
    }
}
