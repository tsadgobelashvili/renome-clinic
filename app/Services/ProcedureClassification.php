<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\ProcedureCatalogMapping;
use App\Models\TreatmentCase;
use App\Models\VisitTreatmentCase;
use App\Support\Currency;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ProcedureClassification
{
    public const UNCATEGORIZED = 'uncategorized';

    public static function label(string $category): string
    {
        return $category === self::UNCATEGORIZED
            ? (app()->getLocale() === 'en' ? 'Uncategorized' : 'დაუჯგუფებელი')
            : (TreatmentCase::categoryLabels()[$category] ?? $category);
    }

    /** One row per visit item. Existing valid IDs always win; ambiguous names never auto-match. */
    public static function resolvedItems(): Builder
    {
        $exactNames = DB::table('treatment_cases')->whereRaw("TRIM(COALESCE(category, '')) <> ''")
            ->selectRaw('LOWER(TRIM(name)) as normalized_name, MIN(id) as id')
            ->groupByRaw('LOWER(TRIM(name))')->havingRaw('COUNT(*) = 1');

        return DB::table('visit_treatment_cases as procedure_items')
            ->leftJoin('treatment_cases as original_catalog', 'original_catalog.id', '=', 'procedure_items.treatment_case_id')
            ->leftJoin('procedure_catalog_mappings as procedure_mapping', 'procedure_mapping.normalized_name', '=',
                DB::raw("LOWER(TRIM(COALESCE(original_catalog.name, procedure_items.custom_service_name, '')))"))
            ->leftJoinSub($exactNames, 'exact_catalog', 'exact_catalog.normalized_name', '=',
                DB::raw("LOWER(TRIM(COALESCE(original_catalog.name, procedure_items.custom_service_name, '')))"))
            ->leftJoin('treatment_cases as resolved_catalog', 'resolved_catalog.id', '=', DB::raw(
                "CASE WHEN TRIM(COALESCE(original_catalog.category, '')) <> '' THEN original_catalog.id ELSE COALESCE(procedure_mapping.treatment_case_id, exact_catalog.id) END"))
            ->leftJoin('treatment_categories as resolved_category', 'resolved_category.id', '=', 'resolved_catalog.category')
            ->leftJoin('treatment_statistics_groups as resolved_group', fn ($join) => $join
                ->on('resolved_group.id', '=', 'resolved_catalog.statistics_group')->on('resolved_group.category_id', '=', 'resolved_catalog.category'))
            ->selectRaw('procedure_items.id as item_id,
                CASE WHEN resolved_category.id IS NOT NULL THEN resolved_catalog.id END as id,
                resolved_category.id as category,
                resolved_catalog.name, resolved_group.id as statistics_group');
    }

    public static function uncategorized(bool $includeMapped = false): \Illuminate\Database\Eloquent\Builder
    {
        // Payments belong to visits. Aggregate first, never join individual payments to work rows.
        $payments = Payment::query()->select('visit_id', 'currency')->selectRaw('SUM(amount) as paid')
            ->groupBy('visit_id', 'currency');
        $visitWork = DB::table('visit_treatment_cases as work')
            ->leftJoin('treatment_cases as catalog', 'catalog.id', '=', 'work.treatment_case_id')
            ->selectRaw("work.visit_id, MIN(work.id) as first_item_id,
                COUNT(DISTINCT LOWER(TRIM(COALESCE(catalog.name, work.custom_service_name, '')))) as procedure_count,
                MIN(work.currency) as min_currency, MAX(work.currency) as max_currency,
                SUM(work.quantity * work.unit_price) as item_total")
            ->groupBy('work.visit_id');
        $rows = DB::table('visit_treatment_cases as items')
            ->join('visits', 'visits.id', '=', 'items.visit_id')->whereNull('visits.cancelled_at')
            ->leftJoinSub($payments, 'procedure_payments', fn ($join) => $join
                ->on('procedure_payments.visit_id', '=', 'visits.id')->on('procedure_payments.currency', '=', 'visits.currency'))
            ->joinSub($visitWork, 'visit_work', 'visit_work.visit_id', '=', 'visits.id')
            ->leftJoin('treatment_cases as original', 'original.id', '=', 'items.treatment_case_id')
            ->leftJoinSub(self::resolvedItems(), 'classification', 'classification.item_id', '=', 'items.id')
            ->whereRaw("TRIM(COALESCE(original.name, items.custom_service_name, '')) <> ''")
            ->selectRaw('CAST(MIN(items.id) AS BIGINT) as id, MIN(COALESCE(original.name, items.custom_service_name)) as procedure_name,
                COUNT(DISTINCT items.visit_id) as usage_count, MAX(visits.visit_date) as last_used_at,
                MAX(CASE WHEN classification.category IS NULL THEN 1 ELSE 0 END) as needs_mapping')
            ->groupByRaw('LOWER(TRIM(COALESCE(original.name, items.custom_service_name)))');

        // No estimated payment split: exact attribution only when all visit work is this procedure
        // in the visit currency, with no separate consultation fee / unmatched legacy charge.
        $exact = "visit_work.procedure_count = 1 AND COALESCE(visit_work.min_currency, '') = visits.currency
            AND COALESCE(visit_work.max_currency, '') = visits.currency AND visit_work.item_total = COALESCE(visits.total_price, -1)";
        foreach (array_keys(Currency::OPTIONS) as $currency) {
            $key = strtolower($currency);
            $rows->selectRaw("SUM(CASE WHEN COALESCE(items.currency, visits.currency) = ? THEN items.quantity * items.unit_price ELSE 0 END) as charged_{$key},
                SUM(CASE WHEN COALESCE(items.currency, visits.currency) = ? THEN 1 ELSE 0 END) as count_{$key},
                SUM(CASE WHEN COALESCE(items.currency, visits.currency) = ? AND {$exact} AND items.id = visit_work.first_item_id
                    THEN COALESCE(procedure_payments.paid, 0) ELSE 0 END) as paid_{$key},
                MAX(CASE WHEN COALESCE(items.currency, visits.currency) = ? AND COALESCE(procedure_payments.paid, 0) > 0
                    AND NOT ({$exact}) THEN 1 ELSE 0 END) as paid_unknown_{$key}",
                [$currency, $currency, $currency, $currency]);
        }

        return VisitTreatmentCase::query()->fromSub($rows, 'visit_treatment_cases')
            ->when(! $includeMapped, fn ($query) => $query->where('needs_mapping', 1));
    }

    public static function financialLines(VisitTreatmentCase $row, string $amount): array
    {
        $lines = [];
        foreach (array_keys(Currency::OPTIONS) as $currency) {
            $key = strtolower($currency);
            if ((int) $row->{'count_'.$key} === 0) {
                continue;
            }
            $lines[] = $amount === 'paid' && (int) $row->{'paid_unknown_'.$key} > 0
                ? '— '.$currency
                : Currency::format($row->{$amount.'_'.$key}, $currency);
        }

        return $lines;
    }

    public static function assign(string $name, int $catalogId): void
    {
        $catalog = TreatmentCase::findOrFail($catalogId);
        Gate::authorize('update', $catalog);
        $normalized = VisitTreatmentCase::normalizeProcedureName($name);
        if ($normalized === '' || blank($catalog->category)) {
            throw ValidationException::withMessages(['treatment_case_id' => 'აირჩიეთ კატეგორიის მქონე კატალოგის ჩანაწერი.']);
        }
        ProcedureCatalogMapping::upsert([
            ['normalized_name' => $normalized, 'treatment_case_id' => $catalog->id,
                'created_at' => now(), 'updated_at' => now()],
        ], ['normalized_name'], ['treatment_case_id', 'updated_at']);
    }

    public static function classify(string $name, array $classification): void
    {
        Gate::authorize('create', TreatmentCase::class);
        DB::transaction(function () use ($name, $classification): void {
            $normalized = VisitTreatmentCase::normalizeProcedureName($name);
            // Serialize assignments of the same existing procedure without rewriting visit items.
            VisitTreatmentCase::whereRaw('LOWER(TRIM(custom_service_name)) = ?', [$normalized])->orderBy('id')->lockForUpdate()->first(['id']);
            $mapping = ProcedureCatalogMapping::where('normalized_name', $normalized)->first();
            $catalog = $mapping ? TreatmentCase::findOrFail($mapping->treatment_case_id) : null;
            if (! $catalog) {
                $matches = TreatmentCase::whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->get();
                if ($matches->count() > 1) {
                    // The explicitly selected hierarchy can disambiguate existing names.
                    $matchingClassification = $matches->filter(fn (TreatmentCase $item) => $item->category === $classification['category']
                        && $item->statistics_group === ($classification['statistics_group'] ?? null));
                    if ($matchingClassification->count() !== 1) {
                        throw ValidationException::withMessages(['category' => 'არსებობს რამდენიმე ერთსახელიანი ჩანაწერი. აირჩიეთ შესაბამისი კატეგორია და ჯგუფი ან დააზუსტეთ კატალოგში.']);
                    }
                    $matches = $matchingClassification;
                }
                $catalog = $matches->first() ?? new TreatmentCase(['name' => trim($name), 'is_active' => true]);
            }
            Gate::authorize($catalog->exists ? 'update' : 'create', $catalog->exists ? $catalog : TreatmentCase::class);
            $catalog->fill(['category' => $classification['category'], 'statistics_group' => $classification['statistics_group'] ?? null])->save();
            self::assign($name, $catalog->id);
        });
    }
}
