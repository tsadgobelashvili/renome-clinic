<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class ExpenseCategory extends Model
{
    protected $fillable = ['name', 'active', 'sort_order'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'sort_order' => 'integer'];
    }

    public function subcategories(): HasMany
    {
        return $this->hasMany(ExpenseSubcategory::class)->orderBy('sort_order')->orderBy('name');
    }

    public function isUsed(): bool
    {
        if (BankCategory::where('expense_category_id', $this->id)->where(fn ($query) => $query->whereHas('transactions')
            ->orWhereExists(fn ($rules) => $rules->selectRaw('1')->from('bank_categorization_rules')->whereColumn('bank_categorization_rules.bank_category_id', 'bank_categories.id')))->exists()) {
            return true;
        }
        foreach ([FinanceTransaction::class, PartnerFinanceTransaction::class, DirectExpense::class, BankTransaction::class, BankCategorizationRule::class] as $model) {
            if ($model::where('expense_category_id', $this->id)->exists()) {
                return true;
            }
        }

        return $this->subcategories()->whereHas('financeTransactions')->exists()
            || $this->subcategories()->whereHas('partnerFinanceTransactions')->exists()
            || $this->subcategories()->whereHas('directExpenses')->exists();
    }

    protected static function booted(): void
    {
        static::deleting(function (self $category): void {
            if ($category->isUsed()) {
                throw ValidationException::withMessages(['category' => __('expense-categories.used')]);
            }
            BankCategory::where('expense_category_id', $category->id)->update(['expense_category_id' => null]);
            $category->subcategories()->delete();
        });
    }
}
