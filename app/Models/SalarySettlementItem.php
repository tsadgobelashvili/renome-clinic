<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalarySettlementItem extends Model
{
    protected $fillable = [
        'salary_settlement_id', 'visit_id', 'visit_treatment_case_id',
        'revenue', 'direct_expense', 'salary_base', 'doctor_share',
        'total_value_snapshot', 'paid_amount_snapshot', 'outstanding_amount_snapshot',
        'expense_snapshot', 'base_snapshot', 'doctor_share_snapshot', 'patient_group_slug',
    ];

    protected function casts(): array
    {
        return [
            'revenue' => 'decimal:2',
            'direct_expense' => 'decimal:2',
            'salary_base' => 'decimal:2',
            'doctor_share' => 'decimal:2',
            'total_value_snapshot' => 'decimal:2',
            'paid_amount_snapshot' => 'decimal:2',
            'outstanding_amount_snapshot' => 'decimal:2',
            'expense_snapshot' => 'decimal:2',
            'base_snapshot' => 'decimal:2',
            'doctor_share_snapshot' => 'decimal:2',
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
