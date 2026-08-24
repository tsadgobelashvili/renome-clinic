<?php

namespace App\Models;

use App\Support\RomanNumeral;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TreatmentEstimateStage extends Model
{
    protected $fillable = [
        'treatment_estimate_option_id',
        'name',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (TreatmentEstimateStage $stage): void {
            if (filled($stage->name)) {
                return;
            }

            $position = (int) $stage->sort_order;

            if ($position < 1) {
                $position = (int) static::query()
                    ->where('treatment_estimate_option_id', $stage->treatment_estimate_option_id)
                    ->max('sort_order') + 1;
            }

            $stage->name = RomanNumeral::fromInteger($position).' ეტაპი';
        });
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(TreatmentEstimateOption::class, 'treatment_estimate_option_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(TreatmentEstimateItem::class)->orderBy('id');
    }

    public function getSubtotalAttribute(): float
    {
        if ($this->relationLoaded('items')) {
            return round((float) $this->items->sum('line_total'), 2);
        }

        return round((float) $this->items()
            ->selectRaw('COALESCE(SUM(quantity * unit_price), 0) AS total')
            ->toBase()->value('total'), 2);
    }
}
