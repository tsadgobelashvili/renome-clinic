<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\LabMainWork;
use App\Models\OwnerSalaryShare;
use App\Models\PatientGroup;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\VisitTreatmentCase;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalarySettlementService
{
    public function __construct(
        private readonly DoctorCompensationCalculator $calculator,
        private readonly FinanceUsdUsageService $finance,
        private readonly IsraeliSalaryCarryService $carry,
    ) {}

    /** @return array<int, SalarySettlement> */
    public function settle(
        int $doctorId,
        string $from,
        string $until,
        float $percentage,
        ?int $userId,
        ?int $cutoffVisitId = null,
        string $patientGroup = DoctorCompensationCalculator::GROUP_ALL,
        ?string $paymentCurrency = null,
        ?float $exchangeRate = null,
        ?float $actualPaidUsd = null,
        ?array $selectedLabWorkIds = null,
        bool $israeliLabOnly = false,
        array $approvedFullDiscountItemIds = [],
    ): array {
        if ($percentage <= 0 || $percentage > 100) {
            throw ValidationException::withMessages([
                'percentage' => 'ხელფასის დასაფიქსირებლად ექიმის პროცენტი უნდა იყოს 0-ზე მეტი და მაქსიმუმ 100.',
            ]);
        }

        return DB::transaction(function () use ($doctorId, $from, $until, $percentage, $userId, $cutoffVisitId, $patientGroup, $paymentCurrency, $exchangeRate, $actualPaidUsd, $selectedLabWorkIds, $israeliLabOnly, $approvedFullDiscountItemIds): array {
            // Serialize payouts and undo, including generated Owner Split counterparts.
            Doctor::query()->where(fn ($query) => $query->whereKey($doctorId)->orWhereNotNull('owner_split_key'))
                ->orderBy('id')->lockForUpdate()->get();
            $report = $this->calculator->calculate($doctorId, $from, $until, $percentage, $cutoffVisitId, $patientGroup, $selectedLabWorkIds, $israeliLabOnly, $approvedFullDiscountItemIds);
            $items = collect($report['details'])->flatMap(fn (array $row): array => $row['items']);
            $visitItemIds = $items->where('source_type', 'visit')->pluck('id')->all();
            $labItemIds = $items->where('source_type', 'lab')->pluck('id')->all();
            $incomingShareIds = collect($report['owner_split_income'])->pluck('id')->all();
            if ($visitItemIds === [] && $labItemIds === [] && $incomingShareIds === []) {
                throw ValidationException::withMessages(['settlement' => 'არჩეულ პერიოდში დაუხურავი სამუშაო არ მოიძებნა.']);
            }

            VisitTreatmentCase::query()->whereKey($visitItemIds)->lockForUpdate()->get();
            LabMainWork::query()->whereKey($labItemIds)->lockForUpdate()->get();
            OwnerSalaryShare::query()->whereKey($incomingShareIds)->lockForUpdate()->get();
            $report = $this->calculator->calculate($doctorId, $from, $until, $percentage, $cutoffVisitId, $patientGroup, $selectedLabWorkIds, $israeliLabOnly, $approvedFullDiscountItemIds);
            $rowsByKey = collect($report['details'])->groupBy(fn (array $row): string => $row['patient_group_slug'].'|'.$row['currency']);
            $sharesByKey = collect($report['owner_split_income'])->groupBy(fn (array $share): string => $share['patient_group_slug'].'|'.$share['currency']);

            if ($actualPaidUsd !== null && $rowsByKey->keys()->merge($sharesByKey->keys())->unique()
                ->filter(fn (string $key): bool => str_starts_with($key, PatientGroup::ISRAEL_PARTNER_SLUG.'|'))->count() > 1) {
                throw ValidationException::withMessages(['actual_paid_usd' => 'Finalize each Israeli salary basis currency separately when entering an actual USD payment.']);
            }

            return $rowsByKey->keys()->merge($sharesByKey->keys())->unique()->map(
                function (string $key) use ($rowsByKey, $sharesByKey, $doctorId, $from, $until, $percentage, $userId, $paymentCurrency, $exchangeRate, $actualPaidUsd): SalarySettlement {
                    $rows = $rowsByKey->get($key, collect());
                    $incomingShares = $sharesByKey->get($key, collect());
                    [$groupSlug, $currency] = explode('|', $key, 2);
                    $normalSalary = round((float) $rows->sum('doctor_share'), 2);
                    $incomingSalary = round((float) $incomingShares->sum('amount'), 2);
                    $salaryTotal = round($normalSalary + $incomingSalary, 2);
                    $payment = $this->paymentSnapshot($groupSlug, $currency, $salaryTotal, $paymentCurrency, $exchangeRate);
                    $payment = $this->withCarrySnapshot($doctorId, $currency, $salaryTotal, $payment, $actualPaidUsd);

                    $settlement = SalarySettlement::query()->create([
                        'doctor_id' => $doctorId,
                        'period_start' => $from,
                        'period_end' => $until,
                        'settled_at' => now(),
                        'currency' => $currency,
                        ...$payment,
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
                        'salary_total' => $salaryTotal,
                        'status' => 'confirmed',
                        'created_by' => $userId,
                    ]);

                    $rows->each(function (array $row) use ($settlement, $groupSlug): void {
                        foreach ($row['items'] as $item) {
                            $settlement->items()->create([
                                'visit_id' => $row['visit_id'],
                                'visit_treatment_case_id' => $item['source_type'] === 'visit' ? $item['id'] : null,
                                'lab_main_work_id' => $item['source_type'] === 'lab' ? $item['id'] : null,
                                'quantity_snapshot' => $item['quantity'],
                                'unit_rate_snapshot' => $item['unit_rate'] ?? null,
                                'revenue' => $item['revenue'],
                                'direct_expense' => $item['direct_expense'],
                                'salary_base' => $item['salary_base'],
                                'salary_percentage_snapshot' => $item['applied_percentage'],
                                'doctor_share' => $item['doctor_share'],
                                'total_value_snapshot' => $item['revenue'],
                                'paid_amount_snapshot' => $item['paid_amount'],
                                'outstanding_amount_snapshot' => $item['outstanding_amount'],
                                'expense_snapshot' => $item['direct_expense'],
                                'base_snapshot' => $item['salary_base'],
                                'doctor_share_snapshot' => $item['doctor_share'],
                                'is_full_discount_snapshot' => $item['is_full_discount'] ?? null,
                                'potential_doctor_share_snapshot' => ($item['is_full_discount'] ?? false)
                                    ? $item['potential_doctor_share'] : null,
                                'salary_approved' => $item['salary_approved'] ?? null,
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
                        OwnerSalaryShare::query()->firstOrCreate([
                            'source_salary_settlement_id' => $settlement->getKey(),
                            'visit_id' => $row['visit_id'],
                            'recipient_doctor_id' => $otherOwnerId,
                        ], [
                            'source_doctor_id' => $doctorId,
                            'patient_group_slug' => $groupSlug,
                            'currency' => $currency,
                            'amount' => $row['doctor_share'],
                            'status' => 'pending',
                        ]);
                    }

                    $this->finalizeCounterpartOwnerSplit($settlement, $userId);

                    OwnerSalaryShare::query()->whereKey($incomingShares->pluck('id'))->where('status', 'pending')->update([
                        'recipient_salary_settlement_id' => $settlement->getKey(),
                        'status' => 'settled',
                        'settled_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $this->carry->record($settlement);
                    $this->finance->recordIsraeliDoctorSalary($settlement);

                    return $settlement->load('items');
                }
            )->values()->all();
        });
    }

    public function undo(int $settlementId, ?int $doctorId = null): bool
    {
        return DB::transaction(function () use ($settlementId, $doctorId): bool {
            $targetDoctorId = SalarySettlement::query()->whereKey($settlementId)->value('doctor_id');
            Doctor::query()->where(fn ($query) => $query->whereKey($targetDoctorId)->orWhereNotNull('owner_split_key'))
                ->orderBy('id')->lockForUpdate()->get();
            $settlement = SalarySettlement::query()
                ->when($doctorId !== null, fn ($query) => $query->where('doctor_id', $doctorId))
                ->lockForUpdate()->find($settlementId);
            if (! $settlement) {
                return false;
            }

            $outgoingShares = OwnerSalaryShare::query()
                ->where('source_salary_settlement_id', $settlementId)
                ->lockForUpdate()
                ->get();
            $generatedSettlementIds = $outgoingShares->pluck('recipient_salary_settlement_id')->filter()->unique();
            $this->carry->reverse($generatedSettlementIds->concat([$settlementId])->unique()->values());
            $this->finance->reverseIsraeliDoctorSalaries(
                $generatedSettlementIds->concat([$settlementId])->unique()->values(),
            );
            OwnerSalaryShare::query()->whereKey($outgoingShares->modelKeys())->delete();
            SalarySettlement::query()
                ->whereKey($generatedSettlementIds)
                ->where('normal_salary_total', 0)
                ->where('owner_split_received_total', '>', 0)
                ->delete();

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

    private function finalizeCounterpartOwnerSplit(SalarySettlement $source, ?int $userId): void
    {
        $shares = OwnerSalaryShare::query()
            ->where('source_salary_settlement_id', $source->getKey())
            ->whereNull('recipient_salary_settlement_id')
            ->lockForUpdate()
            ->get();

        if ($shares->isEmpty()) {
            return;
        }

        $amount = round((float) $shares->sum('amount'), 2);
        $basis = round((float) $source->items()
            ->whereIn('visit_id', $shares->pluck('visit_id'))
            ->sum('base_snapshot'), 2);
        $payment = $this->paymentSnapshot(
            $source->patient_group_slug,
            $source->currency,
            $amount,
            $source->payment_currency,
            filled($source->payment_exchange_rate) ? (float) $source->payment_exchange_rate : null,
        );
        $payment = $this->withCarrySnapshot((int) $shares->first()->recipient_doctor_id, $source->currency, $amount, $payment);
        $counterpart = SalarySettlement::query()->create([
            'doctor_id' => $shares->first()->recipient_doctor_id,
            'period_start' => $source->period_start,
            'period_end' => $source->period_end,
            'settled_at' => $source->settled_at,
            'currency' => $source->currency,
            ...$payment,
            'patient_group_slug' => $source->patient_group_slug,
            'performed_total' => $basis,
            'paid_amount' => $basis,
            'outstanding_amount' => 0,
            'direct_expense_total' => 0,
            'base_total' => $basis,
            'percentage' => 50,
            'normal_salary_total' => 0,
            'owner_split_received_total' => $amount,
            'salary_total' => $amount,
            'status' => 'confirmed',
            'created_by' => $userId,
        ]);

        OwnerSalaryShare::query()->whereKey($shares->modelKeys())->update([
            'recipient_salary_settlement_id' => $counterpart->getKey(),
            'status' => 'settled',
            'settled_at' => now(),
            'updated_at' => now(),
        ]);

        $this->carry->record($counterpart);
        $this->finance->recordIsraeliDoctorSalary($counterpart);
    }

    private function withCarrySnapshot(int $doctorId, string $basisCurrency, float $salaryTotal, array $payment, ?float $actualPaidUsd = null): array
    {
        if ($payment['payment_currency'] !== 'USD') {
            return $payment;
        }

        $snapshot = $this->carry->preview($doctorId, (float) $payment['payment_amount'], $actualPaidUsd);

        return [...$payment, ...$snapshot,
            'gel_salary_basis' => $basisCurrency === 'GEL' ? $salaryTotal : null,
            'payment_amount' => $snapshot['actual_paid_usd'],
        ];
    }

    /** @return array{payment_currency: ?string, payment_exchange_rate: ?float, payment_amount: ?float} */
    private function paymentSnapshot(
        string $groupSlug,
        string $basisCurrency,
        float $salaryTotal,
        ?string $paymentCurrency,
        ?float $exchangeRate,
    ): array {
        if ($groupSlug !== PatientGroup::ISRAEL_PARTNER_SLUG) {
            return ['payment_currency' => null, 'payment_exchange_rate' => null, 'payment_amount' => null];
        }

        $paymentCurrency = strtoupper($paymentCurrency ?: $basisCurrency);
        if (! in_array($paymentCurrency, ['GEL', 'USD'], true)) {
            throw ValidationException::withMessages(['payment_currency' => 'Payment currency must be GEL or USD.']);
        }

        $requiresConversion = $paymentCurrency !== $basisCurrency;
        if ($requiresConversion && (! is_numeric($exchangeRate) || $exchangeRate <= 0)) {
            throw ValidationException::withMessages(['exchange_rate' => 'A positive GEL/USD exchange rate is required.']);
        }

        $paymentAmount = match ([$basisCurrency, $paymentCurrency]) {
            ['GEL', 'USD'] => round($salaryTotal / $exchangeRate, 2),
            ['USD', 'GEL'] => round($salaryTotal * $exchangeRate, 2),
            default => $salaryTotal,
        };

        if (! Currency::isSupported($basisCurrency)) {
            throw ValidationException::withMessages(['currency' => 'The salary basis currency is invalid.']);
        }

        return [
            'payment_currency' => $paymentCurrency,
            'payment_exchange_rate' => $requiresConversion ? $exchangeRate : null,
            'payment_amount' => $paymentAmount,
        ];
    }
}
