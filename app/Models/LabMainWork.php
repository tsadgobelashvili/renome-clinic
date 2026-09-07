<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class LabMainWork extends Model
{
    protected $fillable = ['lab_case_id', 'material', 'quantity', 'shade', 'sort_order', 'technician_id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $work): void {
            $case = $work->labCase;
            if ($work->material === 'zircon' && $case?->source === 'israeli' && blank($case->doctor_id)) {
                throw ValidationException::withMessages([
                    'doctor_id' => __('lab.israeli_doctor_required'),
                ]);
            }
        });
        static::saved(fn (self $work) => $work->syncLegacySummary());
        static::deleted(fn (self $work) => $work->syncLegacySummary());
    }

    public function labCase(): BelongsTo
    {
        return $this->belongsTo(LabCase::class);
    }

    public function technicianEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'technician_id');
    }

    public function salarySettlementItem(): HasOne
    {
        return $this->hasOne(SalarySettlementItem::class);
    }

    public function linkedVisitTreatmentCase(): HasOne
    {
        return $this->hasOne(VisitTreatmentCase::class);
    }

    private function syncLegacySummary(): void
    {
        $case = $this->labCase;
        $first = $case?->mainWorks()->orderBy('sort_order')->orderBy('id')->first();

        $case?->updateQuietly([
            'material' => $first?->material,
            'quantity' => $first?->quantity,
            'shade' => $first?->shade,
        ]);
    }
}
