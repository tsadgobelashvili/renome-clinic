<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Aggregate SELECTs only. No raw payloads, personal data or credentials in the output. */
class PurchaseAnalysisDiagnostics
{
    public function snapshot(string $from, string $until): array
    {
        validator(compact('from', 'until'), ['from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from'])->validate();
        $documents = DB::table('purchases')->where('source', 'rs')->whereDate('purchase_date', '>=', $from)->whereDate('purchase_date', '<=', $until);
        $eligible = app(PurchaseAnalysisEligibility::class)->documents();
        $direction = app(PurchaseAnalysis::class)->directionExpression();
        $allLines = DB::table('purchase_items')->join('purchases', 'purchases.id', '=', 'purchase_items.purchase_id')
            ->where('purchases.source', 'rs')->whereDate('purchases.purchase_date', '>=', $from)->whereDate('purchases.purchase_date', '<=', $until);
        $lines = app(PurchaseAnalysis::class)->lines(['from' => $from, 'until' => $until])->toBase()->selectRaw('
            COUNT(*) as eligible_lines,
            SUM(CASE WHEN purchase_items.purchase_product_id IS NOT NULL THEN 1 ELSE 0 END) as direct_catalog_lines,
            SUM(CASE WHEN purchase_items.purchase_product_id IS NULL AND purchase_items.resolved_product_id IS NOT NULL THEN 1 ELSE 0 END) as resolved_legacy_lines,
            SUM(CASE WHEN purchase_items.resolved_product_id IS NULL AND '.$direction.' IS NOT NULL THEN 1 ELSE 0 END) as legacy_direction_only_lines,
            SUM(CASE WHEN purchase_items.resolved_product_id IS NULL THEN 1 ELSE 0 END) as unresolved_product_lines,
            SUM(CASE WHEN '.$direction.' IS NULL THEN 1 ELSE 0 END) as unclassified_direction_lines')->first();
        $report = app(PurchaseAnalysis::class);
        $filters = compact('from', 'until');
        $started = hrtime(true);
        $directionRows = $report->directions($filters)->limit(100)->get();
        $groupRow = $report->groups($filters)->first();
        $group = (string) ($groupRow?->group_id ?? 'ungrouped');
        $productRow = $report->products($filters, $group)->first();
        $historyFound = $productRow?->product_id
            ? $report->history($filters, $group, (string) $productRow->product_id)->exists() : false;

        return ['timezone' => config('app.timezone'), 'date_field' => 'purchases.purchase_date', 'from' => $from, 'until' => $until,
            'optional_schema' => app(PurchaseAnalysisMapping::class)->capabilities(),
            'report_smoke' => ['direction_rows_up_to_100' => $directionRows->count(), 'group_found' => $groupRow !== null,
                'product_found' => $productRow !== null, 'history_found' => $historyFound,
                'elapsed_ms' => round((hrtime(true) - $started) / 1000000, 1)],
            'rs_documents_in_period' => (clone $documents)->count(),
            'rs_documents_outside_period' => DB::table('purchases')->where('source', 'rs')->where(fn ($q) => $q->whereDate('purchase_date', '<', $from)->orWhereDate('purchase_date', '>', $until))->count(),
            'included_documents' => (clone $documents)->whereIn('id', clone $eligible)->count(),
            'excluded_by_status_or_validation' => (clone $documents)->whereNotIn('id', clone $eligible)->count(),
            'documents_without_new_payload' => (clone $documents)->whereNull('rs_import_payload')->count(),
            'verification_markers' => [
                'validated_true' => (clone $documents)->where('rs_import_payload->validated', true)->count(),
                'validated_false' => (clone $documents)->where('rs_import_payload->validated', false)->count(),
                'validated_missing' => (clone $documents)->whereNull('rs_import_payload->validated')->count(),
                'source_rows_missing' => (clone $documents)->whereNull('rs_import_payload->source_rows')->count(),
            ],
            'all_lines_in_period' => (clone $allLines)->count(),
            'excluded_lines' => (clone $allLines)->whereNotIn('purchases.id', clone $eligible)->count(),
            'mapping' => array_map(fn ($value) => (int) $value, (array) $lines)];
    }
}
