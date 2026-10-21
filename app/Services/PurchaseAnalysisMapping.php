<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only compatibility. Ambiguous identities stay unresolved; never match display names. */
class PurchaseAnalysisMapping
{
    private ?array $schema = null;

    public function capabilities(): array
    {
        return $this->schema ??= ['aliases' => Schema::hasTable('purchase_product_aliases'),
            'group_direction' => Schema::hasColumn('purchase_product_groups', 'expense_direction_id')];
    }

    public function items(): Builder
    {
        $capabilities = $this->capabilities();
        if ($capabilities['aliases'] && DB::connection()->getDriverName() === 'sqlite') {
            // Pure connection-local function; no tables, records or schema are changed.
            DB::connection()->getPdo()->sqliteCreateFunction('purchase_identity_hash', fn ($value) => $value === null ? null : hash('sha256', $value), 1);
        }
        $legacy = DB::table('purchase_items as linked')->join('purchases as linked_document', 'linked_document.id', '=', 'linked.purchase_id')
            ->whereNotNull('linked.product_id')->whereNotNull('linked.purchase_product_id');
        $legacyIdentity = 'linked.purchase_product_id';
        if ($capabilities['aliases']) {
            $legacy->leftJoin('purchase_products as linked_catalog', 'linked_catalog.id', '=', 'linked.purchase_product_id')
                ->leftJoin('purchase_product_aliases as linked_alias', 'linked_alias.identity_key', '=', 'linked_catalog.identity_key');
            $legacyIdentity = 'COALESCE(linked_alias.purchase_product_id, linked.purchase_product_id)';
        }
        $legacy
            ->groupBy('linked_document.supplier_id', 'linked.product_id')
            ->havingRaw('COUNT(DISTINCT '.$legacyIdentity.') = 1')
            ->selectRaw('linked_document.supplier_id, linked.product_id as legacy_id, MIN('.$legacyIdentity.') as catalog_id');
        $directions = DB::table('expense_categories')->where('classification_dimension', 'direction')->whereNull('parent_id')
            ->whereNotNull('classification_code')->groupBy('classification_code')->havingRaw('COUNT(*) = 1')
            ->selectRaw('classification_code, MIN(id) as direction_id');
        $query = DB::table('purchase_items as i')->join('purchases as d', 'd.id', '=', 'i.purchase_id')
            ->leftJoinSub($legacy, 'legacy_link', fn ($join) => $join->on('legacy_link.legacy_id', '=', 'i.product_id')->on('legacy_link.supplier_id', '=', 'd.supplier_id'))
            ->leftJoin('products as retail', 'retail.id', '=', 'i.product_id')
            ->leftJoin('product_categories as legacy_category', 'legacy_category.id', '=', 'retail.product_category_id')
            // Exact shared taxonomy codes only. Unrelated numeric category IDs never overlap.
            ->leftJoinSub($directions, 'legacy_direction', 'legacy_direction.classification_code', '=', 'legacy_category.slug');
        $resolved = ['i.purchase_product_id', 'legacy_link.catalog_id'];
        foreach (['rs_product_code' => ['rs product code', 'rs item code', 'rs code', 'საქონლის კოდი'],
            'supplier_product_code' => ['supplier item code', 'supplier product code', 'product code', 'item code', 'მომწოდებლის კოდი']] as $field => $headers) {
            $mapping = DB::table('purchase_products as code_product')->whereNotNull('code_product.'.$field)->whereNotNull('code_product.supplier_id');
            $codeIdentity = 'code_product.id';
            if ($capabilities['aliases']) {
                $mapping->leftJoin('purchase_product_aliases as code_alias', 'code_alias.identity_key', '=', 'code_product.identity_key');
                $codeIdentity = 'COALESCE(code_alias.purchase_product_id, code_product.id)';
            }
            $mapping->groupBy('code_product.supplier_id', 'code_product.'.$field)->havingRaw('COUNT(DISTINCT '.$codeIdentity.') = 1')
                ->selectRaw('code_product.supplier_id, code_product.'.$field.' as code, MIN('.$codeIdentity.') as catalog_id');
            [$cell, $bindings] = $this->sourceCode($headers);
            // PostgreSQL may reorder JOIN predicates. CASE guarantees that modern
            // direct mappings never parse legacy raw payloads, even under that plan.
            $cell = 'CASE WHEN i.purchase_product_id IS NULL AND legacy_link.catalog_id IS NULL THEN ('.$cell.') ELSE NULL END';
            $alias = $field === 'rs_product_code' ? 'rs_link' : 'supplier_link';
            $query->leftJoinSub($mapping, $alias, fn ($join) => $join->on($alias.'.supplier_id', '=', 'd.supplier_id')
                ->whereNull('i.purchase_product_id')->whereNull('legacy_link.catalog_id')->whereRaw($alias.'.code = ('.$cell.')', $bindings));
            $resolved[] = $alias.'.catalog_id';
            if (! $capabilities['aliases']) {
                continue;
            }
            $kind = $field === 'rs_product_code' ? 'rs' : 'supplier';
            $identity = match (DB::connection()->getDriverName()) {
                'mysql', 'mariadb' => "SHA2(CONCAT(d.supplier_id, '|{$kind}|', ({$cell})), 256)",
                'pgsql' => "encode(sha256(convert_to(CAST(d.supplier_id AS TEXT) || '|{$kind}|' || ({$cell}), 'UTF8')), 'hex')",
                default => "purchase_identity_hash(CAST(d.supplier_id AS TEXT) || '|{$kind}|' || ({$cell}))",
            };
            $query->leftJoin('purchase_product_aliases as '.$alias.'_alias', fn ($join) => $join->on($alias.'_alias.supplier_id', '=', 'd.supplier_id')
                ->whereNull('i.purchase_product_id')->whereNull('legacy_link.catalog_id')->whereRaw($alias.'_alias.identity_key = '.$identity, $bindings));
            $resolved[] = $alias.'_alias.purchase_product_id';
        }

        $identity = 'COALESCE('.implode(', ', $resolved).')';
        if ($capabilities['aliases']) {
            $query->leftJoin('purchase_products as resolved_catalog', 'resolved_catalog.id', '=', DB::raw($identity))
                ->leftJoin('purchase_product_aliases as canonical_catalog', 'canonical_catalog.identity_key', '=', 'resolved_catalog.identity_key');
            $identity = 'COALESCE(canonical_catalog.purchase_product_id, '.$identity.')';
        }

        return $query->select('i.*')->selectRaw($identity.' as resolved_product_id, legacy_direction.direction_id as legacy_direction_id');
    }

