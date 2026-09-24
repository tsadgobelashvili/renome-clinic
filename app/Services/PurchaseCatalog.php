<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Analytical purchasing metadata only: never writes to Finance, Cashier or Bank. */
class PurchaseCatalog
{
    public function groupOptions(): array
    {
        return PurchaseProductGroup::orderBy('name')->pluck('name', 'id')->all();
    }

    public function assignGroup(PurchaseProduct $product, ?int $groupId): void
    {
        validator(['purchase_product_group_id' => $groupId], [
            'purchase_product_group_id' => 'nullable|integer|exists:purchase_product_groups,id',
        ])->validate();
        $product->update(['purchase_product_group_id' => $groupId]);
    }

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
        $this->assignClassification($product, $directionId, $product->expense_direction_id == $directionId ? $product->expense_type_id : null);
    }

    public function subcategoryOptions(?int $directionId, ?int $selected = null): array
    {
        return app(ExpenseDimensions::class)->childOptions($directionId, $selected);
    }

    public function createSubcategory(int $directionId, string $name): int
    {
        validator(['name' => trim($name)], ['name' => 'required|string|max:255'])->validate();
        if (! array_key_exists($directionId, $this->directionOptions())) {
            throw ValidationException::withMessages(['expense_direction_id' => __('expense-dimensions.invalid')]);
        }

        $category = new ExpenseCategory;
        $category->forceFill(['name' => trim($name), 'parent_id' => $directionId, 'classification_dimension' => 'type', 'active' => true, 'sort_order' => 0])->save();

        return $category->id;
    }

    public function assignClassification(PurchaseProduct $product, ?int $directionId, ?int $subcategoryId): void
    {
        if ($directionId !== null && ! array_key_exists($directionId, $this->directionOptions($product->expense_direction_id))) {
            throw ValidationException::withMessages(['expense_direction_id' => __('expense-dimensions.invalid')]);
        }
        $data = ['expense_direction_id' => $directionId, 'expense_type_id' => $subcategoryId];
        app(ExpenseDimensions::class)->validate($data, $product);
        $product->update($data);
    }

    public function breakdown(int $purchaseId): Collection
    {
        return DB::table('purchase_items as i')->leftJoin('purchase_products as p', 'p.id', '=', 'i.purchase_product_id')
            ->leftJoin('expense_categories as d', 'd.id', '=', 'p.expense_direction_id')
            ->where('i.purchase_id', $purchaseId)->groupBy('d.id', 'd.name')
            ->selectRaw('d.id, d.name, SUM(i.line_total) AS amount')->orderBy('d.name')->get();
    }
}
