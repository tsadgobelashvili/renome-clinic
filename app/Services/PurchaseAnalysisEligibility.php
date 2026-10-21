<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Read-only RS evidence filter. Never changes confirmation/import/accounting rules. */
class PurchaseAnalysisEligibility
{
    public function documents(): Builder
    {
        $query = DB::table('purchases as eligible')->select('eligible.id')->where('eligible.source', 'rs')
            ->whereNotNull('eligible.purchase_date')
            ->where('eligible.total_amount', '>=', 0)
            ->whereNull('eligible.rs_import_payload->pending_import')
            ->where(fn ($q) => $q->whereNull('eligible.rs_import_payload->document_kind')->orWhere('eligible.rs_import_payload->document_kind', 'purchase'))
            // Missing modern verification evidence is not a cancellation/invalid status.
            // Accounting confirmation remains subject to PurchaseReconciliation.
            ->where(fn ($q) => $q->whereNull('eligible.rs_import_payload->validation_errors')->orWhereJsonLength('eligible.rs_import_payload->validation_errors', 0));

        // Status is retained in raw RS headers/cells, not a normalized Purchase column.
        // Match explicit text statuses only; do not guess numeric RS enum meanings.
        $headers = RsDocumentSemantics::STATUS_HEADERS;
        $excluded = [...RsDocumentSemantics::CANCELLED, ...RsDocumentSemantics::RETURNS];
        $driver = DB::connection()->getDriverName();
        $source = match ($driver) {
            'pgsql' => "jsonb_each(CASE WHEN jsonb_typeof(eligible.rs_import_payload::jsonb->'source_rows') = 'object' THEN eligible.rs_import_payload::jsonb->'source_rows' ELSE '{}'::jsonb END) src",
            'mysql', 'mariadb' => "JSON_TABLE(eligible.rs_import_payload, '$.source_rows.*' COLUMNS(raw JSON PATH '$.raw')) src",
            default => "json_each(eligible.rs_import_payload, '$.source_rows') src",
        };
        [$rows, $header, $status] = match ($driver) {
            'pgsql' => [$source." CROSS JOIN LATERAL jsonb_array_elements_text(CASE WHEN jsonb_typeof(src.value->'raw'->'headers') = 'array' THEN src.value->'raw'->'headers' ELSE '[]'::jsonb END) WITH ORDINALITY AS h(header, ordinal)", 'h.header', "src.value->'raw'->'cells'->>(h.ordinal::int - 1)"],
            'mysql', 'mariadb' => [$source." CROSS JOIN JSON_TABLE(src.raw, '$.headers[*]' COLUMNS(ordinal FOR ORDINALITY, header VARCHAR(255) PATH '$')) h", 'h.header', "JSON_UNQUOTE(JSON_EXTRACT(src.raw, CONCAT('$.cells[', h.ordinal - 1, ']')))"],
            default => [$source." JOIN json_each(src.value, '$.raw.headers') h", 'h.value', "json_extract(src.value, '$.raw.cells[' || h.key || ']')"],
        };
        $query->whereRaw('NOT EXISTS (SELECT 1 FROM '.$rows.' WHERE LOWER(TRIM('.$header.')) IN ('.implode(',', array_fill(0, count($headers), '?')).') AND LOWER(TRIM('.$status.')) IN ('.implode(',', array_fill(0, count($excluded), '?')).'))', [...$headers, ...$excluded]);
        $headers = [...RsDocumentSemantics::TYPE_HEADERS, ...RsDocumentSemantics::WAYBILL_HEADERS];
        $excluded = RsDocumentSemantics::RETURNS;
        $query->whereRaw('NOT EXISTS (SELECT 1 FROM '.$rows.' WHERE LOWER(TRIM('.$header.')) IN ('.implode(',', array_fill(0, count($headers), '?')).') AND LOWER(TRIM('.$status.')) IN ('.implode(',', array_fill(0, count($excluded), '?')).'))', [...$headers, ...$excluded]);

        return $query;
    }
}
