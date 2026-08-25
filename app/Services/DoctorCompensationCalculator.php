<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class DoctorCompensationCalculator
{
    public function defaultPeriodStart(int $doctorId): string
    {
        $firstUnsettledDate = Visit::query()
            ->where('doctor_id', $doctorId)
            ->whereHas('treatmentCaseItems', fn ($query) => $query->whereDoesntHave('salarySettlementItem'))
            ->orderBy('visit_date')
            ->value('visit_date');

        return $firstUnsettledDate
            ? date('Y-m-d', strtotime((string) $firstUnsettledDate))
            : today()->toDateString();
    }

    public function eligibleVisitsQuery(int $doctorId, string $from, string $until): Builder
    {
        return Visit::query()
            ->where('doctor_id', $doctorId)
            ->whereDate('visit_date', '>=', $from)
            ->whereDate('visit_date', '<=', $until)
            ->whereHas('treatmentCaseItems', fn ($query) => $query->whereDoesntHave('salarySettlementItem'));
    }

    /** @return array<int, string> */
    public function cutoffVisitOptions(int $doctorId, string $until, ?string $search = null): array
    {
        $query = $this->eligibleVisitsQuery($doctorId, $until, $until);

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
                ->whereDoesntHave('salarySettlementItem')->with('treatmentCase')])
            ->limit(50)
            ->get()
            ->mapWithKeys(fn (Visit $visit): array => [$visit->getKey() => $this->cutoffVisitLabelFromModel($visit)])
            ->all();
    }

    public function cutoffVisitLabel(int $doctorId, string $until, int $visitId): ?string
    {
        $visit = $this->eligibleVisitsQuery($doctorId, $until, $until)
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
    ): array {
        $doctor = Doctor::query()->findOrFail($doctorId);
        $percentage ??= (float) ($doctor->compensation_percentage ?? 0);

        if ($percentage < 0 || $percentage > 100) {
            throw ValidationException::withMessages(['percentage' => 'ექიმის პროცენტი უნდა იყოს 0-დან 100-მდე.']);
        }

        $visitsQuery = $this->eligibleVisitsQuery($doctorId, $from, $until);

        if ($cutoffVisitId !== null) {
            $cutoffVisit = $this->eligibleVisitsQuery($doctorId, $until, $until)
                ->whereKey($cutoffVisitId)
                ->first();

            if (! $cutoffVisit) {
                throw ValidationException::withMessages([
                    'cutoff_visit_id' => 'არჩეული საბოლოო Visit აღარ არის ხელმისაწვდომი.',
                ]);
            }

            $this->applyCutoff($visitsQuery, $cutoffVisit, $until);
        }

        $visits = $this->orderedVisits($visitsQuery)
            ->with(['patient', 'treatmentCaseItems' => fn ($query) => $query
                ->whereDoesntHave('salarySettlementItem')->with(['treatmentCase', 'directExpenses'])])
            ->get();

        $details = $visits->map(function (Visit $visit) use ($percentage): array {
            $currency = $visit->currency ?: Currency::DEFAULT;
            $items = $visit->treatmentCaseItems->map(function (VisitTreatmentCase $item) use ($currency, $percentage): array {
                $revenue = round($item->manipulation_total, 2);
                $expense = round((float) $item->directExpenses->where('currency', $currency)->sum('amount'), 2);
                $base = round($revenue - $expense, 2);

                return ['id' => $item->getKey(), 'name' => $item->display_name, 'quantity' => (int) $item->quantity,
                    'revenue' => $revenue, 'direct_expense' => $expense, 'salary_base' => $base,
                    'doctor_share' => round($base * $percentage / 100, 2),
                    'expenses' => $item->directExpenses->where('currency', $currency)->map(fn ($expense): array => [
                        'id' => $expense->getKey(), 'name' => $expense->name, 'amount' => (float) $expense->amount,
                    ])->values()->all()];
            })->values();
            $work = round((float) $items->sum('revenue'), 2);
            $expense = round((float) $items->sum('direct_expense'), 2);
            $base = round($work - $expense, 2);

            return ['visit_id' => $visit->getKey(), 'visit_date' => $visit->visit_date->format('d.m.Y'),
                'patient' => $visit->patient?->full_name ?? '—', 'manipulations' => $items->pluck('name')->implode(', '),
                'items' => $items->all(), 'currency' => $currency, 'work_total' => $work,
                'expense_total' => $expense, 'base_total' => $base,
                'doctor_share' => round($base * $percentage / 100, 2)];
        })->values()->all();

        $totals = collect($details)->groupBy('currency')->map(function ($rows) use ($percentage): array {
            $work = round((float) $rows->sum('work_total'), 2);
            $expense = round((float) $rows->sum('expense_total'), 2);
            $base = round($work - $expense, 2);

            return ['visits_count' => $rows->count(), 'work_total' => $work, 'expense_total' => $expense,
                'base_total' => $base, 'doctor_share' => round($base * $percentage / 100, 2)];
        })->all();

        return ['doctor_id' => $doctor->getKey(), 'doctor_name' => $doctor->full_name, 'from' => $from,
            'until' => $until, 'cutoff_visit_id' => $cutoffVisitId, 'percentage' => $percentage,
            'totals' => $totals, 'details' => $details];
    }

    private function orderedVisits(Builder $query): Builder
    {
        $query->orderBy('visit_date');

        if (Schema::hasColumn('visits', 'visit_time')) {
            $query->orderByRaw('visit_time ASC NULLS LAST');
        }

        return $query->orderBy('id');
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

    private function applyCutoff(Builder $query, Visit $cutoffVisit, string $until): void
    {
        $query->where(function (Builder $query) use ($cutoffVisit, $until): void {
            $query->whereDate('visit_date', '<', $until)
                ->orWhere(function (Builder $query) use ($cutoffVisit, $until): void {
                    $query->whereDate('visit_date', $until);

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
