<?php

namespace App\Services;

use App\Models\SalarySettlement;
use Illuminate\Support\Collection;

class DoctorSalaryHistory
{
    /** @return Collection<int, SalarySettlement> */
    public function forDoctor(int $doctorId): Collection
    {
        return SalarySettlement::query()
            ->where('doctor_id', $doctorId)
            ->with([
                'items.visit.patient',
                'items.visitTreatmentCase.treatmentCase',
                'items.labMainWork.labCase.patient',
                'incomingOwnerShares.sourceDoctor',
                'incomingOwnerShares.visit.patient',
                'incomingOwnerShares.sourceSettlement.items.visitTreatmentCase.treatmentCase',
            ])
            ->latest('settled_at')
            ->latest('id')
            ->get()
            ->groupBy(fn (SalarySettlement $settlement): string => implode('|', [
                $settlement->doctor_id,
                $settlement->period_start->toDateString(),
                $settlement->period_end->toDateString(),
                $settlement->patient_group_slug,
                $settlement->currency,
                $settlement->payment_currency,
                $settlement->payment_exchange_rate,
                $settlement->actual_paid_usd !== null || $settlement->total_paid_gel !== null ? $settlement->getKey() : '',
            ]))
            ->map(function (Collection $records): SalarySettlement {
                /** @var SalarySettlement $display */
                $display = clone $records->first();
                foreach ([
                    'performed_total', 'paid_amount', 'outstanding_amount', 'direct_expense_total', 'base_total',
                    'normal_salary_total', 'owner_split_received_total', 'salary_total',
                ] as $field) {
                    $display->setAttribute($field, round((float) $records->sum($field), 2));
                }
                $display->setAttribute('settled_at', $records->max('settled_at'));
                $display->setRelation('items', $records->flatMap->items->values());
                $display->setRelation('historyRecords', $records->values());
                $display->setRelation('incomingOwnerShares', $records->flatMap->incomingOwnerShares->values());

                return $display;
            })
            ->sortByDesc(fn (SalarySettlement $settlement) => $settlement->settled_at)
            ->values();
    }
}
