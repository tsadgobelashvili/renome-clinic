<?php

namespace App\Services;

use App\Models\PurchaseProduct;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Only unreferenced purchase-catalog rows, never retail Products or accounting. */
class PurchaseCatalogDeletion
{
    private ?array $foreignReferences = null;

    public function blockers(PurchaseProduct $product): array
    {
        $reasons = [];
        foreach ($this->foreignReferences() as $reference) {
            if ($reference['target'] === 'purchase_product_aliases') {
                $query = DB::table($reference['table'].' as usage')->join('purchase_product_aliases as owned_alias', function ($join) use ($reference): void {
                    foreach ($reference['columns'] as $column => $target) {
                        $join->on('usage.'.$column, '=', 'owned_alias.'.$target);
                    }
                })->where('owned_alias.purchase_product_id', $product->id);
                if ($query->exists()) {
                    $reasons[] = 'პროდუქტის ბმებს სხვა ჩანაწერები იყენებს.';
                }

                continue;
            }
            $query = DB::table($reference['table']);
            foreach ($reference['columns'] as $column => $target) {
                if ($product->getAttribute($target) === null) {
                    continue 2;
                }
                $query->where($column, $product->getAttribute($target));
            }
            if ($query->exists()) {
                $reasons[] = match (basename(str_replace('.', '/', $reference['table']))) {
                    'purchase_items' => 'პროდუქტს აქვს შესყიდვის ისტორია.',
                    'finance_transactions' => 'პროდუქტი გამოიყენება ფინანსურ ჩანაწერში.',
                    'partner_finance_transactions' => 'პროდუქტი გამოიყენება ისრაელის ფინანსურ ჩანაწერში.',
                    default => 'პროდუქტი დაკავშირებულია სხვა სისტემურ ჩანაწერთან.',
                };
            }
        }
        // Analysis also resolves legacy lines by exact codes and canonical aliases,
        // including lines with no direct purchase_product_id foreign key.
        if (! in_array('პროდუქტს აქვს შესყიდვის ისტორია.', $reasons, true)
            && DB::query()->fromSub(app(PurchaseAnalysisMapping::class)->items(), 'catalog_history')
                ->where('resolved_product_id', $product->id)->exists()) {
            $reasons[] = 'პროდუქტს აქვს შესყიდვის ისტორია.';
        }
        if (DB::table('purchase_product_aliases as alias')->join('purchase_products as member', 'member.identity_key', '=', 'alias.identity_key')
            ->where('alias.purchase_product_id', $product->id)->where('member.id', '!=', $product->id)->exists()) {
            $reasons[] = 'პროდუქტის საერთო იდენტობის ბმა სხვა კატალოგის ჩანაწერს სჭირდება.';
        }

        return array_values(array_unique($reasons));
    }

    private function foreignReferences(): array
    {
        if ($this->foreignReferences !== null) {
            return $this->foreignReferences;
        }
        $references = [];
        foreach (Schema::getTables() as $table) {
            $name = $table['schema_qualified_name'] ?? $table['name'];
            foreach (Schema::getForeignKeys($name) as $key) {
                if (! in_array($key['foreign_table'], ['purchase_products', 'purchase_product_aliases'], true)
                    || ($table['name'] === 'purchase_product_aliases' && $key['foreign_table'] === 'purchase_products')) {
                    continue;
                }
                $references[] = ['table' => $name, 'target' => $key['foreign_table'], 'columns' => array_combine($key['columns'], $key['foreign_columns'])];
            }
        }

        return $this->foreignReferences = $references;
    }

    public function assertDeletable(PurchaseProduct $product): void
    {
        if ($reasons = $this->blockers($product)) {
            throw ValidationException::withMessages(['product' => implode(' ', $reasons)]);
        }
    }

    public function snapshot(PurchaseProduct $product): array
    {
        return [...$product->only(['id', 'supplier_id', 'name', 'rs_product_code', 'supplier_product_code', 'identity_key',
            'catalog_origin', 'manually_retained', 'expense_direction_id', 'expense_type_id', 'purchase_product_group_id']),
            'aliases' => DB::table('purchase_product_aliases')->where('purchase_product_id', $product->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
    }

    public function auditProduct(array $snapshot, User $actor): void
    {
        $audit = ['product' => $snapshot, 'user_id' => $actor->id, 'timestamp' => now()->toIso8601String()];
        DB::afterCommit(fn () => Log::info('Unused purchase catalog product deleted', $audit));
    }

    public function afterDocumentDeleted(array $productIds, array $document, User $actor): void
    {
        $canonicalIds = PurchaseProduct::whereIn('id', $productIds)->get()
            ->map(fn ($product) => app(PurchaseProductIdentity::class)->canonical($product)->id)->all();
        PurchaseProduct::whereIn('id', array_unique([...$productIds, ...$canonicalIds]))->orderBy('id')->lockForUpdate()->get();
        $removed = $kept = [];
        // Visit source members before roots, then reconsider roots after exclusive
        // source members are gone. No product outside the affected identities is swept.
        foreach ([...$productIds, ...$canonicalIds] as $id) {
            $product = PurchaseProduct::find($id);
            if (! $product) {
                continue;
            }
            if ($product->catalog_origin !== 'rs' || $product->manually_retained) {
                $kept[$id] = ['id' => $id, 'reason' => 'Manual use or unknown creation provenance.'];

                continue;
            }
            if ($reasons = $this->blockers($product)) {
                $kept[$id] = ['id' => $id, 'reasons' => $reasons];

                continue;
            }
            $snapshot = $this->snapshot($product);
            $product->delete();
            $removed[$id] = $snapshot;
            unset($kept[$id]);
        }
        $audit = ['document' => $document, 'orphan_product_ids' => array_keys($removed), 'orphan_products' => array_values($removed),
            'kept_products' => array_values($kept), 'user_id' => $actor->id, 'timestamp' => now()->toIso8601String()];
        DB::afterCommit(fn () => Log::info('Unaccounted RS purchase deleted', $audit));
    }
}
