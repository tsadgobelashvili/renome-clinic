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

    /** Salary-only semantic projection. General statistics keep resolvedItems(). */
    public static function salaryItems(): Builder
    {
        return DB::query()->fromSub(self::resolvedItems(), 'salary_resolved')
            ->leftJoinSub(self::approvedManualRadiologyItems(), 'salary_manual', 'salary_manual.item_id', '=', 'salary_resolved.item_id')
            ->select('salary_resolved.item_id', 'salary_resolved.id', 'salary_resolved.statistics_group')
            ->selectRaw("CASE WHEN salary_manual.item_id IS NOT NULL THEN 'tomography' ELSE salary_resolved.category END AS category,
                CASE WHEN salary_manual.item_id IS NOT NULL THEN 'approved_manual_radiology_identifier'
                    WHEN salary_resolved.category IS NOT NULL THEN 'catalog' ELSE 'unresolved' END AS classification_source");
    }

    public static function approvedManualRadiologyNames(): array
    {
        return array_values(array_unique(array_filter(array_map(
            VisitTreatmentCase::normalizeProcedureName(...), config('procedure_classification.manual_salary_radiology_names', []),
        ))));
    }

    /** Explicit catalog decisions, mappings and even ambiguous catalog names win.
     * Never infer an exclusion from a word occurring inside a treatment name. */
    public static function approvedManualRadiologyItems(): Builder
    {
        return DB::table('visit_treatment_cases as manual_radiology')
            ->whereNull('manual_radiology.treatment_case_id')
            ->whereIn(DB::raw('LOWER(TRIM(manual_radiology.custom_service_name))'), self::approvedManualRadiologyNames())
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('procedure_catalog_mappings as manual_mapping')
                ->whereColumn('manual_mapping.normalized_name', DB::raw('LOWER(TRIM(manual_radiology.custom_service_name))')))
            ->whereNotExists(fn (Builder $query) => $query->selectRaw('1')->from('treatment_cases as manual_catalog')
                ->whereRaw('LOWER(TRIM(manual_catalog.name)) = LOWER(TRIM(manual_radiology.custom_service_name))'))
            ->select('manual_radiology.id as item_id');
    }

    /** Broad signals are permitted ONLY for read-only review, never eligibility. */
    public static function potentialUnclassifiedRadiologyItems(): Builder
    {
        $name = "LOWER(TRIM(COALESCE(audit_catalog.name, audit_items.custom_service_name, '')))";

        return DB::table('visit_treatment_cases as audit_items')
            ->leftJoin('treatment_cases as audit_catalog', 'audit_catalog.id', '=', 'audit_items.treatment_case_id')
            ->leftJoinSub(self::resolvedItems(), 'audit_classification', 'audit_classification.item_id', '=', 'audit_items.id')
            ->whereNull('audit_classification.category')->whereNull('audit_items.lab_main_work_id')
            ->where(function (Builder $query) use ($name): void {
                $query->whereIn(DB::raw($name), self::approvedManualRadiologyNames());
                foreach (['ტომოგრაფ', 'რადიოლოგ', 'რენტგენ', 'პანორამ', 'tomograph', 'radiolog', 'radiograph', 'panoram', 'cbct', 'imaging', 'x-ray', 'xray'] as $token) {
                    $query->orWhereRaw($name.' LIKE ?', ['%'.$token.'%']);
                }
                foreach (['ct', 'ct %', '% ct', '% ct %', '3d', '3დ'] as $pattern) {
                    $query->orWhereRaw($name.' LIKE ?', [$pattern]);
                }
            })
            ->select('audit_items.id as item_id', 'audit_items.visit_id', 'audit_items.quantity', 'audit_items.unit_price', 'audit_items.currency', 'audit_items.exchange_rate')
            ->selectRaw($name.' AS normalized_name');
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
