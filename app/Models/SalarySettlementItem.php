<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalarySettlementItem extends Model
{
    protected $fillable = [
        'salary_settlement_id', 'visit_id', 'visit_treatment_case_id',
        'revenue', 'direct_expense', 'salary_base', 'doctor_share',
    ];

    protected function casts(): array
    {
        return [
            'revenue' => 'decimal:2',
            'direct_expense' => 'decimal:2',
            'salary_base' => 'decimal:2',
            'doctor_share' => 'decimal:2',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(SalarySettlement::class, 'salary_settlement_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function visitTreatmentCase(): BelongsTo
    {
        return $this->belongsTo(VisitTreatmentCase::class);
    }
}
