<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ExpenseSubcategory extends Model
{
    protected $fillable = ['expense_category_id', 'name', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
    }

    public function financeTransactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class);
    }

    public function partnerFinanceTransactions(): HasMany
    {
        return $this->hasMany(PartnerFinanceTransaction::class);
    }

    public function directExpenses(): HasMany
    {
        return $this->hasMany(DirectExpense::class);
    }

    public function isUsed(): bool
    {
        return $this->financeTransactions()->exists() || $this->partnerFinanceTransactions()->exists() || $this->directExpenses()->exists();
    }

    protected static function booted(): void
    {
        static::deleting(function (self $subcategory): void {
            if ($subcategory->isUsed()) {
                throw ValidationException::withMessages(['category' => __('expense-categories.used')]);
            }
        });
        static::updating(function (self $subcategory): void {
            if ($subcategory->isDirty('expense_category_id') && $subcategory->isUsed()) {
                throw ValidationException::withMessages(['expense_category_id' => __('expense-categories.used')]);
            }
        });
    }
}
