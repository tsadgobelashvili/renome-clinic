<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeSalarySettlementItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['work_date' => 'date', 'rate_amount' => 'decimal:2', 'amount_gel' => 'decimal:2'];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(EmployeeSalarySettlement::class, 'employee_salary_settlement_id');
    }
}
