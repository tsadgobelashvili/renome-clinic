<?php

namespace App\Services;

use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Support\Money;

/** Audit allocation of already-calculated salary; never recalculates work or finance. */
class IsraeliPayoutComposition
{
    public function workRows(SalarySettlement $settlement): array
    {
        $settlement->loadMissing(['items.visit.patient', 'items.visitTreatmentCase.treatmentCase', 'items.labMainWork.labCase.patient']);

        return $settlement->items->sortBy('id')->reject(fn (SalarySettlementItem $item): bool => (bool) ($item->manual_case_snapshot['link_only'] ?? false))->map(function (SalarySettlementItem $item): array {
            if ($snapshot = $item->manual_case_snapshot) {
                return ['salary_settlement_item_id' => $item->id,
                    'patient_id' => $snapshot['patient_id'], 'patient' => $snapshot['patient'],
                    'visit_id' => null, 'visit_treatment_case_id' => null, 'lab_main_work_id' => $item->lab_main_work_id,
                    'manual_lab_case_id' => $item->manual_lab_case_id, 'works' => $snapshot['works'],
                    'work_date' => $snapshot['work_date'], 'service' => $snapshot['service'],
                    'quantity' => null, 'base' => $snapshot['amount_gel'], 'percentage' => null, 'unit_rate' => null,
                    'accrued' => Money::decimal($snapshot['amount_gel'])];
            }
            $visit = $item->visit;
            $work = $item->labMainWork;
            $patient = $visit?->patient ?? $work?->labCase?->patient;

            return [
                'salary_settlement_item_id' => $item->id,
                'patient_id' => $patient?->id, 'patient' => $patient?->full_name ?? '—',
                'visit_id' => $item->visit_id, 'visit_treatment_case_id' => $item->visit_treatment_case_id,
                'lab_main_work_id' => $item->lab_main_work_id,
                'work_date' => ($visit?->visit_date ?? $work?->labCase?->case_date)?->toDateString(),
                'service' => $item->visitTreatmentCase?->display_name ?? ($work ? strtoupper($work->material) : '—'),
                'quantity' => $item->quantity_snapshot,
                'base' => $item->base_snapshot ?? $item->salary_base,
                'percentage' => $item->salary_percentage_snapshot,
                'unit_rate' => $item->unit_rate_snapshot,
                'accrued' => Money::decimal($item->doctor_share_snapshot ?? $item->doctor_share),
            ];
        })->values()->all();
    }

    public function snapshot(SalarySettlement $settlement, float $total, float $deduction): ?array
    {
        $rows = $this->workRows($settlement);
        $payouts = $settlement->payouts()->orderBy('id')->get();
        // Old partial payments do not tell us which item remains unpaid. Never guess.
        if ($payouts->contains(fn ($payout) => $payout->composition_snapshot === null)
            || collect($rows)->contains(fn ($row) => $row['accrued'] < 0)
            || array_sum(array_map(fn ($row) => Money::minorUnits($row['accrued']), $rows)) !== Money::minorUnits($settlement->salary_total)) {
            return null;
        }
        $previous = [];
        $advanceByItem = [];
        // Attribute the already settled advance first, using the same immutable item FIFO.
        $advance = Money::minorUnits($settlement->salary_advance_applied);
        foreach ($rows as $row) {
            $used = min($advance, Money::minorUnits($row['accrued']));
            $previous[$row['salary_settlement_item_id']] = $used;
            $advanceByItem[$row['salary_settlement_item_id']] = $used;
            $advance -= $used;
        }
        foreach ($payouts as $payout) {
            foreach ($payout->composition_snapshot['items'] as $row) {
                $id = $row['salary_settlement_item_id'];
                $previous[$id] = ($previous[$id] ?? 0) + Money::minorUnits($row['paid']) + Money::minorUnits($row['deducted']);
            }
        }
        $cash = Money::minorUnits($total) - Money::minorUnits($deduction);
        $offset = Money::minorUnits($deduction);
        $items = [];
        // Persist FIFO by immutable settlement-item ID, disclosed before confirmation.
        foreach ($rows as $row) {
            $available = max(0, Money::minorUnits($row['accrued']) - ($previous[$row['salary_settlement_item_id']] ?? 0));
            $paid = min($cash, $available);
            $deducted = min($offset, $available - $paid);
            $advanced = $payouts->isEmpty() ? ($advanceByItem[$row['salary_settlement_item_id']] ?? 0) : 0;
            if ($paid + $deducted + $advanced === 0 && ! (isset($row['manual_lab_case_id']) && $row['accrued'] === 0.0)) {
                continue;
            }
            $cash -= $paid;
            $offset -= $deducted;
            $items[] = [...$row, 'paid' => $paid / 100, 'deducted' => $deducted / 100,
                ...($advanced > 0 ? ['advance_applied' => $advanced / 100] : []),
                'remaining' => ($available - $paid - $deducted) / 100];
        }
        if ($cash !== 0 || $offset !== 0) {
            return null;
        }
        $covered = Money::minorUnits($settlement->salary_advance_applied) + Money::minorUnits($payouts->sum('total_gel')) + Money::minorUnits($total);

        return ['version' => 1, 'allocation' => 'settlement_item_fifo', 'currency' => $settlement->currency,
            'payment_method' => $settlement->israeli_payment_method ?? 'cash',
            'accrued' => Money::decimal($settlement->salary_total), 'covered_after' => $covered / 100,
            'remaining_after' => max(0, Money::minorUnits($settlement->salary_total) - $covered) / 100,
            'paid' => Money::decimal($total - $deduction), 'deducted' => Money::decimal($deduction), 'items' => $items];
    }

    /** Only a single full payout proves that every saved item was paid. */
    public function fullSettlement(SalarySettlement $settlement, float $covered): ?array
    {
        $rows = $this->workRows($settlement);
        if ($rows === [] || Money::minorUnits($covered) !== Money::minorUnits($settlement->salary_total)
            || array_sum(array_map(fn ($row) => Money::minorUnits($row['accrued']), $rows)) !== Money::minorUnits($covered)
            || collect($rows)->contains(fn ($row) => $row['accrued'] < 0)) {
            return null;
        }

        return array_map(fn ($row) => [...$row, 'paid' => $row['accrued'], 'deducted' => 0, 'remaining' => 0], $rows);
    }
}
