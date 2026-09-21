<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class EmployeeAdvanceEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'expense_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['advance' => 'დადასტურებული ავანსის ჩანაწერის შეცვლა შეუძლებელია.']));
        static::deleting(fn () => throw ValidationException::withMessages(['advance' => 'დადასტურებული ავანსის ჩანაწერის წაშლა შეუძლებელია.']));
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_direction_id');
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_type_id');
    }
}
