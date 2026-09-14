<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\LabMainWork;
use App\Models\OwnerSalaryShare;
use App\Models\PatientGroup;
use App\Models\SalarySettlement;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DoctorCompensationCalculator
{
    public const GROUP_ALL = 'all';

    /** Leave ordinary zero-payable work available for a later payment. Explicit
     * full-discount decisions still belong to the existing salary-fix snapshot. */
    public function finalizableClinicReport(array $report): array
    {
        $report['details'] = collect($report['details'])->map(function ($row) {
            if ($row['patient_group_slug'] === PatientGroup::CLINIC_SLUG) {
                $row['items'] = array_values(array_filter($row['items'], fn ($item) => $item['doctor_share'] > 0 || ($item['is_full_discount'] ?? false)));
            }

            return $row;
        })->filter(fn ($row) => $row['items'] !== [])->values()->all();

        return $report;
    }

    public function defaultPeriodStart(int $doctorId, string $patientGroup = self::GROUP_ALL): string
    {
        $firstVisitDate = Visit::query()
            ->where('doctor_id', $doctorId)
            ->when($patientGroup !== self::GROUP_ALL, fn (Builder $query): Builder => $query
                ->whereHas('patient.patientGroup', fn (Builder $group): Builder => $group->where('slug', $patientGroup)))
            ->whereHas('treatmentCaseItems', fn (Builder $query): Builder => $this->unsettledItems($query))
            ->orderBy('visit_date')
            ->value('visit_date');

        $doctor = Doctor::query()->find($doctorId);
        $firstLabDate = in_array($patientGroup, [self::GROUP_ALL, PatientGroup::ISRAEL_PARTNER_SLUG], true)
            && $doctor
                ? app(IsraeliLabSalaryItems::class)->eligible($doctor)
                    ->map(fn (LabMainWork $work) => $work->labCase->case_date->toDateString())->min()
                : null;

        $firstUnsettledDate = collect([$firstVisitDate, $firstLabDate])
            ->filter()
            ->sort()
            ->first();

        return $firstUnsettledDate
            ? date('Y-m-d', strtotime((string) $firstUnsettledDate))
            : today()->toDateString();
    }

    public function eligibleVisitsQuery(int $doctorId, string $from, string $until, string $patientGroup = self::GROUP_ALL): Builder
    {
        return Visit::query()
            ->where('doctor_id', $doctorId)
            ->whereDate('visit_date', '>=', $from)
            ->whereDate('visit_date', '<=', $until)
            ->when($patientGroup !== self::GROUP_ALL, fn (Builder $query): Builder => $query
                ->whereHas('patient.patientGroup', fn (Builder $group): Builder => $group->where('slug', $patientGroup)))
            ->whereHas('treatmentCaseItems', fn (Builder $query): Builder => $this->unsettledItems($query));
    }

    /** @return array<int, string> */
    public function cutoffVisitOptions(int $doctorId, string $from, string $until, ?string $search = null, string $patientGroup = self::GROUP_ALL): array
    {
        $query = $this->eligibleVisitsQuery($doctorId, $from, $until, $patientGroup);

        if (filled($search)) {
            $normalizedSearch = mb_strtolower(trim((string) $search));
            $pattern = '%'.$normalizedSearch.'%';
            preg_match('/(?:visit\s*#?\s*)?(\d+)/iu', $normalizedSearch, $idMatch);

            $query->where(function (Builder $query) use ($idMatch, $pattern): void {
                if (isset($idMatch[1])) {
                    $query->orWhere('id', (int) $idMatch[1]);
                }

                $query->orWhereHas('patient', fn (Builder $patient): Builder => $patient
                    ->whereRaw('LOWER(first_name) LIKE ?', [$pattern])
                    ->orWhereRaw('LOWER(last_name) LIKE ?', [$pattern])
                    ->orWhereRaw("LOWER(first_name || ' ' || last_name) LIKE ?", [$pattern])
                    ->orWhereRaw("LOWER(last_name || ' ' || first_name) LIKE ?", [$pattern]));
            });
        }

        return $this->orderedVisits($query)
            ->with(['patient', 'treatmentCaseItems' => fn ($query) => $query
                ->salaryUnsettled()->with('treatmentCase')])
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Visit $visit): array => [$visit->getKey() => $this->cutoffVisitLabelFromModel($visit)])
            ->all();
    }

    public function cutoffVisitLabel(int $doctorId, string $from, string $until, int $visitId, string $patientGroup = self::GROUP_ALL): ?string
    {
        $visit = $this->eligibleVisitsQuery($doctorId, $from, $until, $patientGroup)
            ->whereKey($visitId)
            ->with('patient')
            ->first();

        return $visit ? $this->cutoffVisitLabelFromModel($visit) : null;
    }

    /** @return array<string, mixed> */
    public function calculate(
        int $doctorId,
        string $from,
        string $until,
        ?float $percentage = null,
        ?int $cutoffVisitId = null,
        string $patientGroup = self::GROUP_ALL,
        ?array $selectedLabWorkIds = null,
        bool $israeliLabOnly = false,
        array $approvedFullDiscountItemIds = [],
    ): array {
        $approvedFullDiscountItems = array_fill_keys(array_map('intval', array_filter(
            $approvedFullDiscountItemIds,
            fn (mixed $id): bool => (is_int($id) || (is_string($id) && ctype_digit($id))) && (int) $id > 0,
        )), true);
        $israeliLabOnly = $israeliLabOnly && $patientGroup === PatientGroup::ISRAEL_PARTNER_SLUG;
        if ($israeliLabOnly) {
            $cutoffVisitId = null;
        }
        $doctor = Doctor::query()->findOrFail($doctorId);
        $defaultPercentage = (float) ($doctor->compensation_percentage ?? 0);
        $percentage ??= $defaultPercentage;
        $categoryPercentages = collect($doctor->compensation_category_percentages ?? [])
            ->map(fn (mixed $value): float => (float) $value);
        $useCategoryPercentages = $categoryPercentages->isNotEmpty()
            && abs($percentage - $defaultPercentage) < 0.00001;

        if ($percentage < 0 || $percentage > 100) {
            throw ValidationException::withMessages(['percentage' => 'ექიმის პროცენტი უნდა იყოს 0-დან 100-მდე.']);
        }

        $visitsQuery = $this->eligibleVisitsQuery($doctorId, $from, $until, $patientGroup);
        if ($israeliLabOnly) {
            $visitsQuery->whereRaw('1 = 0');
        }

        if ($cutoffVisitId !== null) {
            $cutoffVisit = $this->eligibleVisitsQuery($doctorId, $from, $until, $patientGroup)
                ->whereKey($cutoffVisitId)
                ->first();

            if (! $cutoffVisit) {
                throw ValidationException::withMessages([
                    'cutoff_visit_id' => 'არჩეული საბოლოო Visit აღარ არის ხელმისაწვდომი.',
                ]);
            }

            $this->applyCutoff($visitsQuery, $cutoffVisit);
        }

        $visits = $this->orderedVisits($visitsQuery)
            ->with(['doctor', 'patient.patientGroup', 'payments', 'treatmentCaseItems' => fn ($query) => $query
                ->salaryUnsettled()->with(['treatmentCase', 'directExpenses'])])
            ->get();

        $details = $visits->map(fn (Visit $visit): array => $this->calculateVisit(
            $visit, $percentage, $categoryPercentages, $useCategoryPercentages, $approvedFullDiscountItems,
        ))->values()->all();

        if (in_array($patientGroup, [self::GROUP_ALL, PatientGroup::ISRAEL_PARTNER_SLUG], true)) {
            $labDetails = app(IsraeliLabSalaryItems::class)->eligible($doctor, $from, $until)
                ->map(function (LabMainWork $work) use ($doctor): array {
                    $unitRate = $work->material === 'pmma' ? IsraeliLabSalaryItems::PMMA_RATE : (float) $doctor->israeli_lab_zircon_rate;
                    $name = $work->material === 'pmma' ? 'PMMA' : 'Zircon';
                    $amount = app(IsraeliLabSalaryItems::class)->amount($work, $doctor);
                    $item = [
                        'id' => $work->getKey(), 'source_type' => 'lab', 'name' => $name,
                        'quantity' => $work->quantity, 'unit_rate' => $unitRate,
                        'revenue' => $amount, 'direct_expense' => 0.0, 'expenses' => [],
                        'paid_amount' => $amount, 'outstanding_amount' => 0.0,
                        'salary_base' => $amount, 'applied_percentage' => null,
                        'doctor_share' => $amount,
                    ];

                    return [
                        'visit_id' => null, 'lab_case_id' => $work->lab_case_id,
                        'source_key' => 'lab-'.$work->getKey(),
                        'visit_date' => $work->labCase->case_date->format('d.m.Y'),
                        'patient' => $work->labCase->patient?->lab_name ?? '—',
                        'manipulations' => $name, 'patient_group_slug' => PatientGroup::ISRAEL_PARTNER_SLUG,
                        'patient_group_name' => 'Israeli', 'items' => [$item], 'currency' => Currency::DEFAULT,
                        'work_total' => $amount, 'total_value' => $amount, 'discount_total' => 0.0,
                        'final_payable' => $amount, 'paid_total' => $amount, 'outstanding_total' => 0.0,
                        'expense_total' => 0.0, 'base_total' => $amount, 'doctor_share' => $amount,
                        'owner_split' => false, 'owner_split_override' => null, 'source_type' => 'lab',
                    ];
                })->all();

            $details = [...$details, ...$labDetails];
        }

        if ($patientGroup !== self::GROUP_ALL) {
            $details = collect($details)
                ->where('patient_group_slug', $patientGroup)
                ->values()
                ->all();
        }

        if ($selectedLabWorkIds !== null && $patientGroup === PatientGroup::ISRAEL_PARTNER_SLUG) {
            $selectedLabWorkIds = array_map('intval', $selectedLabWorkIds);
            $details = collect($details)->filter(fn (array $row): bool => $row['source_type'] !== 'lab'
                || in_array((int) $row['items'][0]['id'], $selectedLabWorkIds, true))->values()->all();
        }

        $incomingOwnerShares = OwnerSalaryShare::query()
            ->when($israeliLabOnly, fn (Builder $query): Builder => $query->whereRaw('1 = 0'))
            ->where('recipient_doctor_id', $doctorId)
            ->where('status', 'pending')
            ->when($patientGroup !== self::GROUP_ALL, fn (Builder $query): Builder => $query
                ->where('patient_group_slug', $patientGroup))
            ->with(['visit.patient', 'sourceDoctor'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->map(fn (OwnerSalaryShare $share): array => [
                'id' => $share->getKey(),
                'visit_id' => $share->visit_id,
                'visit_date' => $share->visit?->visit_date?->format('d.m.Y'),
                'patient' => $share->visit?->patient?->full_name ?? '—',
                'source_doctor' => $share->sourceDoctor?->full_name ?? '—',
                'patient_group_slug' => $share->patient_group_slug,
                'currency' => $share->currency,
                'amount' => round((float) $share->amount, 2),
            ])->values()->all();

        $aggregate = function ($rows): array {
            $work = round((float) $rows->sum('work_total'), 2);
            $totalValue = round((float) $rows->sum('total_value'), 2);
            $finalPayable = round((float) $rows->sum('final_payable'), 2);
            $paid = round((float) $rows->sum('paid_total'), 2);
            $outstanding = round((float) $rows->sum('outstanding_total'), 2);
            $expense = round((float) $rows->sum('expense_total'), 2);
            $base = round((float) $rows->sum('base_total'), 2);

            $doctorShare = round((float) $rows->sum('doctor_share'), 2);

            return ['visits_count' => $rows->count(), 'work_total' => $work, 'total_value' => $totalValue,
                'discount_total' => round($totalValue - $finalPayable, 2), 'final_payable' => $finalPayable,
                'paid_total' => $paid, 'outstanding_total' => $outstanding, 'expense_total' => $expense,
                'base_total' => $base, 'normal_doctor_share' => $doctorShare,
                'owner_split_received' => 0.0, 'doctor_share' => $doctorShare];
        };
        $totals = collect($details)->groupBy('currency')->map($aggregate)->all();
        $totalsByGroup = collect($details)->groupBy('patient_group_slug')->map(
            fn ($rows): array => $rows->groupBy('currency')->map($aggregate)->all()
        )->all();

        foreach (collect($incomingOwnerShares)->groupBy(fn (array $share): string => $share['patient_group_slug'].'|'.$share['currency']) as $key => $shares) {
            [$groupSlug, $currency] = explode('|', $key, 2);
            $amount = round((float) $shares->sum('amount'), 2);
            $empty = ['visits_count' => 0, 'work_total' => 0.0, 'total_value' => 0.0, 'discount_total' => 0.0,
                'final_payable' => 0.0, 'paid_total' => 0.0, 'outstanding_total' => 0.0, 'expense_total' => 0.0,
                'base_total' => 0.0, 'normal_doctor_share' => 0.0, 'owner_split_received' => 0.0, 'doctor_share' => 0.0];
            $totals[$currency] ??= $empty;
            $totals[$currency]['owner_split_received'] = round($totals[$currency]['owner_split_received'] + $amount, 2);
            $totals[$currency]['doctor_share'] = round($totals[$currency]['doctor_share'] + $amount, 2);
            $totalsByGroup[$groupSlug][$currency] ??= $empty;
            $totalsByGroup[$groupSlug][$currency]['owner_split_received'] = round($totalsByGroup[$groupSlug][$currency]['owner_split_received'] + $amount, 2);
            $totalsByGroup[$groupSlug][$currency]['doctor_share'] = round($totalsByGroup[$groupSlug][$currency]['doctor_share'] + $amount, 2);
        }

        $counterpart = $doctor->isOwnerSplitDoctor()
            ? Doctor::query()->whereKeyNot($doctorId)->whereNotNull('owner_split_key')->first()
            : null;
        $ownerSplitPreview = $counterpart
            ? collect($details)->where('owner_split', true)->groupBy('currency')->map(
                fn ($rows, string $currency): array => [
                    'source_doctor' => $doctor->full_name,
                    'counterpart_doctor' => $counterpart->full_name,
                    'counterpart_key' => $counterpart->owner_split_key,
                    'currency' => $currency,
                    'net_basis' => round((float) $rows->sum('base_total'), 2),
                    'source_share' => round((float) $rows->sum('doctor_share'), 2),
                    'counterpart_share' => round((float) $rows->sum('doctor_share'), 2),
                    'visits' => $rows->map(fn (array $row): array => [
                        'visit_id' => $row['visit_id'],
                        'manipulations' => $row['manipulations'],
                        'net_basis' => $row['base_total'],
                        'counterpart_share' => $row['doctor_share'],
                    ])->values()->all(),
                ],
            )->values()->all()
            : [];

        return ['doctor_id' => $doctor->getKey(), 'doctor_name' => $doctor->full_name, 'from' => $from,
            'until' => $until, 'cutoff_visit_id' => $cutoffVisitId, 'percentage' => $percentage,
            'patient_group' => $patientGroup, 'totals' => $totals,
            'totals_by_group' => $totalsByGroup, 'details' => $details,
            'owner_split_income' => $incomingOwnerShares, 'owner_split_preview' => $ownerSplitPreview];
    }

    /**
     * Compact current payable totals. Confirmed item snapshots are the cutoff, rather
     * than a calendar-day exclusion that would lose later same-day or unpaid work.
     * Numeric visit inputs are processed in bounded batches; no modal details are loaded.
     */
    public function payableSummaries(Collection $doctors, bool $clinicCycle = false): array
    {
        if ($doctors->isEmpty()) {
            return [];
        }
        $doctors = $doctors->keyBy('id');
        $totals = [];
        $add = function (int $doctorId, string $source, string $currency, float $amount) use (&$totals): void {
            $totals[$doctorId][$source][$currency] = round(($totals[$doctorId][$source][$currency] ?? 0) + $amount, 2);
        };

        Visit::query()
            ->select(['id', 'doctor_id', 'currency', 'total_price', 'discount_type', 'discount_value', 'discount_amount', 'cancelled_at', 'owner_split_override'])
            ->selectSub(DB::table('payments')
                ->selectRaw('COALESCE(SUM(amount), 0)')->whereColumn('visit_id', 'visits.id')
                ->whereColumn('currency', 'visits.currency'), 'salary_paid')
            ->whereIn('doctor_id', $doctors->keys())
            ->when($clinicCycle, fn ($query) => $query->whereHas('doctor', fn ($query) => $query->where('is_active', true)))
            ->whereDate('visit_date', '<=', today())
            ->whereHas('patient.patientGroup', fn (Builder $query) => $query->where('slug', PatientGroup::CLINIC_SLUG))
            ->whereHas('treatmentCaseItems', fn (Builder $query) => $this->unsettledItems($query))
            ->with(['treatmentCaseItems' => fn ($query) => $query->salaryUnsettled()
                ->select(['id', 'visit_id', 'treatment_case_id', 'lab_main_work_id', 'quantity', 'unit_price'])
                ->selectSub(DB::table('direct_expenses')
                    ->join('visits as expense_visits', 'expense_visits.id', '=', 'visit_treatment_cases.visit_id')
                    ->selectRaw('COALESCE(SUM(direct_expenses.amount), 0)')
                    ->whereColumn('visit_treatment_case_id', 'visit_treatment_cases.id')
                    ->whereColumn('direct_expenses.currency', 'expense_visits.currency'), 'salary_expense')
                ->with('treatmentCase:id,category,triggers_owner_split')])
            ->chunkById(200, function ($visits) use ($doctors, $add, $clinicCycle): void {
                foreach ($visits as $visit) {
                    $doctor = $doctors->get($visit->doctor_id);
                    $visit->setRelation('doctor', $doctor);
                    $visit->setAttribute('salary_group', PatientGroup::CLINIC_SLUG);
                    $categories = collect($doctor->compensation_category_percentages ?? [])->map(fn ($value): float => (float) $value);
                    $row = $this->calculateVisit($visit, (float) $doctor->compensation_percentage, $categories, $categories->isNotEmpty(), compact: true);
                    $add($doctor->id, 'clinic', $row['currency'], $row['doctor_share']);
                    if ($clinicCycle && $row['owner_split']) {
                        $counterpart = $doctors->first(fn ($other) => $other->id !== $doctor->id && $other->owner_split_key !== null);
                        if ($counterpart) {
                            $add($counterpart->id, 'clinic', $row['currency'], $row['doctor_share']);
                        }
                    }
                }
            });

        $lab = app(IsraeliLabSalaryItems::class);
        if (! $clinicCycle) {
            $pending = SalarySettlement::query()->unpaidAllocations()->whereIn('doctor_id', $doctors->keys())
                ->selectRaw('doctor_id, SUM(salary_total - COALESCE((SELECT SUM(total_gel) FROM salary_payouts WHERE salary_settlement_id = salary_settlements.id), 0)) AS remaining_gel')
                ->groupBy('doctor_id')->get();
            foreach ($pending as $salary) {
                $add($salary->doctor_id, 'israeli', 'GEL', (float) $salary->remaining_gel);
            }
        }
        foreach ($clinicCycle ? [] : $lab->eligibleForDoctors($doctors, until: today()->toDateString(), compact: true) as $work) {
            $doctor = $doctors->get($work->labCase->doctor_id);
            $add($doctor->id, 'israeli', Currency::DEFAULT, $lab->amount($work, $doctor));
        }
        // The Israeli modal is lab-only, and therefore does not include owner shares.
        $shares = OwnerSalaryShare::query()->whereIn('recipient_doctor_id', $clinicCycle ? $doctors->where('is_active', true)->keys() : $doctors->keys())
            ->where('status', 'pending')->where('patient_group_slug', PatientGroup::CLINIC_SLUG)
            ->selectRaw('recipient_doctor_id, currency, SUM(amount) as amount')
            ->groupBy('recipient_doctor_id', 'currency')->get();
        foreach ($shares as $share) {
            $add($share->recipient_doctor_id, 'clinic', $share->currency, (float) $share->amount);
        }

        return $totals;
    }

    /** The shared per-visit rules used by both the modal and compact overview. */
    private function calculateVisit(Visit $visit, float $percentage, Collection $categoryPercentages, bool $useCategoryPercentages, array $approvedFullDiscountItems = [], bool $compact = false): array
    {
        $currency = $visit->currency ?: Currency::DEFAULT;
        $allWork = round((float) $visit->treatmentCaseItems->sum('manipulation_total'), 2);
        $items = $visit->treatmentCaseItems
            ->filter(fn (VisitTreatmentCase $item): bool => $item->isSalaryEligible())
            ->map(function (VisitTreatmentCase $item) use ($currency, $compact): array {
                $revenue = round($item->manipulation_total, 2);
                $expense = round((float) ($compact ? $item->salary_expense : $item->directExpenses->where('currency', $currency)->sum('amount')), 2);

                return ['id' => $item->getKey(), 'source_type' => 'visit', 'name' => $compact ? '' : $item->display_name, 'quantity' => (int) $item->quantity,
                    'category' => $item->treatmentCase?->category,
                    'revenue' => $revenue, 'direct_expense' => $expense,
                    'expenses' => $compact ? [] : $item->directExpenses->where('currency', $currency)->map(fn ($expense): array => [
                        'id' => $expense->getKey(), 'name' => $expense->name, 'amount' => (float) $expense->amount,
                    ])->values()->all()];
            })->values();
        $work = round((float) $items->sum('revenue'), 2);
        $visitFullValue = round((float) ($visit->gross_amount ?? $allWork), 2);
        $visitFinalPayable = round((float) ($visit->net_amount ?? $allWork), 2);
        $visitPaid = round(min($visitFinalPayable, max(0, ($compact ? (float) $visit->salary_paid : $visit->paid_amount))), 2);
        $eligibleRatio = $visitFullValue > 0 ? min($work / $visitFullValue, 1) : 0;
        $fullValue = $work;
        $finalPayable = round(min($work, $visitFinalPayable * $eligibleRatio), 2);
        $paid = round(min($finalPayable, $visitPaid * $eligibleRatio), 2);
        $outstanding = round(max($finalPayable - $paid, 0), 2);
        $expense = round((float) $items->sum('direct_expense'), 2);
        $groupSlug = $compact ? $visit->salary_group : ($visit->patient?->patientGroup?->slug ?? PatientGroup::CLINIC_SLUG);
        $isPartner = $groupSlug === PatientGroup::ISRAEL_PARTNER_SLUG;
        $ownerSplit = $visit->usesOwnerSplit();
        $isFullDiscount = $visit->discount_type === 'percent' && (float) $visit->discount_value === 100.0;
        $requiresSalaryApproval = $isFullDiscount && ! $isPartner && ! $ownerSplit;
        $salaryFromOriginalValue = $requiresSalaryApproval
            && $visitFinalPayable === 0.0 && $visitPaid === 0.0 && $work > 0;
        $base = round(max(($ownerSplit ? $paid : ($isPartner ? $work : $paid)) - $expense, 0), 2);
        if ($salaryFromOriginalValue) {
            $base = round(max($work - $expense, 0), 2);
        }
        $remainingPaid = $paid;
        $remainingBase = $base;
        $lastItemIndex = $items->count() - 1;
        if ($salaryFromOriginalValue) {
            $lastItemIndex = $items->filter(fn (array $item): bool => $item['revenue'] > 0)->keys()->last();
        }
        $items = $items->map(function (array $item, int $index) use (
            $work,
            $paid,
            $base,
            $ownerSplit,
            $percentage,
            $categoryPercentages,
            $useCategoryPercentages,
            $lastItemIndex,
            $isFullDiscount,
            $requiresSalaryApproval,
            $approvedFullDiscountItems,
            &$remainingPaid,
            &$remainingBase,
        ): array {
            $ratio = $work > 0 ? $item['revenue'] / $work : 0;
            $itemPaid = $index === $lastItemIndex ? $remainingPaid : round($paid * $ratio, 2);
            $itemBase = $index === $lastItemIndex ? $remainingBase : round($base * $ratio, 2);
            $remainingPaid = round($remainingPaid - $itemPaid, 2);
            $remainingBase = round($remainingBase - $itemBase, 2);
            $itemPercentage = $ownerSplit
                ? 50.0
                : ($useCategoryPercentages
                ? (float) $categoryPercentages->get($item['category'], $percentage)
                : $percentage);

            $potentialShare = round($itemBase * $itemPercentage / 100, 2);
            $salaryApproved = $requiresSalaryApproval ? isset($approvedFullDiscountItems[$item['id']]) : null;

            return [...$item,
                'paid_amount' => $itemPaid,
                'outstanding_amount' => round(max($item['revenue'] - $itemPaid, 0), 2),
                'salary_base' => $itemBase,
                'applied_percentage' => $itemPercentage,
                'is_full_discount' => $isFullDiscount,
                'requires_salary_approval' => $requiresSalaryApproval,
                'potential_doctor_share' => $potentialShare,
                'salary_approved' => $salaryApproved,
                'doctor_share' => $salaryApproved === false ? 0.0 : $potentialShare];
        });
        $share = round((float) $items->sum('doctor_share'), 2);

        if ($compact) {
            return ['doctor_share' => $share, 'currency' => $currency, 'patient_group_slug' => $groupSlug, 'owner_split' => $ownerSplit];
        }

        return ['visit_id' => $visit->getKey(), 'source_key' => 'visit-'.$visit->getKey(),
            'source_type' => 'visit', 'visit_date' => $visit->visit_date->format('d.m.Y'),
            'patient' => $visit->patient?->full_name ?? '—', 'manipulations' => $items->pluck('name')->implode(', '),
            'patient_group_slug' => $groupSlug,
            'salary_from_original_value' => $salaryFromOriginalValue,
            'discount_reason' => $salaryFromOriginalValue
                ? (Visit::DISCOUNT_REASONS[$visit->discount_reason] ?? null) : null,
            'patient_group_name' => $visit->patient?->patientGroup?->name ?? 'Clinic',
            'items' => $items->all(), 'currency' => $currency, 'work_total' => $work, 'total_value' => $fullValue,
            'discount_total' => round($fullValue - $finalPayable, 2), 'final_payable' => $finalPayable,
            'paid_total' => $paid, 'outstanding_total' => $outstanding,
            'expense_total' => $expense, 'base_total' => $base,
            'doctor_share' => $share, 'owner_split' => $ownerSplit,
            'owner_split_override' => $visit->owner_split_override];
    }

    private function orderedVisits(Builder $query): Builder
    {
        $query->orderByDesc('visit_date');

        if ($this->hasVisitTime()) {
            $query->orderByRaw('visit_time DESC NULLS LAST');
        }

        return $query->orderByDesc('id');
    }

    private function unsettledItems(Builder $query): Builder
    {
        return $query->salaryUnsettled()->salaryEligible();
    }

    private function cutoffVisitLabelFromModel(Visit $visit): string
    {
        $parts = [$visit->patient?->full_name ?? '—'];

        if ($this->hasVisitTime() && filled($visit->getAttribute('visit_time'))) {
            $parts[] = substr((string) $visit->getAttribute('visit_time'), 0, 5);
        }

        $parts[] = 'Visit #'.$visit->getKey();

        return implode(' — ', $parts);
    }

    private function applyCutoff(Builder $query, Visit $cutoffVisit): void
    {
        $cutoffDate = $cutoffVisit->visit_date->toDateString();

        $query->where(function (Builder $query) use ($cutoffVisit, $cutoffDate): void {
            $query->whereDate('visit_date', '<', $cutoffDate)
                ->orWhere(function (Builder $query) use ($cutoffVisit, $cutoffDate): void {
                    $query->whereDate('visit_date', $cutoffDate);

                    if (! $this->hasVisitTime()) {
                        $query->where('id', '<=', $cutoffVisit->getKey());

                        return;
                    }

                    $cutoffTime = $cutoffVisit->getAttribute('visit_time');
                    $query->where(function (Builder $query) use ($cutoffVisit, $cutoffTime): void {
                        if (filled($cutoffTime)) {
                            $query->where('visit_time', '<', $cutoffTime)
                                ->orWhere(fn (Builder $query): Builder => $query
                                    ->where('visit_time', $cutoffTime)
                                    ->where('id', '<=', $cutoffVisit->getKey()));

                            return;
                        }

                        $query->whereNotNull('visit_time')
                            ->orWhere(fn (Builder $query): Builder => $query
                                ->whereNull('visit_time')
                                ->where('id', '<=', $cutoffVisit->getKey()));
                    });
                });
        });
    }

    private function hasVisitTime(): bool
    {
        return once(fn (): bool => Schema::hasColumn('visits', 'visit_time'));
    }

    /** @return array<string, mixed> */
    public function summary(Doctor $doctor): array
    {
        $report = $this->calculate($doctor->getKey(), '1900-01-01', today()->toDateString());
        $last = $doctor->salarySettlements()
            ->with(['items.visit.patient'])
            ->latest('settled_at')
            ->latest('id')
            ->first();
        $lastItem = $last?->last_included_item;

        return ['totals' => $report['totals'], 'last_settled_at' => $last?->settled_at,
            'last_salary' => $last ? Currency::format((float) $last->salary_total, $last->currency) : '—',
            'last_patient' => $lastItem?->visit?->patient?->full_name ?? '—',
            'last_visit_id' => $lastItem?->visit_id];
    }
}
