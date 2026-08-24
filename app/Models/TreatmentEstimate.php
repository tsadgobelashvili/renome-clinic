<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;

class TreatmentEstimate extends Model
{
    /** @var array{planned_amount: float, executed_amount: float, paid_amount: float, remaining_amount: float}|null */
    protected ?array $progressSummaryCache = null;

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'visit_id',
        'estimate_date',
        'estimated_duration',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'estimate_date' => 'date',
        ];
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function options(): HasMany
    {
        return $this->hasMany(TreatmentEstimateOption::class);
    }

    public function items(): HasManyThrough
    {
        return $this->hasManyThrough(TreatmentEstimateItem::class, TreatmentEstimateOption::class);
    }

    public function treatmentVisits(): HasMany
    {
        return $this->hasMany(Visit::class, 'treatment_estimate_id');
    }

    /** @return array{planned_amount: float, executed_amount: float, paid_amount: float, remaining_amount: float} */
    public function getProgressSummary(): array
    {
        if ($this->progressSummaryCache !== null) {
            return $this->progressSummaryCache;
        }

        $optionId = $this->treatmentVisits()
            ->where('visit_type', 'treatment')
            ->whereNotNull('treatment_estimate_option_id')
            ->value('treatment_estimate_option_id');
        $option = $optionId
            ? $this->options()->whereKey($optionId)->with('items')->first()
            : $this->options()->with('items')->oldest('id')->first();

        $executedAmount = round((float) $this->treatmentVisits()
            ->where('visit_type', 'treatment')
            ->selectRaw('COALESCE(SUM(total_price - discount_amount), 0) AS total')
            ->toBase()->value('total'), 2);
        $paidAmount = round((float) DB::table('payments')
            ->join('visits', 'visits.id', '=', 'payments.visit_id')
            ->where('visits.treatment_estimate_id', $this->getKey())
            ->where('visits.visit_type', 'treatment')
            ->sum('payments.amount'), 2);

        return $this->progressSummaryCache = [
            'planned_amount' => round((float) ($option?->final_amount ?? 0), 2),
            'executed_amount' => $executedAmount,
            'paid_amount' => $paidAmount,
            'remaining_amount' => round(max(0, $executedAmount - $paidAmount), 2),
        ];
    }

    public function getTotalAmountAttribute(): float
    {
        if ($this->relationLoaded('items')) {
            return round((float) $this->items->sum('line_total'), 2);
        }

        return round((float) $this->options->sum('total_amount'), 2);
    }
}
