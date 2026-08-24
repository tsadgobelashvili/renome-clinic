<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class TreatmentEstimateItem extends Model
{
    protected $fillable = [
        'treatment_estimate_id',
        'treatment_estimate_option_id',
        'treatment_estimate_stage_id',
        'description',
        'quantity',
        'unit_price',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TreatmentEstimateItem $item): void {
            if ($item->treatment_estimate_stage_id !== null) {
                $stage = TreatmentEstimateStage::query()->find($item->treatment_estimate_stage_id);

                if (! $stage) {
                    throw ValidationException::withMessages([
                        'treatment_estimate_stage_id' => 'არჩეული ეტაპი ვერ მოიძებნა.',
                    ]);
                }

                $item->treatment_estimate_option_id = $stage->treatment_estimate_option_id;
            } elseif ($item->treatment_estimate_option_id !== null) {
                $stage = TreatmentEstimateStage::query()->firstOrCreate(
                    ['treatment_estimate_option_id' => $item->treatment_estimate_option_id],
                    ['name' => 'ძირითადი ეტაპი', 'sort_order' => 1],
                );
                $item->treatment_estimate_stage_id = $stage->getKey();
            }

            if ((float) $item->quantity <= 0) {
                throw ValidationException::withMessages([
                    'quantity' => 'რაოდენობა უნდა იყოს 0-ზე მეტი.',
                ]);
            }

            if ((float) $item->unit_price < 0) {
                throw ValidationException::withMessages([
                    'unit_price' => 'ერთეულის ფასი უარყოფითი ვერ იქნება.',
                ]);
            }
        });
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(TreatmentEstimate::class, 'treatment_estimate_id');
    }

    public function option(): BelongsTo
    {
        return $this->belongsTo(TreatmentEstimateOption::class, 'treatment_estimate_option_id');
    }

    public function stage(): BelongsTo
    {
        return $this->belongsTo(TreatmentEstimateStage::class, 'treatment_estimate_stage_id');
    }

    public function getLineTotalAttribute(): float
    {
        return round((float) $this->quantity * (float) $this->unit_price, 2);
    }
}
