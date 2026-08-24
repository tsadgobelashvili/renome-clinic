<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class TreatmentEstimateOption extends Model
{
    protected $fillable = [
        'treatment_estimate_id',
        'name',
        'estimated_duration',
        'comment',
        'discount_type',
        'discount_value',
    ];

    protected function casts(): array
    {
        return ['discount_value' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (TreatmentEstimateOption $option): void {
            if (blank($option->name)) {
                $position = $option->exists
                    ? static::query()
                        ->where('treatment_estimate_id', $option->treatment_estimate_id)
                        ->where('id', '<=', $option->getKey())
                        ->count()
                    : static::query()
                        ->where('treatment_estimate_id', $option->treatment_estimate_id)
                        ->count() + 1;

                $option->name = 'ვარიანტი '.max(1, $position);
            }

            $option->discount_type = $option->discount_type ?: 'amount';
            $option->discount_value ??= 0;

            if (! in_array($option->discount_type, ['amount', 'percent'], true)) {
                throw ValidationException::withMessages(['discount_type' => 'ფასდაკლების ტიპი არასწორია.']);
            }

            if ((float) $option->discount_value < 0) {
                throw ValidationException::withMessages(['discount_value' => 'ფასდაკლება უარყოფითი ვერ იქნება.']);
            }

            if ($option->discount_type === 'percent' && (float) $option->discount_value > 100) {
                throw ValidationException::withMessages(['discount_value' => 'ფასდაკლება 100%-ზე მეტი ვერ იქნება.']);
            }
        });
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(TreatmentEstimate::class, 'treatment_estimate_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TreatmentEstimateItem::class);
    }

    public function stages(): HasMany
    {
        return $this->hasMany(TreatmentEstimateStage::class)
            ->chaperone('option')
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    public function treatmentVisits(): HasMany
    {
        return $this->hasMany(Visit::class, 'treatment_estimate_option_id');
    }

    public function getTotalAmountAttribute(): float
    {
        if ($this->relationLoaded('items')) {
            return round((float) $this->items->sum('line_total'), 2);
        }

        return round((float) $this->items()->selectRaw('COALESCE(SUM(quantity * unit_price), 0) as total')->toBase()->value('total'), 2);
    }

    public function getDiscountAmountAttribute(): float
    {
        $discount = $this->discount_type === 'percent'
            ? $this->total_amount * (float) $this->discount_value / 100
            : (float) $this->discount_value;

        return round(min($this->total_amount, max(0, $discount)), 2);
    }

    public function getFinalAmountAttribute(): float
    {
        return round($this->total_amount - $this->discount_amount, 2);
    }

    public function getDiscountDisplayAttribute(): string
    {
        return $this->discount_type === 'percent'
            ? number_format((float) $this->discount_value, 2).'% ('.number_format($this->discount_amount, 2).' ₾)'
            : number_format($this->discount_amount, 2).' ₾';
    }
}
