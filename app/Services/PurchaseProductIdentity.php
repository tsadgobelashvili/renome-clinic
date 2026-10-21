<?php

namespace App\Services;

use App\Models\Product;
use App\Models\PurchaseProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Explicit aliases preserve legacy catalog rows and original purchase-line links. */
class PurchaseProductIdentity
{
    public function visible(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('purchase_product_aliases as canonical_alias')
            ->whereColumn('canonical_alias.identity_key', 'purchase_products.identity_key')
            ->whereColumn('canonical_alias.purchase_product_id', '!=', 'purchase_products.id'));
    }

    public function canonical(PurchaseProduct $product): PurchaseProduct
    {
        $id = DB::table('purchase_product_aliases')->where('identity_key', $product->identity_key)->value('purchase_product_id');

        return $id && $id != $product->id ? PurchaseProduct::findOrFail($id) : $product;
    }

    public function same(PurchaseProduct $a, PurchaseProduct $b): bool
    {
        if (! $a->supplier_id || $a->supplier_id !== $b->supplier_id) {
            return false;
        }
        if (filled($a->rs_product_code) && filled($b->rs_product_code)) {
            return trim($a->rs_product_code) === trim($b->rs_product_code);
        }
        if (filled($a->supplier_product_code) && filled($b->supplier_product_code)) {
            return trim($a->supplier_product_code) === trim($b->supplier_product_code);
        }
        if (filled($a->rs_product_code) || filled($b->rs_product_code)
            || filled($a->supplier_product_code) || filled($b->supplier_product_code)) {
            return false;
        }

        return Product::normalizeName($a->name) === Product::normalizeName($b->name);
    }

    /** Server-owned preview; only changed rows can request propagation. */
    public function preview(array $pending, array $originals, bool $lock = false): array
    {
        Gate::authorize('update', new PurchaseProduct);
        $query = $this->visible(PurchaseProduct::query())->orderBy('id');
        $products = ($lock ? $query->lockForUpdate() : $query)->get()->keyBy('id');
        $result = [];
        foreach ($pending as $id => $row) {
            if (! isset($originals[$id]) || PurchaseClassificationBatch::selection($row) === PurchaseClassificationBatch::selection($originals[$id])) {
                continue;
            }
            $product = $products->get($id);
            if (! $product) {
                throw ValidationException::withMessages(['pendingClassifications' => __('catalog-classification.conflict')]);
            }
            $matches = $products->filter(fn ($other) => $other->id !== $product->id && $this->same($product, $other));
            if ($matches->isEmpty()) {
                continue;
            }
            $members = (clone $matches)->prepend($product);
            $conflict = false;
            foreach ($members as $left) {
                foreach ($members as $right) {
                    if (! $this->same($left, $right)) {
                        $conflict = true;
                    }
                }
            }
            foreach (['expense_direction_id', 'purchase_product_group_id', 'expense_type_id'] as $field) {
                if ($members->pluck($field)->filter(fn ($value) => $value !== null)->unique()->count() > 1) {
                    $conflict = true;
                }
                // Propagation must not overwrite an already classified counterpart.
                foreach ($matches as $match) {
                    $proposed = $pending[$id][$field] ?? ($field === 'expense_type_id' ? $product->expense_type_id : null);
                    if ($match->$field !== null && $proposed != $match->$field) {
                        $conflict = true;
                    }
                }
            }
            $result[$id] = ['name' => $product->name, 'conflict' => $conflict,
                'selection' => PurchaseClassificationBatch::selection($row), 'original' => $product->getAttributes(),
                'members' => $matches->mapWithKeys(fn ($p) => [$p->id => $p->getAttributes()])->all()];
        }

        return $result;
    }

    public function link(array $plan): void
    {
        Gate::authorize('update', new PurchaseProduct);
        $claimed = [];
        foreach ($plan as $id => $entry) {
            if ($entry['conflict']) {
                throw ValidationException::withMessages(['pendingClassifications' => __('catalog-classification.identity_conflict')]);
            }
            // Two dirty rows from the same identity must be reviewed separately.
            foreach (array_keys($entry['members']) as $member) {
                if (isset($plan[$member]) || isset($claimed[$member]) || isset($claimed[$id])) {
                    throw ValidationException::withMessages(['pendingClassifications' => __('catalog-classification.identity_conflict')]);
                }
                $claimed[$member] = true;
            }
        }
        foreach ($plan as $id => $entry) {
            foreach ($entry['members'] as $member) {
                DB::table('purchase_product_aliases')->insert([
                    'supplier_id' => $member['supplier_id'], 'identity_key' => $member['identity_key'], 'purchase_product_id' => $id,
                ]);
                // Keep existing inbound aliases flat, so all consumers need one lookup.
                DB::table('purchase_product_aliases')->where('purchase_product_id', $member['id'])->update(['purchase_product_id' => $id]);
            }
        }
    }
}
