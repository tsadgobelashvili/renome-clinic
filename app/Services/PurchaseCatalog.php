<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\Product;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Analytical purchasing metadata only: never writes to Finance, Cashier or Bank. */
class PurchaseCatalog
{
    public function groupOptions(): array
    {
        return PurchaseProductGroup::orderBy('name')->get()->reject(fn ($group) => $this->isUnclassifiedName($group->name))->pluck('name', 'id')->all();
    }

    public function directionGroupOptions(?int $direction): array
    {
        if (! $direction) {
            return [];
        }

        return PurchaseProductGroup::where(function ($query) use ($direction): void {
            $query->where('expense_direction_id', $direction)->orWhere(function ($query) use ($direction): void {
                $query->whereNull('expense_direction_id')
                    ->whereHas('products', fn ($q) => $q->where('expense_direction_id', $direction))
                    ->whereDoesntHave('products', fn ($q) => $q->whereNotNull('expense_direction_id')->where('expense_direction_id', '!=', $direction));
            });
        })->orderBy('name')->get()->reject(fn ($group) => $this->isUnclassifiedName($group->name))->pluck('name', 'id')->all();
    }

    public function createGroup(array $data): PurchaseProductGroup
    {
        Gate::authorize('create', PurchaseProductGroup::class);

        return DB::transaction(function () use ($data): PurchaseProductGroup {
            $this->validateGroup($data);

            return PurchaseProductGroup::create(['name' => trim($data['name']), 'expense_direction_id' => $data['expense_direction_id']]);
        });
    }

    public function updateGroup(PurchaseProductGroup $group, array $data): PurchaseProductGroup
    {
        Gate::authorize('update', $group);
        $this->validateGroup($data, $group);
        if ($group->products()->whereNotNull('expense_direction_id')->where('expense_direction_id', '!=', $data['expense_direction_id'])->exists()) {
            throw ValidationException::withMessages(['expense_direction_id' => __('product-groups.incompatible')]);
        }
        $group->update(['name' => trim($data['name']), 'expense_direction_id' => $data['expense_direction_id']]);

        return $group;
    }

    private function validateGroup(array $data, ?PurchaseProductGroup $group = null): void
    {
        validator($data, ['name' => 'required|string|max:255', 'expense_direction_id' => 'required|integer'])->validate();
        if (! array_key_exists($data['expense_direction_id'], $this->directionOptions($group?->expense_direction_id))) {
            throw ValidationException::withMessages(['expense_direction_id' => __('expense-dimensions.invalid')]);
        }
        // Keep the existing global unique-name rule, including legacy groups without Direction.
        if (! $this->groupNameAvailable($data['name'], $group?->id)) {
            throw ValidationException::withMessages(['name' => __('validation.unique', ['attribute' => __('product-groups.name')])]);
        }
    }

    public function groupNameAvailable(string $name, ?int $ignore = null): bool
    {
        $name = Product::normalizeName($name);

        return ! $this->isUnclassifiedName($name) && ! PurchaseProductGroup::whereKeyNot($ignore ?? 0)->get()
            ->contains(fn ($existing) => Product::normalizeName($existing->name) === $name);
    }

    public function assignCatalogGroup(PurchaseProduct $product, ?int $group): void
    {
        Gate::authorize('update', $product);
        if ($group !== null && ! array_key_exists($group, $this->directionGroupOptions($product->expense_direction_id))) {
            throw ValidationException::withMessages(['purchase_product_group_id' => __('product-groups.incompatible')]);
        }
        $this->assignGroup($product, $group);
    }

    public function assignCatalogDirection(PurchaseProduct $product, ?int $direction): void
    {
        $this->assignCatalogClassification($product, $direction, $product->expense_direction_id == $direction ? $product->expense_type_id : null);
    }

    public function assignCatalogClassification(PurchaseProduct $product, ?int $direction, ?int $subcategory): void
    {
        Gate::authorize('update', $product);
        DB::transaction(function () use ($product, $direction, $subcategory): void {
            // Resolve before editing: a legacy group's Direction must not drift with this product.
            $groupDirection = $this->groupDirection($product->purchase_product_group_id);
            $changed = $product->expense_direction_id != $direction;
            $this->assignClassification($product, $direction, $subcategory);
            if ($changed && $product->purchase_product_group_id && ($direction === null || $groupDirection !== $direction)) {
                $product->update(['purchase_product_group_id' => null]);
            }
        });
    }

    public function createGroupForProduct(PurchaseProduct $product, array $data): PurchaseProductGroup
    {
        Gate::authorize('update', $product);
        Gate::authorize('create', PurchaseProductGroup::class);

        return DB::transaction(function () use ($product, $data): PurchaseProductGroup {
            $product = PurchaseProduct::lockForUpdate()->findOrFail($product->id);
            if (! $product->expense_direction_id || ($data['expense_direction_id'] ?? null) != $product->expense_direction_id) {
                throw ValidationException::withMessages(['expense_direction_id' => __('product-groups.direction_first')]);
            }
            $group = $this->createGroup($data);
            $this->assignCatalogGroup($product, $group->id);

            return $group;
        });
    }

