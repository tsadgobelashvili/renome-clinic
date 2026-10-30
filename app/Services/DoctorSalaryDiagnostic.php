<?php

namespace App\Services;

use App\Models\ClinicPayrollCycle;
use App\Models\ProcedureCatalogMapping;
use App\Models\TreatmentCase;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use ReflectionClass;

/** No writes, names, patient/doctor IDs, free text or complete payroll payloads. */
class DoctorSalaryDiagnostic
{
    public function trace(int $visitId, ?int $reference = null): array
    {
        $release = [];
        foreach ([VisitTreatmentCase::class, ProcedureClassification::class, DoctorCompensationCalculator::class, ClinicPayrollCycleService::class] as $class) {
            $file = (new ReflectionClass($class))->getFileName();
            $release[$class] = ['file' => $file, 'sha256_lf' => hash('sha256', str_replace("\r\n", "\n", file_get_contents($file)))];
        }
        $result = ['runtime' => ['php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'release_path' => realpath(base_path()),
            'config_cached' => app()->configurationIsCached(), 'cli_opcache_enabled' => (bool) ini_get('opcache.enable_cli'),
            'web_fpm_release_verified' => false, 'source' => $release]];
        $result['runtime']['exclusion_support'] = [
            'batch_eligibility' => method_exists(VisitTreatmentCase::class, 'scopeWithSalaryEligibility'),
            'manual_radiology_fallback' => method_exists(ProcedureClassification::class, 'salaryItems'),
            'deferred_reprojection' => method_exists(ClinicPayrollCycleService::class, 'refreshDeferredDoctorReports'),
            'selected_visit_items' => in_array('selectedVisitItemIds', array_map(fn ($parameter) => $parameter->name,
                (new \ReflectionMethod(DoctorCompensationCalculator::class, 'calculate'))->getParameters()), true),
        ];
        if (in_array(false, $result['runtime']['exclusion_support'], true)) {
            return [...$result, 'diagnostic_unavailable' => 'The CLI release is missing part of the previous tomography exclusion.'];
        }
        $visit = Visit::withCancelled()->with(['doctor', 'patient.patientGroup', 'payments',
            'treatmentCaseItems' => fn ($query) => $query->orderBy('id')->withSalaryEligibility()->with(['treatmentCase.classificationCategory', 'directExpenses'])])
            ->find($visitId);
        if (! $visit) {
            return [...$result, 'visit_id' => $visitId, 'found' => false];
        }
        $result['found'] = true;
        $items = $visit->treatmentCaseItems;
        $resolved = DB::query()->fromSub(ProcedureClassification::resolvedItems(), 'classification')
            ->whereIn('item_id', $items->modelKeys())->get(['item_id', 'id', 'category', 'statistics_group'])->keyBy('item_id');
        $salaryResolved = DB::query()->fromSub(ProcedureClassification::salaryItems(), 'classification')
            ->whereIn('item_id', $items->modelKeys())->get()->keyBy('item_id');
        $unsettled = VisitTreatmentCase::whereKey($items->modelKeys())->salaryUnsettled()->pluck('id')->all();
        $result['visit'] = ['id' => $visit->id, 'date' => $visit->visit_date->toDateString(),
            'reference_matches' => $reference === null ? null : (int) $visit->patient?->patient_number === $reference,
            'currency' => $visit->currency, 'cancelled' => $visit->is_cancelled,
            'gross' => $visit->gross_amount, 'discount' => (float) $visit->discount_amount,
            'net' => $visit->net_amount, 'paid' => $visit->paid_amount,
            'consultation_fee' => (float) $visit->consultation_fee,
            'patient_group' => $visit->patient?->patientGroup?->slug,
            'doctor_percentage' => (float) $visit->doctor?->compensation_percentage,
            'category_percentages' => $visit->doctor?->compensation_category_percentages,
            'israeli_salary_type' => $visit->doctor?->israeli_visit_salary_type,
            'israeli_salary_rate' => $visit->doctor?->israeli_visit_salary_rate,
            'owner_split' => $visit->usesOwnerSplit()];
        $result['items'] = $items->map(function ($item) use ($resolved, $salaryResolved, $unsettled, $visit) {
            // Match using exactly the resolver's SQL normalization, including DB collation.
            $normalized = DB::table('visit_treatment_cases as i')->leftJoin('treatment_cases as c', 'c.id', '=', 'i.treatment_case_id')
                ->where('i.id', $item->id)->selectRaw("LOWER(TRIM(COALESCE(c.name, i.custom_service_name, ''))) AS name")->value('name');
            $mapping = ProcedureCatalogMapping::where('normalized_name', $normalized)->first();
            $exact = TreatmentCase::whereRaw('LOWER(TRIM(name)) = ?', [$normalized])->whereRaw("TRIM(COALESCE(category, '')) <> ''")->get(['id', 'category']);
            $classification = $resolved[$item->id];
            $category = $classification->category === null ? null : DB::table('treatment_categories')->where('id', $classification->category)->value('name');
            $group = $classification->statistics_group === null ? null : DB::table('treatment_statistics_groups')->where('id', $classification->statistics_group)->value('name');

            return ['id' => $item->id, 'catalog_id' => $item->treatment_case_id, 'lab_linked' => $item->lab_main_work_id !== null,
                'manual_name_present' => filled($item->custom_service_name),
                'service_name_has_imaging_token' => $this->hasImagingToken($normalized),
                'original_category' => $item->treatmentCase?->category,
                'mapping_id' => $mapping?->id, 'mapping_catalog_id' => $mapping?->treatment_case_id,
                'mapping_target_category' => $mapping ? TreatmentCase::whereKey($mapping->treatment_case_id)->value('category') : null,
                'unique_name_match_count' => $exact->count(), 'unique_name_catalog_id' => $exact->count() === 1 ? $exact->first()->id : null,
                'resolved_catalog_id' => $classification->id, 'resolved_category' => $classification->category,
                'salary_category' => $salaryResolved[$item->id]->category,
                'salary_classification_source' => $salaryResolved[$item->id]->classification_source,
                'resolved_statistics_group' => $classification->statistics_group,
                'category_label' => $this->safeClassificationLabel($category),
                'group_label' => $this->safeClassificationLabel($group),
                'original_catalog_takes_precedence' => filled($item->treatmentCase?->category),
                'quantity' => (int) $item->quantity, 'unit_price' => (float) $item->unit_price,
                'currency' => $item->currency, 'exchange_rate' => (float) $item->exchange_rate,
                'native_line_total' => $item->manipulation_total,
                'visit_currency_line_total' => round($item->manipulation_total * ($item->currency === $visit->currency ? 1 : (float) $item->exchange_rate), 2),
                'direct_expenses_in_visit_currency' => (float) $item->directExpenses->where('currency', $visit->currency)->sum('amount'),
                'classification_excluded' => (bool) $item->salary_excluded,
                'salary_eligible' => $item->isSalaryEligible(), 'unsettled' => in_array($item->id, $unsettled, true)];
        })->all();
        $convertedTotal = round(array_sum(array_column($result['items'], 'visit_currency_line_total'))
            + ($visit->visit_type === 'consultation' ? (float) $visit->consultation_fee : 0), 2);
        $result['item_total_matches_stored_gross'] = $visit->gross_amount !== null && abs($convertedTotal - $visit->gross_amount) < 0.01;
        $result['live_calculation'] = $visit->doctor_id === null ? null : $this->live($visit, $items->modelKeys());
        $result['settled_snapshots'] = DB::table('salary_settlement_items as i')->join('salary_settlements as s', 's.id', '=', 'i.salary_settlement_id')
            ->where('i.visit_id', $visitId)->orderBy('i.id')->get(['i.id', 'i.visit_treatment_case_id', 's.id as settlement_id', 's.status',
                's.uses_allocations', 's.currency', 's.settled_at', 'i.revenue', 'i.base_snapshot', 'i.doctor_share_snapshot'])->all();
        // Only the latest cycle's remaining rows drive the existing payroll preview.
        $cycle = ClinicPayrollCycle::whereIn('status', ['partial', 'finalized'])->orderByDesc('payroll_date')->first();
        $result['deferred_snapshot'] = [];
        foreach ($cycle?->snapshot['remaining']['doctors'] ?? [] as $row) {
            if ((int) $row['id'] !== (int) $visit->doctor_id) {
                continue;
            }
            $details = collect($row['report']['details'] ?? []);
            $visitDetails = $details->where('visit_id', $visitId);
            if ($visitDetails->isEmpty()) {
                continue;
            }
            $oldIds = $details->flatMap(fn ($detail) => $detail['items'])->where('source_type', 'visit')->pluck('id')->all();
            $trigger = VisitTreatmentCase::whereKey($oldIds)->select('id')->withSalaryEligibility()->get()->contains(fn ($item) => (bool) $item->salary_excluded);
            $result['deferred_snapshot'][] = ['cycle_id' => $cycle->id, 'cycle_status' => $cycle->status,
                'period_start' => $row['period_start'], 'period_end' => $row['period_end'],
                'automatic_reprojection_trigger' => $trigger,
                'stored_visit_rows' => $visitDetails->map(fn ($detail) => $this->numericDetail($detail))->values()->all()];
        }

        return $result;
    }

