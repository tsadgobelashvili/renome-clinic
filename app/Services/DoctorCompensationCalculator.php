<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\OwnerSalaryShare;
use App\Models\PatientGroup;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DoctorCompensationCalculator
{
    public const GROUP_ALL = 'all';

    public function defaultPeriodStart(int $doctorId, string $patientGroup = self::GROUP_ALL): string
    {
        $firstUnsettledDate = Visit::query()
            ->where('doctor_id', $doctorId)
            ->when($patientGroup !== self::GROUP_ALL, fn (Builder $query): Builder => $query
                ->whereHas('patient.patientGroup', fn (Builder $group): Builder => $group->where('slug', $patientGroup)))
            ->whereHas('treatmentCaseItems', fn (Builder $query): Builder => $this->unsettledItems($query))
            ->orderBy('visit_date')
            ->value('visit_date');

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
    ): array {
        $doctor = Doctor::query()->findOrFail($doctorId);
        $percentage ??= (float) ($doctor->compensation_percentage ?? 0);

        if ($percentage < 0 || $percentage > 100) {
            throw ValidationException::withMessages(['percentage' => 'ექიმის პროცენტი უნდა იყოს 0-დან 100-მდე.']);
        }

        $visitsQuery = $this->eligibleVisitsQuery($doctorId, $from, $until, $patientGroup);

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

        $details = $visits->map(function (Visit $visit) use ($percentage): array {
            $currency = $visit->currency ?: Currency::DEFAULT;
            $allWork = round((float) $visit->treatmentCaseItems->sum('manipulation_total'), 2);
            $items = $visit->treatmentCaseItems
                ->filter(fn (VisitTreatmentCase $item): bool => $item->isSalaryEligible())
                ->map(function (VisitTreatmentCase $item) use ($currency): array {
                    $revenue = round($item->manipulation_total, 2);
                    $expense = round((float) $item->directExpenses->where('currency', $currency)->sum('amount'), 2);

                    return ['id' => $item->getKey(), 'name' => $item->display_name, 'quantity' => (int) $item->quantity,
                        'revenue' => $revenue, 'direct_expense' => $expense,
                        'expenses' => $item->directExpenses->where('currency', $currency)->map(fn ($expense): array => [
                            'id' => $expense->getKey(), 'name' => $expense->name, 'amount' => (float) $expense->amount,
                        ])->values()->all()];
                })->values();
            $work = round((float) $items->sum('revenue'), 2);
            $visitFullValue = round((float) ($visit->gross_amount ?? $allWork), 2);
            $visitFinalPayable = round((float) ($visit->net_amount ?? $allWork), 2);
            $visitPaid = round(min($visitFinalPayable, max(0, $visit->paid_amount)), 2);
            $eligibleRatio = $visitFullValue > 0 ? min($work / $visitFullValue, 1) : 0;
            $fullValue = $work;
            $finalPayable = round(min($work, $visitFinalPayable * $eligibleRatio), 2);
            $paid = round(min($finalPayable, $visitPaid * $eligibleRatio), 2);
            $outstanding = round(max($finalPayable - $paid, 0), 2);
            $expense = round((float) $items->sum('direct_expense'), 2);
            $groupSlug = $visit->patient?->patientGroup?->slug ?? PatientGroup::CLINIC_SLUG;
            $isPartner = $groupSlug === PatientGroup::ISRAEL_PARTNER_SLUG;
            $ownerSplit = $visit->usesOwnerSplit();
            $base = round(max(($ownerSplit ? $paid : ($isPartner ? $work : $paid)) - $expense, 0), 2);
            $sharePercentage = $ownerSplit ? 50.0 : $percentage;
            $share = round($base * $sharePercentage / 100, 2);
            $remainingPaid = $paid;
            $remainingBase = $base;
            $lastItemIndex = $items->count() - 1;
            $items = $items->map(function (array $item, int $index) use (
                $work,
                $paid,
                $base,
                $sharePercentage,
                $lastItemIndex,
                &$remainingPaid,
                &$remainingBase,
            ): array {
                $ratio = $work > 0 ? $item['revenue'] / $work : 0;
                $itemPaid = $index === $lastItemIndex ? $remainingPaid : round($paid * $ratio, 2);
                $itemBase = $index === $lastItemIndex ? $remainingBase : round($base * $ratio, 2);
                $remainingPaid = round($remainingPaid - $itemPaid, 2);
                $remainingBase = round($remainingBase - $itemBase, 2);

                return [...$item,
                    'paid_amount' => $itemPaid,
                    'outstanding_amount' => round(max($item['revenue'] - $itemPaid, 0), 2),
                    'salary_base' => $itemBase,
                    'doctor_share' => round($itemBase * $sharePercentage / 100, 2)];
            });

            return ['visit_id' => $visit->getKey(), 'visit_date' => $visit->visit_date->format('d.m.Y'),
                'patient' => $visit->patient?->full_name ?? '—', 'manipulations' => $items->pluck('name')->implode(', '),
                'patient_group_slug' => $groupSlug,
                'patient_group_name' => $visit->patient?->patientGroup?->name ?? 'Clinic',
                'items' => $items->all(), 'currency' => $currency, 'work_total' => $work, 'total_value' => $fullValue,
                'discount_total' => round($fullValue - $finalPayable, 2), 'final_payable' => $finalPayable,
                'paid_total' => $paid, 'outstanding_total' => $outstanding,
                'expense_total' => $expense, 'base_total' => $base,
                'doctor_share' => $share, 'owner_split' => $ownerSplit,
                'owner_split_override' => $visit->owner_split_override];
        })->values()->all();

        $incomingOwnerShares = OwnerSalaryShare::query()
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

        return ['doctor_id' => $doctor->getKey(), 'doctor_name' => $doctor->full_name, 'from' => $from,
            'until' => $until, 'cutoff_visit_id' => $cutoffVisitId, 'percentage' => $percentage,
            'patient_group' => $patientGroup, 'totals' => $totals,
            'totals_by_group' => $totalsByGroup, 'details' => $details,
            'owner_split_income' => $incomingOwnerShares];
    }

    private function orderedVisits(Builder $query): Builder
    {
        $query->orderByDesc('visit_date');

        if (Schema::hasColumn('visits', 'visit_time')) {
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

        if (Schema::hasColumn('visits', 'visit_time') && filled($visit->getAttribute('visit_time'))) {
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

                    if (! Schema::hasColumn('visits', 'visit_time')) {
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
