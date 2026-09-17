<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Analytical purchasing metadata only: never writes to Finance, Cashier or Bank. */
class PurchaseCatalog
{
    public function directionOptions(?int $selected = null): array
    {
        $dimensions = app(ExpenseDimensions::class);

        return $dimensions->registry()->filter(fn ($row) => $row->classification_dimension === 'direction'
            && ! $row->parent_id && ($row->active || $row->id === $selected))
            ->sortBy('sort_order')->mapWithKeys(fn ($row) => [$row->id => ExpenseDimensions::label($row)])->all();
    }

    public function resolve(int $supplierId, string $name, ?string $rsCode = null, ?string $supplierCode = null): PurchaseProduct
    {
        $name = trim($name);
        $rsCode = filled($rsCode) ? trim($rsCode) : null;
        $supplierCode = filled($supplierCode) ? trim($supplierCode) : null;
        $normalized = Product::normalizeName($name);
        $identity = $rsCode !== null ? 'rs|'.$rsCode : ($supplierCode !== null ? 'supplier|'.$supplierCode : 'name|'.$normalized);

        return PurchaseProduct::query()->firstOrCreate([
            'identity_key' => hash('sha256', $supplierId.'|'.$identity),
        ], [
            'supplier_id' => $supplierId, 'name' => $name, 'normalized_name' => $normalized,
            'rs_product_code' => $rsCode, 'supplier_product_code' => $supplierCode,
        ]);
    }

    public function assignDirection(PurchaseProduct $product, ?int $directionId): void
    {
        if ($directionId !== null && ! array_key_exists($directionId, $this->directionOptions($product->expense_direction_id))) {
            throw ValidationException::withMessages(['expense_direction_id' => __('expense-dimensions.invalid')]);
        }
        $product->update(['expense_direction_id' => $directionId]);
    }

    public function breakdown(int $purchaseId): Collection
    {
        return DB::table('purchase_items as i')->leftJoin('purchase_products as p', 'p.id', '=', 'i.purchase_product_id')
            ->leftJoin('expense_categories as d', 'd.id', '=', 'p.expense_direction_id')
            ->where('i.purchase_id', $purchaseId)->groupBy('d.id', 'd.name')
            ->selectRaw('d.id, d.name, SUM(i.line_total) AS amount')->orderBy('d.name')->get();
    }
}