    public function isUnclassifiedName(string $name): bool
    {
        return in_array(mb_strtolower(trim($name)), ['unclassified', 'needs review', 'დასაზუსტებელია', 'უკატეგორიო'], true);
    }

    /** Resolve a group default only when its existing product classifications agree. */
    public function groupDirection(?int $groupId): ?int
    {
        if (! $groupId) {
            return null;
        }
        $explicit = PurchaseProductGroup::whereKey($groupId)->value('expense_direction_id');
        if ($explicit !== null) {
            return (int) $explicit;
        }
        $directions = PurchaseProduct::where('purchase_product_group_id', $groupId)
            ->whereNotNull('expense_direction_id')->distinct()->pluck('expense_direction_id');

        return $directions->count() === 1 ? (int) $directions->first() : null;
    }

    public function productDirection(PurchaseProduct $product): ?int
    {
        return $product->expense_direction_id ?? $this->groupDirection($product->purchase_product_group_id);
    }

    public function createManual(array $data): PurchaseProduct
    {
        Gate::authorize('create', PurchaseProduct::class);
        $data['name'] = trim($data['name'] ?? '');
        validator($data, [
            'name' => 'required|string|max:255|not_in:Unclassified,დასაზუსტებელია',
            'supplier_id' => 'nullable|integer|exists:suppliers,id',
            'supplier_product_code' => 'nullable|string|max:255',
            'purchase_product_group_id' => 'nullable|integer|exists:purchase_product_groups,id',
        ])->validate();
        if (filled($data['purchase_product_group_id'] ?? null)
            && ! array_key_exists($data['purchase_product_group_id'], $this->groupOptions())) {
            throw ValidationException::withMessages(['purchase_product_group_id' => __('expense-dimensions.invalid')]);
        }
        $direction = filled($data['expense_direction_id'] ?? null) ? (int) $data['expense_direction_id']
            : $this->groupDirection($data['purchase_product_group_id'] ?? null);
        if ($direction && ! array_key_exists($direction, $this->directionOptions())) {
            throw ValidationException::withMessages(['expense_direction_id' => __('expense-dimensions.invalid')]);
        }
        if (filled($data['purchase_product_group_id'] ?? null)) {
            $groupDirection = $this->groupDirection((int) $data['purchase_product_group_id']);
            if ($groupDirection !== null && $groupDirection !== $direction) {
                throw ValidationException::withMessages(['purchase_product_group_id' => __('product-groups.incompatible')]);
            }
        }

        return DB::transaction(function () use ($data, $direction): PurchaseProduct {
            $supplier = filled($data['supplier_id'] ?? null) ? (int) $data['supplier_id'] : null;
            $code = filled($data['supplier_product_code'] ?? null) ? trim($data['supplier_product_code']) : null;
            $name = Product::normalizeName($data['name']);
            $identity = $code !== null ? 'supplier|'.$code : 'name|'.$name;

            return PurchaseProduct::firstOrCreate(['identity_key' => hash('sha256', ($supplier ?? 'manual').'|'.$identity)], [
                'supplier_id' => $supplier, 'name' => $data['name'], 'normalized_name' => $name,
                'supplier_product_code' => $code, 'purchase_product_group_id' => $data['purchase_product_group_id'] ?? null,
                'expense_direction_id' => $direction,
            ]);
        });
    }

    public function assignGroup(PurchaseProduct $product, ?int $groupId): void
    {
        validator(['purchase_product_group_id' => $groupId], [
            'purchase_product_group_id' => 'nullable|integer|exists:purchase_product_groups,id',
        ])->validate();
        if ($groupId !== null && $groupId !== $product->purchase_product_group_id && ! array_key_exists($groupId, $this->groupOptions())) {
            throw ValidationException::withMessages(['purchase_product_group_id' => __('expense-dimensions.invalid')]);
        }
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

        // Keep established supplier/code identities first. Reuse a uniquely matching manual
        // entry only when no supplier-specific mapping exists; ambiguous names remain separate.
        $known = PurchaseProduct::where('identity_key', hash('sha256', $supplierId.'|'.$identity))->first();
        if ($known) {
            return $known;
        }
        $alias = DB::table('purchase_product_aliases')->where('identity_key', hash('sha256', $supplierId.'|'.$identity))->value('purchase_product_id');
        if ($alias) {
            return PurchaseProduct::findOrFail($alias);
        }
        $manual = PurchaseProduct::whereNull('supplier_id')->where('normalized_name', $normalized)
            ->where(fn ($query) => $query->whereNull('rs_product_code')->orWhere('rs_product_code', $rsCode))
            ->where(fn ($query) => $query->whereNull('supplier_product_code')->orWhere('supplier_product_code', $supplierCode))
            ->limit(2)->get();
        if ($manual->count() === 1) {
            $aliasKey = hash('sha256', $supplierId.'|'.$identity);
            DB::table('purchase_product_aliases')->insertOrIgnore([
                'supplier_id' => $supplierId, 'identity_key' => $aliasKey, 'purchase_product_id' => $manual->first()->id,
            ]);

            return PurchaseProduct::findOrFail(DB::table('purchase_product_aliases')->where('identity_key', $aliasKey)->value('purchase_product_id'));
        }

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
