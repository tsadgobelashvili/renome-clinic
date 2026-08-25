<?php

namespace App\Services;

use App\Models\SalarySettlement;
use App\Models\VisitTreatmentCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalarySettlementService
{
    public function __construct(private readonly DoctorCompensationCalculator $calculator) {}

    /** @return array<int, SalarySettlement> */
    public function settle(
        int $doctorId,
        string $from,
        string $until,
        float $percentage,
        ?int $userId,
        ?int $cutoffVisitId = null,
    ): array {
        return DB::transaction(function () use ($doctorId, $from, $until, $percentage, $userId, $cutoffVisitId): array {
            $report = $this->calculator->calculate($doctorId, $from, $until, $percentage, $cutoffVisitId);
            $itemIds = collect($report['details'])->flatMap(fn (array $row): array => array_column($row['items'], 'id'))->all();
            if ($itemIds === []) {
                throw ValidationException::withMessages(['settlement' => 'არჩეულ პერიოდში დაუხურავი სამუშაო არ მოიძებნა.']);
            }

            VisitTreatmentCase::query()->whereKey($itemIds)->lockForUpdate()->get();
            $report = $this->calculator->calculate($doctorId, $from, $until, $percentage, $cutoffVisitId);

            return collect($report['totals'])->map(function (array $totals, string $currency) use ($report, $doctorId, $from, $until, $percentage, $userId): SalarySettlement {
                $settlement = SalarySettlement::query()->create(['doctor_id' => $doctorId, 'period_start' => $from,
                    'period_end' => $until, 'settled_at' => now(), 'currency' => $currency,
                    'performed_total' => $totals['work_total'], 'direct_expense_total' => $totals['expense_total'],
                    'base_total' => $totals['base_total'], 'percentage' => $percentage,
                    'salary_total' => $totals['doctor_share'], 'status' => 'confirmed', 'created_by' => $userId]);

                collect($report['details'])->where('currency', $currency)->each(function (array $row) use ($settlement): void {
                    foreach ($row['items'] as $item) {
                        $settlement->items()->create(['visit_id' => $row['visit_id'], 'visit_treatment_case_id' => $item['id'],
                            'revenue' => $item['revenue'], 'direct_expense' => $item['direct_expense'],
                            'salary_base' => $item['salary_base'], 'doctor_share' => $item['doctor_share']]);
                    }
                });

                return $settlement->load('items');
            })->values()->all();
        });
    }
}
