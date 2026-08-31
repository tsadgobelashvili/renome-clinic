<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LabSalarySettlementItem extends Model
{
    protected $fillable = ['lab_salary_settlement_id', 'lab_work_item_id', 'quantity_snapshot', 'rate_snapshot', 'salary_snapshot'];

    protected function casts(): array
    {
        return ['rate_snapshot' => 'decimal:2', 'salary_snapshot' => 'decimal:2'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(LabSalarySettlement::class, 'lab_salary_settlement_id');
    }

    public function workItem(): BelongsTo
    {
        return $this->belongsTo(LabWorkItem::class);
    }
}
