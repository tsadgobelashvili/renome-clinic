<?php

namespace App\Models;

use App\Services\ExpenseDimensions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function isUsed(): bool
    {
        if ($this->children()->get()->contains(fn ($child) => $child->isUsed()) || self::where('legacy_type_id', $this->id)->exists()) {
            return true;
        }
        if (BankCategory::where('expense_category_id', $this->id)->where(fn ($query) => $query->whereHas('transactions')
            ->orWhereExists(fn ($rules) => $rules->selectRaw('1')->from('bank_categorization_rules')->whereColumn('bank_categorization_rules.bank_category_id', 'bank_categories.id')))->exists()) {
            return true;
        }
        foreach ([FinanceTransaction::class, PartnerFinanceTransaction::class, DirectExpense::class, BankTransaction::class, BankCategorizationRule::class] as $model) {
            if ($model::where('expense_category_id', $this->id)->orWhere('expense_direction_id', $this->id)->orWhere('expense_type_id', $this->id)->exists()) {
                return true;
            }
        }

        if (PurchaseProduct::where('expense_direction_id', $this->id)->exists()) {
            return true;
        }

        return $this->subcategories()->whereHas('financeTransactions')->exists()
            || $this->subcategories()->whereHas('partnerFinanceTransactions')->exists()
            || $this->subcategories()->whereHas('directExpenses')->exists();
    }

    protected static function booted(): void
    {
        static::saving(function (self $record): void {
            if ($record->exists && $record->isDirty('parent_id') && $record->isUsed()) {
                throw ValidationException::withMessages(['parentId' => __('expense-categories.used')]);
            }
            if ($record->parent_id && ($record->classification_dimension !== 'type'
                || ! self::whereKey($record->parent_id)->where('classification_dimension', 'direction')->whereNull('parent_id')->exists())) {
                throw ValidationException::withMessages(['parentId' => __('expense-dimensions.invalid')]);
            }
        });
        static::saved(fn () => app(ExpenseDimensions::class)->reset());
        static::deleted(fn () => app(ExpenseDimensions::class)->reset());
        static::deleting(function (self $category): void {
            if ($category->isUsed()) {
                throw ValidationException::withMessages(['category' => __('expense-categories.used')]);
            }
            BankCategory::where('expense_category_id', $category->id)->update(['expense_category_id' => null]);
            $category->children->each->delete();
            $category->subcategories()->delete();
        });
    }
}