    private function sourceCode(array $headers): array
    {
        [$rows, $header, $cell, $ordinal] = match (DB::connection()->getDriverName()) {
            'pgsql' => ["jsonb_array_elements_text(CASE WHEN jsonb_typeof(d.rs_import_payload::jsonb->'source_rows'->i.source_row_hash->'raw'->'headers') = 'array' THEN d.rs_import_payload::jsonb->'source_rows'->i.source_row_hash->'raw'->'headers' ELSE '[]'::jsonb END) WITH ORDINALITY h(header, ordinal)", 'h.header', "d.rs_import_payload::jsonb->'source_rows'->i.source_row_hash->'raw'->'cells'->>(h.ordinal::int - 1)", 'h.ordinal'],
            'mysql', 'mariadb' => ["JSON_TABLE(JSON_EXTRACT(d.rs_import_payload, CONCAT('$.source_rows.', JSON_QUOTE(i.source_row_hash), '.raw.headers')), '$[*]' COLUMNS(ordinal FOR ORDINALITY, header VARCHAR(255) PATH '$')) h", 'h.header', "JSON_UNQUOTE(JSON_EXTRACT(d.rs_import_payload, CONCAT('$.source_rows.', JSON_QUOTE(i.source_row_hash), '.raw.cells[', h.ordinal - 1, ']')))", 'h.ordinal'],
            default => ["json_each(d.rs_import_payload, '$.source_rows.\"' || i.source_row_hash || '\".raw.headers') h", 'h.value', "json_extract(d.rs_import_payload, '$.source_rows.\"' || i.source_row_hash || '\".raw.cells[' || h.key || ']')", 'h.key'],
        };

        return ['SELECT NULLIF(TRIM('.$cell."), '') FROM ".$rows.' WHERE LOWER(TRIM('.$header.')) IN ('.implode(',', array_fill(0, count($headers), '?')).') ORDER BY '.$ordinal.' LIMIT 1', $headers];
    }
}
