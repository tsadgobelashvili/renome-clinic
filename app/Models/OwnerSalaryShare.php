<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OwnerSalaryShare extends Model
{
    protected $fillable = [
        'source_salary_settlement_id', 'recipient_salary_settlement_id', 'visit_id',
        'source_doctor_id', 'recipient_doctor_id', 'patient_group_slug', 'currency',
        'amount', 'status', 'settled_at',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'settled_at' => 'datetime'];
    }

    public function sourceSettlement(): BelongsTo
    {
        return $this->belongsTo(SalarySettlement::class, 'source_salary_settlement_id');
    }

    public function recipientSettlement(): BelongsTo
    {
        return $this->belongsTo(SalarySettlement::class, 'recipient_salary_settlement_id');
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function sourceDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'source_doctor_id');
    }

    public function recipientDoctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'recipient_doctor_id');
    }
}
