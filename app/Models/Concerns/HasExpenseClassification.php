<?php

namespace App\Models\Concerns;

use App\Models\DirectExpense;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

trait HasExpenseClassification
{
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    public function expenseSubcategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseSubcategory::class);
    }

    public function validateExpenseClassification(): void
    {
        if (! $this->expense_category_id) {
            if ($this->expense_subcategory_id) {
                throw ValidationException::withMessages(['expense_category_id' => __('expense-categories.invalid')]);
            }

            return; // Legacy and system-generated expenses retain their existing classification.
        }
        $category = ExpenseCategory::find($this->expense_category_id);
        $changed = ! $this->exists || $this->isDirty('expense_category_id');
        if (! $category || ($changed && ! $category->active)) {
            throw ValidationException::withMessages(['expense_category_id' => __('expense-categories.invalid')]);
        }
        if ($this->expense_subcategory_id) {
            $subcategory = ExpenseSubcategory::find($this->expense_subcategory_id);
            if (! $subcategory || $subcategory->expense_category_id !== $category->id
                || ((! $this->exists || $this->isDirty('expense_subcategory_id')) && (! $subcategory->active || ! $category->active))) {
                throw ValidationException::withMessages(['expense_subcategory_id' => __('expense-categories.invalid')]);
            }
        }
        if (! $this instanceof DirectExpense) {
            $this->category = 'managed_'.$category->id;
        }
    }
}
