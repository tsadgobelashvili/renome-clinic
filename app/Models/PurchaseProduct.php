<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseProduct extends Model
{
    protected $fillable = ['supplier_id', 'identity_key', 'name', 'normalized_name', 'rs_product_code', 'supplier_product_code', 'expense_direction_id', 'expense_type_id', 'purchase_product_group_id'];

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_type_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(PurchaseProductGroup::class, 'purchase_product_group_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function direction(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_direction_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }
}
