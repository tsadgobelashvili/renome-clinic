<?php

namespace App\Services;

use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FullDiscountStatistics
{
    /** All monetary salary values are confirmed snapshots, never salary estimates. */
    private function salaries(string $foreignKey): Builder
    {
        return DB::table('salary_settlement_items as si')
            ->join('salary_settlements as ss', 'ss.id', '=', 'si.salary_settlement_id')
            ->where('ss.status', 'confirmed')->whereNotNull("si.{$foreignKey}")
            ->selectRaw("si.{$foreignKey} as item_id, COUNT(*) as records,
                SUM(CASE WHEN si.is_full_discount_snapshot = true AND si.salary_approved = false THEN 1 ELSE 0 END) as declined,
                SUM(CASE WHEN ss.currency = 'GEL' THEN COALESCE(si.doctor_share_snapshot, si.doctor_share, 0) ELSE 0 END) as salary_gel,
                SUM(CASE WHEN ss.currency = 'USD' THEN COALESCE(si.doctor_share_snapshot, si.doctor_share, 0) ELSE 0 END) as salary_usd")
            ->groupBy("si.{$foreignKey}");
    }

    /** One row per actual visit item; an item-less visit remains countable with zero value. */
    private function rows(array $filters): Builder
    {
        $query = DB::table('visits as v')
            ->join('patients as p', 'p.id', '=', 'v.patient_id')
            ->leftJoin('patient_groups as pg', 'pg.id', '=', 'p.patient_group_id')
            ->leftJoin('doctors as d', 'd.id', '=', 'v.doctor_id')
            ->leftJoin('visit_treatment_cases as i', 'i.visit_id', '=', 'v.id')
            ->leftJoinSub(ProcedureClassification::resolvedItems(), 't', 't.item_id', '=', 'i.id')
            ->leftJoinSub(VisitTreatmentCase::query()->salaryEligible()->select('id')->toBase(), 'eligible', 'eligible.id', '=', 'i.id')
            ->leftJoinSub($this->salaries('visit_treatment_case_id'), 'vs', 'vs.item_id', '=', 'i.id')
            // lab_main_work_id is unique on visit items: linked salary is counted once.
            ->leftJoinSub($this->salaries('lab_main_work_id'), 'ls', 'ls.item_id', '=', 'i.lab_main_work_id')
            ->whereNull('v.cancelled_at')
            ->where('v.discount_type', 'percent')->where('v.discount_value', 100)
            ->where('v.currency', $filters['currency'] ?? 'GEL')
            ->when($filters['from'] ?? null, fn (Builder $q, string $from): Builder => $q
                ->where('v.visit_date', '>=', CarbonImmutable::parse($from)->startOfDay()))
            ->when($filters['until'] ?? null, fn (Builder $q, string $until): Builder => $q
                ->where('v.visit_date', '<', CarbonImmutable::parse($until)->addDay()->startOfDay()));

        $source = "COALESCE(pg.slug, '".PatientGroup::CLINIC_SLUG."')";
        if (($filters['source'] ?? 'all') !== 'all') {
            $query->whereRaw("{$source} = ?", [$filters['source']]);
        }
        if (filled($filters['doctor'] ?? null)) {
            $query->where('v.doctor_id', $filters['doctor']);
        }
        if (filled($filters['category'] ?? null)) {
            $query->whereRaw("COALESCE(t.category, 'uncategorized') = ?", [$filters['category']]);
        }
        if (filled($filters['reason'] ?? null)) {
            $query->whereRaw("COALESCE(v.discount_reason, '__none') = ?", [$filters['reason']]);
        }

        $query->selectRaw("v.id as visit_id, v.patient_id, v.doctor_id, v.visit_date, v.currency,
            i.id as item_id, COALESCE(i.quantity, 0) as quantity,
            COALESCE(t.name, i.custom_service_name, '') as service_name,
            COALESCE(t.category, 'uncategorized') as category,
            COALESCE(t.category, 'uncategorized') as category_key,
            CASE WHEN t.id IS NULL THEN 'uncategorized' WHEN t.statistics_group IS NULL THEN t.name ELSE t.statistics_group END as group_key,
            CASE WHEN t.id IS NOT NULL AND t.statistics_group IS NULL THEN 'direct' ELSE 'group' END as group_type,
            COALESCE(v.discount_reason, '__none') as reason, {$source} as source,
            d.first_name as doctor_first_name, d.last_name as doctor_last_name,
            COALESCE(i.quantity * i.unit_price * CASE WHEN COALESCE(i.currency, v.currency) = v.currency THEN 1 ELSE COALESCE(i.exchange_rate, 0) END, 0) as original_value,
            COALESCE(vs.salary_gel, 0) + COALESCE(ls.salary_gel, 0) as salary_gel,
            COALESCE(vs.salary_usd, 0) + COALESCE(ls.salary_usd, 0) as salary_usd,
            CASE WHEN COALESCE(vs.salary_gel, 0) + COALESCE(ls.salary_gel, 0) > 0
                OR COALESCE(vs.salary_usd, 0) + COALESCE(ls.salary_usd, 0) > 0 THEN 'generated'
                WHEN COALESCE(vs.declined, 0) + COALESCE(ls.declined, 0) > 0 THEN 'declined'
                WHEN COALESCE(vs.records, 0) + COALESCE(ls.records, 0) > 0 THEN 'recorded_zero'
                WHEN i.id IS NULL THEN 'no_work'
                WHEN eligible.id IS NULL AND i.lab_main_work_id IS NULL THEN 'excluded'
                ELSE 'not_recorded' END as salary_status");

        return $query;
    }

    private function base(array $filters, array $scope = []): Builder
    {
        $query = DB::query()->fromSub($this->rows($filters), 'discount_work');
        foreach (['doctor_id', 'category_key', 'group_key', 'group_type', 'reason', 'service_name'] as $column) {
            if (array_key_exists($column, $scope)) {
                $scope[$column] === null ? $query->whereNull($column) : $query->where($column, $scope[$column]);
            }
        }
        if (($scope['salary_status'] ?? null) === 'no_salary') {
            $query->where('salary_status', '!=', 'generated');
        } elseif (filled($scope['salary_status'] ?? null)) {
            $query->where('salary_status', $scope['salary_status']);
        }

        return $query;
    }

    private function aggregate(Builder $query): Builder
    {
        return $query->selectRaw('COUNT(DISTINCT patient_id) as patients, COUNT(DISTINCT visit_id) as visits,
            COUNT(item_id) as items, COALESCE(SUM(quantity), 0) as quantity,
            COALESCE(SUM(original_value), 0) as original_value,
            COALESCE(SUM(original_value), 0) as free_value,
            COALESCE(SUM(salary_gel), 0) as salary_gel, COALESCE(SUM(salary_usd), 0) as salary_usd');
    }

    /** Six aggregate queries, independent of the number of visits or doctors. */
    public function report(array $filters): array
    {
        $base = $this->base($filters);
        $summary = $this->aggregate(clone $base)->selectRaw("COUNT(DISTINCT CASE WHEN salary_status = 'generated' THEN visit_id END) as generated_visits,
            COUNT(CASE WHEN salary_status = 'generated' THEN item_id END) as generated_items,
            COUNT(DISTINCT CASE WHEN salary_status != 'generated' THEN visit_id END) as no_salary_visits,
            COUNT(CASE WHEN salary_status != 'generated' THEN item_id END) as no_salary_items,
            COUNT(CASE WHEN salary_status = 'not_recorded' THEN item_id END) as unrecorded_items,
            COUNT(DISTINCT CASE WHEN item_id IS NULL THEN visit_id END) as itemless_visits")->first();

        return [
            'summary' => $summary,
            'categories' => $this->aggregate(clone $base)->addSelect('category_key')->groupBy('category_key')->get(),
            'groups' => $this->aggregate(clone $base)->addSelect('category_key', 'group_key', 'group_type')
                ->groupBy('category_key', 'group_key', 'group_type')->orderByDesc('original_value')->get(),
            'doctors' => $this->aggregate(clone $base)->addSelect('doctor_id', 'doctor_first_name', 'doctor_last_name')
                ->groupBy('doctor_id', 'doctor_first_name', 'doctor_last_name')->orderByDesc('original_value')->get(),
            'reasons' => $this->aggregate(clone $base)->addSelect('reason')->groupBy('reason')->orderByDesc('original_value')->get(),
            'statuses' => $this->aggregate(clone $base)->addSelect('salary_status')->groupBy('salary_status')->get(),
        ];
    }

    public function services(array $filters, array $scope): Collection
    {
        return $this->aggregate($this->base($filters, $scope))
            ->addSelect('category_key', 'group_key', 'group_type', 'service_name')
            ->groupBy('category_key', 'group_key', 'group_type', 'service_name')->orderByDesc('original_value')->get();
    }

    public function details(array $filters, array $scope = [], int $page = 1): Paginator
    {
        // Patient names and historical reason comments are fetched only for this page.
        return $this->base($filters, $scope)
            ->join('patients as detail_patient', 'detail_patient.id', '=', 'discount_work.patient_id')
            ->join('visits as detail_visit', 'detail_visit.id', '=', 'discount_work.visit_id')
            ->select('discount_work.*', 'detail_patient.first_name as patient_first_name',
                'detail_patient.last_name as patient_last_name', 'detail_visit.discount_comment')
            ->orderByDesc('discount_work.visit_date')->orderByDesc('discount_work.visit_id')->orderBy('item_id')
            ->simplePaginate(25, ['*'], 'detailsPage', max(1, $page));
    }

    public static function groupLabel(object $row): string
    {
        return $row->group_type === 'direct' ? $row->group_key
            : (TreatmentCase::statisticsGroupLabels()[$row->group_key] ?? ProcedureClassification::label($row->group_key));
    }

    public static function reasonLabel(?string $reason): string
    {
        if ($reason === '__none' || blank($reason)) {
            return __('discount-statistics.unknown_reason');
        }

        return app()->getLocale() === 'en' && array_key_exists($reason, Visit::DISCOUNT_REASONS)
            ? __('discount-statistics.reasons.'.$reason)
            : (Visit::DISCOUNT_REASONS[$reason] ?? $reason);
    }
}
