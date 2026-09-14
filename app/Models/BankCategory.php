<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class BankCategory extends Model
{
    public const TREATMENTS = ['income', 'expense', 'transfer', 'settlement', 'exclude'];

    protected $fillable = ['name', 'active', 'sort_order', 'accounting_treatment', 'expense_category_id'];

    protected static function booted(): void
    {
        static::saving(function (self $category): void {
            $category->accounting_treatment ??= 'exclude';
            if (! in_array($category->accounting_treatment, self::TREATMENTS, true)) {
                throw ValidationException::withMessages(['accounting_treatment' => __('bank-accounting.invalid_treatment')]);
            }
        });
    }

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class);
    }
}
