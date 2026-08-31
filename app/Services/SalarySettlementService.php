<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\OwnerSalaryShare;
use App\Models\PatientGroup;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
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
        string $patientGroup = DoctorCompensationCalculator::GROUP_ALL,
    ): array {
        if ($percentage <= 0 || $percentage > 100) {
            throw ValidationException::withMessages([
                'percentage' => 'ხელფასის დასაფიქსირებლად ექიმის პროცენტი უნდა იყოს 0-ზე მეტი და მაქსიმუმ 100.',
            ]);
        }

        return DB::transaction(function () use ($doctorId, $from, $until, $percentage, $userId, $cutoffVisitId, $patientGroup): array {
            $report = $this->calculator->calculate($doctorId, $from, $until, $percentage, $cutoffVisitId, $patientGroup);
            $itemIds = collect($report['details'])->flatMap(fn (array $row): array => array_column($row['items'], 'id'))->all();
            $incomingShareIds = collect($report['owner_split_income'])->pluck('id')->all();
            if ($itemIds === [] && $incomingShareIds === []) {
                throw ValidationException::withMessages(['settlement' => 'არჩეულ პერიოდში დაუხურავი სამუშაო არ მოიძებნა.']);
            }

            VisitTreatmentCase::query()->whereKey($itemIds)->lockForUpdate()->get();
            OwnerSalaryShare::query()->whereKey($incomingShareIds)->lockForUpdate()->get();
            $report = $this->calculator->calculate($doctorId, $from, $until, $percentage, $cutoffVisitId, $patientGroup);
            $rowsByKey = collect($report['details'])->groupBy(fn (array $row): string => $row['patient_group_slug'].'|'.$row['currency']);
            $sharesByKey = collect($report['owner_split_income'])->groupBy(fn (array $share): string => $share['patient_group_slug'].'|'.$share['currency']);

            return $rowsByKey->keys()->merge($sharesByKey->keys())->unique()->map(
                function (string $key) use ($rowsByKey, $sharesByKey, $doctorId, $from, $until, $percentage, $userId): SalarySettlement {
                    $rows = $rowsByKey->get($key, collect());
                    $incomingShares = $sharesByKey->get($key, collect());
                    [$groupSlug, $currency] = explode('|', $key, 2);
                    $normalSalary = round((float) $rows->sum('doctor_share'), 2);
                    $incomingSalary = round((float) $incomingShares->sum('amount'), 2);

                    $settlement = SalarySettlement::query()->create([
                        'doctor_id' => $doctorId,
                        'period_start' => $from,
                        'period_end' => $until,
                        'settled_at' => now(),
                        'currency' => $currency,
                        'patient_group_slug' => $groupSlug,
                        'performed_total' => round((float) $rows->sum(
                            $groupSlug === PatientGroup::ISRAEL_PARTNER_SLUG ? 'work_total' : 'total_value'
                        ), 2),
                        'paid_amount' => round((float) $rows->sum('paid_total'), 2),
                        'outstanding_amount' => round((float) $rows->sum('outstanding_total'), 2),
                        'direct_expense_total' => round((float) $rows->sum('expense_total'), 2),
                        'base_total' => round((float) $rows->sum('base_total'), 2),
                        'percentage' => $percentage,
                        'normal_salary_total' => $normalSalary,
                        'owner_split_received_total' => $incomingSalary,
                        'salary_total' => round($normalSalary + $incomingSalary, 2),
                        'status' => 'confirmed',
                        'created_by' => $userId,
                    ]);

                    $rows->each(function (array $row) use ($settlement, $groupSlug): void {
                        foreach ($row['items'] as $item) {
                            $settlement->items()->create([
                                'visit_id' => $row['visit_id'],
                                'visit_treatment_case_id' => $item['id'],
                                'revenue' => $item['revenue'],
                                'direct_expense' => $item['direct_expense'],
                                'salary_base' => $item['salary_base'],
                                'doctor_share' => $item['doctor_share'],
                                'total_value_snapshot' => $item['revenue'],
                                'paid_amount_snapshot' => $item['paid_amount'],
                                'outstanding_amount_snapshot' => $item['outstanding_amount'],
                                'expense_snapshot' => $item['direct_expense'],
                                'base_snapshot' => $item['salary_base'],
                                'doctor_share_snapshot' => $item['doctor_share'],
                                'patient_group_slug' => $groupSlug,
                            ]);
                        }
                    });

                    $ownerDoctor = Doctor::query()->findOrFail($doctorId);
                    $otherOwnerId = $ownerDoctor->isOwnerSplitDoctor()
                        ? Doctor::query()->whereKeyNot($doctorId)->whereNotNull('owner_split_key')->value('id')
                        : null;
                    foreach ($rows->where('owner_split', true) as $row) {
                        if (! $otherOwnerId) {
                            throw ValidationException::withMessages(['settlement' => 'Owner Split-ის მეორე ექიმი კონფიგურირებული არ არის.']);
                        }
                        OwnerSalaryShare::query()->create([
                            'source_salary_settlement_id' => $settlement->getKey(),
                            'visit_id' => $row['visit_id'],
                            'source_doctor_id' => $doctorId,
                            'recipient_doctor_id' => $otherOwnerId,
                            'patient_group_slug' => $groupSlug,
                            'currency' => $currency,
                            'amount' => $row['doctor_share'],
                            'status' => 'pending',
                        ]);
                    }

                    OwnerSalaryShare::query()->whereKey($incomingShares->pluck('id'))->where('status', 'pending')->update([
                        'recipient_salary_settlement_id' => $settlement->getKey(),
                        'status' => 'settled',
                        'settled_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return $settlement->load('items');
                }
            )->values()->all();
        });
    }

    public function undo(int $settlementId, ?int $doctorId = null): bool
    {
        return DB::transaction(function () use ($settlementId, $doctorId): bool {
            $settlement = SalarySettlement::query()
                ->when($doctorId !== null, fn ($query) => $query->where('doctor_id', $doctorId))
                ->lockForUpdate()->find($settlementId);
            if (! $settlement) {
                return false;
            }

            if (OwnerSalaryShare::query()->where('source_salary_settlement_id', $settlementId)->where('status', 'settled')->exists()) {
                throw ValidationException::withMessages([
                    'settlement' => 'ხელფასი ვერ გაუქმდება: Owner Split-ის წილი უკვე დაფიქსირდა მეორე ექიმის ხელფასში.',
                ]);
            }

            OwnerSalaryShare::query()->where('recipient_salary_settlement_id', $settlementId)->where('status', 'settled')->update([
                'recipient_salary_settlement_id' => null,
                'status' => 'pending',
                'settled_at' => null,
                'updated_at' => now(),
            ]);
            OwnerSalaryShare::query()->where('source_salary_settlement_id', $settlementId)->where('status', 'pending')->delete();

            $itemIds = SalarySettlementItem::query()->where('salary_settlement_id', $settlementId)->lockForUpdate()->pluck('id');
            SalarySettlementItem::query()->whereKey($itemIds)->delete();
            $settlement->delete();

            if (SalarySettlementItem::query()->where('salary_settlement_id', $settlementId)->exists()) {
                throw new \RuntimeException('Salary settlement link cleanup failed.');
            }

            return true;
        });
    }
}
