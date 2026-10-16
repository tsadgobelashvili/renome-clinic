<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class PurchaseProductGroup extends Model
{
    protected $fillable = ['name', 'expense_direction_id'];

    public function products(): HasMany
    {
        return $this->hasMany(PurchaseProduct::class);
    }

    protected static function booted(): void
    {
        static::saving(function (self $group): void {
            $group->normalized_name = Product::normalizeName($group->name);
        });
        static::deleting(function (self $group): void {
            if ($group->products()->exists()
                || FinanceTransaction::where('purchase_product_group_id', $group->id)->exists()
                || PartnerFinanceTransaction::where('purchase_product_group_id', $group->id)->exists()) {
                throw ValidationException::withMessages(['name' => 'ჯერ გადაიტანეთ პროდუქტები სხვა ჯგუფში.']);
            }
        });
    }
}
