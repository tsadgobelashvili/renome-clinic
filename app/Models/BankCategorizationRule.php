<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankCategorizationRule extends Model
{
    protected $fillable = ['field', 'phrase', 'bank_category_id', 'active', 'expense_direction_id', 'expense_type_id', 'expense_category_id', 'expense_subcategory_id', 'counterparty', 'purpose_keyword', 'counterparty_account'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function expenseDirection(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_direction_id');
    }

    public function expenseType(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_type_id');
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseSubcategory::class, 'expense_subcategory_id');
    }
}
