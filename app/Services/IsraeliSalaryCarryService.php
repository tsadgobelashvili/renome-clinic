<?php

namespace App\Services;

use App\Models\SalarySettlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IsraeliSalaryCarryService
{
    public function balance(int $doctorId): float
    {
        return round((float) DB::table('israeli_salary_carry_entries')->where('doctor_id', $doctorId)->sum('amount_usd'), 2);
    }

    public function preview(int $doctorId, float $convertedSalary, ?float $actualPaid = null): array
    {
        if ($actualPaid !== null && (! is_finite($actualPaid) || $actualPaid < 0 || $actualPaid > 999999999999.99)) {
            throw ValidationException::withMessages(['actual_paid_usd' => 'Actual paid USD must be a non-negative amount.']);
        }

        $opening = $this->balance($doctorId);
        $convertedSalary = round($convertedSalary, 2);
        $calculated = max(0, round($convertedSalary - $opening, 2));
        $actual = round($actualPaid ?? $calculated, 2);

        return [
            'converted_salary_usd' => $convertedSalary,
            'calculated_usd' => $calculated,
            'actual_paid_usd' => $actual,
            'difference_usd' => round($actual - $calculated, 2),
            'opening_carry_usd' => $opening,
            'closing_carry_usd' => round($opening + $actual - $convertedSalary, 2),
        ];
    }

    // Call while holding the doctor's lock, inside the settlement transaction.
    public function record(SalarySettlement $settlement): void
    {
        if ($settlement->actual_paid_usd === null) {
            return;
        }

        DB::table('israeli_salary_carry_entries')->insert([
            'doctor_id' => $settlement->doctor_id,
            'salary_settlement_id' => $settlement->getKey(),
            'kind' => 'settlement',
            'amount_usd' => round((float) $settlement->closing_carry_usd - (float) $settlement->opening_carry_usd, 2),
            'snapshot' => $settlement->toJson(),
            'created_at' => now(),
        ]);
    }

    public function reverse(iterable $settlementIds): void
    {
        foreach (DB::table('israeli_salary_carry_entries')->whereIn('salary_settlement_id', $settlementIds)->where('kind', 'settlement')->get() as $entry) {
            if (DB::table('israeli_salary_carry_entries')->where('salary_settlement_id', $entry->salary_settlement_id)->where('kind', 'reversal')->exists()) {
                continue;
            }

            // Reverse the original change, not its opening balance: later payouts stay intact.
            DB::table('israeli_salary_carry_entries')->insert([
                'doctor_id' => $entry->doctor_id,
                'salary_settlement_id' => $entry->salary_settlement_id,
                'kind' => 'reversal',
                'amount_usd' => -(float) $entry->amount_usd,
                'snapshot' => $entry->snapshot,
                'created_at' => now(),
            ]);
        }
    }
}