    private function live(Visit $visit, array $ids): array
    {
        $report = app(DoctorCompensationCalculator::class)->calculate($visit->doctor_id,
            $visit->visit_date->toDateString(), $visit->visit_date->toDateString(),
            patientGroup: $visit->patient?->patientGroup?->slug ?? DoctorCompensationCalculator::GROUP_ALL,
            selectedLabWorkIds: [], selectedManualCaseIds: [], selectedVisitItemIds: $ids, selectedOwnerShareIds: []);

        return ['path' => DoctorCompensationCalculator::class.'::calculate -> calculateVisit',
            'visit_rows' => collect($report['details'])->where('visit_id', $visit->id)->map(fn ($detail) => $this->numericDetail($detail))->values()->all()];
    }

    private function numericDetail(array $detail): array
    {
        return [...Arr::only($detail, ['currency', 'work_total', 'total_value', 'final_payable', 'paid_total', 'outstanding_total', 'expense_total', 'base_total', 'doctor_share', 'owner_split']),
            'items' => array_map(fn ($item) => Arr::only($item, ['id', 'source_type', 'quantity', 'category', 'revenue', 'direct_expense', 'paid_amount', 'salary_base', 'applied_percentage', 'doctor_share']), $detail['items'])];
    }

    private function safeClassificationLabel(?string $label): ?array
    {
        if ($label === null) {
            return null;
        }
        $normalized = mb_strtolower(trim($label));

        // Managed labels may contain arbitrary free text: only emit known words,
        // a digest and token flags, never a patient's name accidentally used as a label.
        return ['known_label' => in_array($label, TreatmentCase::CATEGORIES, true) ? $label : null,
            'sha256' => hash('sha256', $normalized),
            'has_imaging_token' => $this->hasImagingToken($normalized)];
    }

    private function hasImagingToken(string $normalized): bool
    {
        // Diagnostic evidence only, never a compensation eligibility rule.
        return preg_match('/ტომოგრაფ|რადიოლოგ|რენტგენ|tomograph|radiolog|radiograph/u', $normalized) === 1;
    }
}
