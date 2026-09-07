<?php

namespace App\Services;

use App\Models\FinanceTransaction;
use App\Models\LabSalarySettlement;
use App\Models\PartnerFinanceTransaction;
use App\Models\PatientGroup;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LabSalaryFunding
{
    public function pay(LabSalarySettlement $settlement): void
    {
        DB::transaction(function () use ($settlement): void {
            $settlement = LabSalarySettlement::query()->with('technician')->lockForUpdate()->findOrFail($settlement->id);
            if ($settlement->status !== 'confirmed') {
                throw ValidationException::withMessages(['payment' => 'This salary settlement cannot be paid.']);
            }
            if ($settlement->actual_paid_gel !== null) {
                return;
            }

            $paid = Money::minorUnits($settlement->salary_total);
            PatientGroup::query()->whereKey(PatientGroup::israelPartnerId())->lockForUpdate()->firstOrFail();
            $available = Money::minorUnits(app(FinanceUsdUsageService::class)->cashBalances('israeli')['GEL']);
            if ($paid > $available) {
                throw ValidationException::withMessages(['payment' => 'Israeli GEL cash balance is insufficient.']);
            }

            $settlement->update(['actual_paid_gel' => $paid / 100, 'israeli_cash_gel' => $paid / 100]);
            $expense = app(FinanceManager::class)->create([
                'lab_salary_settlement_id' => $settlement->id,
                'type' => 'expense',
                'transaction_date' => $settlement->settled_at,
                'category' => 'lab_salary',
                'description' => 'Laboratory salary',
                'amount' => $paid / 100,
                'currency' => 'GEL',
                'payment_method' => 'cash',
                'cash_source' => 'current_cashier',
                'clinic_cash_gel' => 0,
                'israeli_cash_gel' => $paid / 100,
                'note' => $settlement->technician->name,
            ]);
            $this->israeliMovement($expense, $paid, false);
        });
        $settlement->refresh();
    }

    public function reverse(LabSalarySettlement $settlement): void
    {
        $expense = FinanceTransaction::query()->where('lab_salary_settlement_id', $settlement->id)->lockForUpdate()->first();
        if (! $expense || $expense->reversal()->exists()) {
            return;
        }

        $refund = app(FinanceManager::class)->create([
            'reversal_of_finance_transaction_id' => $expense->id,
            'type' => 'income',
            'transaction_date' => now(),
            'category' => 'lab_salary',
            'description' => 'Reversal: '.$expense->description,
            'amount' => $expense->amount,
            'currency' => 'GEL',
            'payment_method' => 'cash',
            'cash_source' => 'current_cashier',
            'clinic_cash_gel' => 0,
            'israeli_cash_gel' => $expense->israeli_cash_gel,
            'note' => $expense->note,
        ]);
        $this->israeliMovement($refund, Money::minorUnits($expense->israeli_cash_gel), true);
    }

    private function israeliMovement(FinanceTransaction $finance, int $amount, bool $refund): void
    {
        PartnerFinanceTransaction::create([
            'finance_transaction_id' => $finance->id,
            'type' => PartnerFinanceTransaction::TYPE_SALARY_CASH,
            'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
            'transacted_at' => $finance->transaction_date,
            'category' => 'lab_salary',
            'from_account' => $refund ? null : 'cash',
            'to_account' => $refund ? 'cash' : null,
            'amount' => $amount / 100,
            'currency' => 'GEL',
            'notes' => $finance->description,
            'recipient' => $finance->labSalarySettlement?->technician?->name ?? $finance->note,
        ]);
    }
}
