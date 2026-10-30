<?php

namespace App\Models;

use App\Services\PurchaseCatalogDeletion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class PurchaseProduct extends Model
{
    protected $fillable = ['supplier_id', 'identity_key', 'name', 'normalized_name', 'rs_product_code', 'supplier_product_code', 'expense_direction_id', 'expense_type_id', 'purchase_product_group_id', 'catalog_origin', 'manually_retained'];

    protected function casts(): array
    {
        return ['manually_retained' => 'boolean'];
    }

    public function delete()
    {
        if (! $this->exists) {
            return parent::delete();
        }

        return DB::transaction(function () {
            Gate::authorize('delete', $this);
            $locked = self::lockForUpdate()->findOrFail($this->getKey());
            $deletion = app(PurchaseCatalogDeletion::class);
            $deletion->assertDeletable($locked);
            $snapshot = $deletion->snapshot($locked);
            DB::table('purchase_product_aliases')->where('purchase_product_id', $locked->id)->delete();
            $deleted = parent::delete();
            if (! $deleted) {
                throw ValidationException::withMessages(['product' => 'პროდუქტის წაშლა გაუქმდა.']);
            }
            $deletion->auditProduct($snapshot, auth()->user());

            return $deleted;
        });
    }

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
