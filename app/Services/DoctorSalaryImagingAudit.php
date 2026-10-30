<?php

namespace App\Services;

use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use Illuminate\Support\Facades\DB;

/** Read-only payroll impact preview. Never returns names or clinical free text. */
class DoctorSalaryImagingAudit
{
    public function __construct(private readonly DoctorCompensationCalculator $calculator) {}

    public function report(?int $visitId = null): array
    {
        $candidates = DB::query()->fromSub(ProcedureClassification::potentialUnclassifiedRadiologyItems(), 'candidate')
            ->join('visits', 'visits.id', '=', 'candidate.visit_id')->whereNull('visits.cancelled_at')->whereNotNull('visits.doctor_id')
            ->when($visitId !== null, fn ($query) => $query->where('visits.id', $visitId))
            ->whereIn('candidate.item_id', VisitTreatmentCase::query()->salaryUnsettled()->select('id'))
            ->select('candidate.*')->orderBy('candidate.visit_id')->orderBy('candidate.item_id')->get();
        $approvedIds = ProcedureClassification::approvedManualRadiologyItems()->whereIn('manual_radiology.id', $candidates->pluck('item_id'))->pluck('item_id')->all();
        $totals = ['potential_visit_count' => 0, 'affected_unfinalized_visit_count' => 0, 'ambiguous_item_count' => 0,
            'potential_imaging_revenue_by_currency' => [], 'approved_excluded_revenue_by_currency' => [],
            'ambiguous_revenue_by_currency' => [], 'doctor_compensation_correction_by_currency' => []];
        $rows = [];
        foreach ($candidates->groupBy('visit_id') as $id => $items) {
            $visit = Visit::with('patient.patientGroup')->findOrFail($id);
            $ids = VisitTreatmentCase::where('visit_id', $id)->salaryUnsettled()->pluck('id')->all();
            $after = $this->calculate($visit, $ids);
            // In-memory config only, restored even on failure. Compare the real shared
            // calculator with/without the new fallback; never estimate salary as price * %.
            $names = config('procedure_classification.manual_salary_radiology_names');
            try {
                config(['procedure_classification.manual_salary_radiology_names' => []]);
                $before = $this->calculate($visit, $ids);
            } finally {
                config(['procedure_classification.manual_salary_radiology_names' => $names]);
            }
            $approved = $items->filter(fn ($item) => in_array($item->item_id, $approvedIds, true));
            $totals['potential_visit_count']++;
            $totals['affected_unfinalized_visit_count'] += $approved->isNotEmpty() ? 1 : 0;
            $correction = round($before['source_doctor_share'] + $before['counterpart_share'] - $after['source_doctor_share'] - $after['counterpart_share'], 2);
            $this->add($totals['doctor_compensation_correction_by_currency'], $visit->currency, $correction);
            $safeItems = [];
            foreach ($items as $item) {
                $automatic = in_array($item->item_id, $approvedIds, true);
                $currency = $item->currency ?: $visit->currency;
                $revenue = round((int) $item->quantity * (float) $item->unit_price, 2);
                $this->add($totals['potential_imaging_revenue_by_currency'], $currency, $revenue);
                $this->add($totals[$automatic ? 'approved_excluded_revenue_by_currency' : 'ambiguous_revenue_by_currency'], $currency, $revenue);
                $totals['ambiguous_item_count'] += $automatic ? 0 : 1;
                $safeItems[] = ['item_id' => $item->item_id, 'quantity' => (int) $item->quantity, 'unit_price' => (float) $item->unit_price,
                    'currency' => $currency, 'native_revenue' => $revenue,
                    'approved_identifier' => $automatic ? $item->normalized_name : null,
                    'identifier_sha256' => hash('sha256', $item->normalized_name),
                    'classification' => $automatic ? 'approved_manual_radiology_identifier' : 'manual_review',
                    'reason' => $automatic ? 'exact_approved_name_without_catalog_or_mapping'
                        : (in_array($item->normalized_name, ProcedureClassification::approvedManualRadiologyNames(), true)
                            ? 'catalog_or_mapping_requires_review' : 'identifier_not_approved')];
            }
            $rows[] = ['visit_id' => (int) $id, 'date' => $visit->visit_date->toDateString(), 'currency' => $visit->currency,
                'before' => $before, 'after' => $after, 'doctor_compensation_correction' => $correction, 'items' => $safeItems];
        }

        return ['scope' => 'Unfinalized doctor work, not patient payment status. Confirmed settlements and cancelled visits are excluded.',
            'correction_scope' => 'Exact approved identifiers only; ambiguous corrections are unknown until explicitly classified. Includes any Owner Split counterpart.',
            'revenue_scope' => 'Native item currencies; no cross-currency totals or estimated exchange rates.',
            'summary' => $totals, 'visits' => $rows];
    }

    private function calculate(Visit $visit, array $ids): array
    {
        $report = $this->calculator->calculate($visit->doctor_id, $visit->visit_date->toDateString(), $visit->visit_date->toDateString(),
            patientGroup: $visit->patient?->patientGroup?->slug ?? DoctorCompensationCalculator::GROUP_ALL,
            selectedLabWorkIds: [], selectedManualCaseIds: [], selectedVisitItemIds: $ids, selectedOwnerShareIds: []);
        $detail = collect($report['details'])->firstWhere('visit_id', $visit->id);
        $counterpart = collect($report['owner_split_preview'])->flatMap(fn ($row) => $row['visits'])->where('visit_id', $visit->id)->sum('counterpart_share');

        return ['eligible_work' => (float) ($detail['work_total'] ?? 0), 'eligible_paid' => (float) ($detail['paid_total'] ?? 0),
            'base' => (float) ($detail['base_total'] ?? 0), 'source_doctor_share' => (float) ($detail['doctor_share'] ?? 0),
            'counterpart_share' => round((float) $counterpart, 2)];
    }

    private function add(array &$totals, string $currency, float $amount): void
    {
        $totals[$currency] = round(($totals[$currency] ?? 0) + $amount, 2);
    }
}
