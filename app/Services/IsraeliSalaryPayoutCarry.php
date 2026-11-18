<?php

namespace App\Services;

use App\Models\IsraeliSalaryPayoutCarryEntry;
use App\Models\SalaryPayout;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/** GEL credits for the Israeli doctor allocation workflow; never posts cash or changes accrued salary. */
class IsraeliSalaryPayoutCarry
{
    public function balances(int $doctorId): array
    {
        return ['GEL' => round((float) DB::table('israeli_salary_payout_carry_entries')
            ->where('doctor_id', $doctorId)->sum('amount_gel'), 2)];
    }

    public function preview(int $doctorId, float $remaining, array $rows, float $deduction = 0): array
    {
        $opening = $this->balances($doctorId);
        $left = max(0, Money::minorUnits($remaining));
        $applied = min($left, max(0, Money::minorUnits($opening['GEL'])));
        $left -= $applied;
        $deducted = min($left, max(0, Money::minorUnits($deduction)));
        $left -= $deducted;
        $actual = $cashSettled = $cashAdvance = 0;
        $parts = [];
        foreach ($rows as $index => $row) {
            // Preserve the existing per-allocation USD/GEL conversion and cent rounding.
            $value = Money::minorUnits(IsraeliSalaryPayoutService::equivalent($row));
            $actual += $value;
            $used = min($left, $value);
            $left -= $used;
            $cashSettled += $used;
            $cashAdvance += $value - $used;
            $parts[] = ['row' => $index, 'salary_settled_gel' => $used / 100, 'advance_gel' => ($value - $used) / 100];
        }
        return ['version' => 1, 'credit_currency' => 'GEL', 'opening' => $opening,
            'applications' => ['GEL' => $applied / 100], 'credits' => ['GEL' => $cashAdvance / 100],
            'allocation_parts' => $parts, 'advance_applied_gel' => $applied / 100, 'advance_created_gel' => $cashAdvance / 100,
            'actual_paid_gel' => $actual / 100, 'cash_settled_gel' => $cashSettled / 100,
            'deducted_gel' => $deducted / 100, 'covered_gel' => ($applied + $cashSettled + $deducted) / 100,
            'remaining_gel' => $left / 100];
    }

    /** Caller holds the shared balance lock and the recipient's Doctor lock in the payout transaction. */
    public function record(SalaryPayout $payout, array $snapshot): void
    {
        foreach (['applications' => 'applied', 'credits' => 'advance'] as $key => $kind) {
            $amount = $snapshot[$key]['GEL'];
            if (Money::minorUnits($amount) > 0) {
                IsraeliSalaryPayoutCarryEntry::create(['doctor_id' => $payout->settlement->doctor_id,
                    'salary_payout_id' => $payout->id, 'kind' => $kind,
                    'amount_gel' => $kind === 'applied' ? -$amount : $amount]);
            }
        }
    }
}
