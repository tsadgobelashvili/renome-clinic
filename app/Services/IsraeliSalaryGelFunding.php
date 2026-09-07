<?php

namespace App\Services;

use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\PatientGroup;
use App\Models\SalarySettlement;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

class IsraeliSalaryGelFunding
{
    public function __construct(
        private readonly FinanceUsdUsageService $balances,
        private readonly FinanceManager $clinic,
    ) {}

    public function record(SalarySettlement $original): ?PartnerFinanceTransaction
    {
        return DB::transaction(function () use ($original): ?PartnerFinanceTransaction {
            // A stable shared row serializes GEL salary allocations across doctors.
            PatientGroup::query()->whereKey(PatientGroup::israelPartnerId())->lockForUpdate()->firstOrFail();
            $settlement = SalarySettlement::query()->lockForUpdate()->find($original->getKey());
            if (! $settlement || $settlement->patient_group_slug !== PatientGroup::ISRAEL_PARTNER_SLUG || $settlement->payment_currency !== 'GEL') {
                return null;
            }
            if ($settlement->total_paid_gel !== null) {
                $original->setRawAttributes($settlement->getAttributes(), true);

                return $settlement->partnerFinanceTransaction;
            }

            $total = max(0, Money::minorUnits($settlement->payment_amount));
            $available = max(0, Money::minorUnits($this->balances->cashBalances('israeli')['GEL']));
            $israeli = min($total, $available);
            $clinic = $total - $israeli;
            $settlement->update([
                'total_paid_gel' => $total / 100,
                'israeli_gel_used' => $israeli / 100,
                'clinic_gel_used' => $clinic / 100,
            ]);
            $note = 'Salary settlement #'.$settlement->getKey().' — '.number_format($total / 100, 2, '.', '')
                .' GEL paid — '.number_format($israeli / 100, 2, '.', '').' Israeli / '.number_format($clinic / 100, 2, '.', '').' Clinic';
            $movement = $israeli > 0 ? PartnerFinanceTransaction::query()->create([
                'salary_settlement_id' => $settlement->getKey(),
                'source' => 'israeli', 'type' => 'expense', 'category' => 'doctor_salary',
                'transacted_at' => $settlement->settled_at, 'from_account' => 'cash',
                'amount' => $israeli / 100, 'currency' => 'GEL',
                'recipient' => $settlement->doctor->full_name, 'doctor_id' => $settlement->doctor_id,
                'created_by' => $settlement->created_by, 'notes' => $note,
            ]) : null;

            if ($clinic > 0) {
                $this->clinic->create([
                    'salary_settlement_id' => $settlement->getKey(),
                    'type' => 'expense', 'category' => 'salary', 'transaction_date' => $settlement->settled_at,
                    'amount' => $clinic / 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier',
                    'description' => 'Israeli doctor salary — '.$settlement->doctor->full_name,
                    'created_by' => $settlement->created_by, 'note' => $note,
                ]);
            }
            $original->setRawAttributes($settlement->getAttributes(), true);

            return $movement;
        });
    }

    public function reverse(iterable $settlementIds): void
    {
        foreach (FinanceTransaction::query()->whereIn('salary_settlement_id', $settlementIds)->lockForUpdate()->get() as $expense) {
            if ($expense->reversal()->exists()) {
                continue;
            }
            // Refund through today's existing cashbox; do not rewrite a closed day's ledger.
            $this->clinic->create([
                'reversal_of_finance_transaction_id' => $expense->getKey(),
                'type' => 'income', 'category' => 'salary', 'transaction_date' => now(),
                'amount' => $expense->amount, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier',
                'description' => 'Salary reversal — '.$expense->description,
                'created_by' => auth()->id(), 'note' => $expense->note,
            ]);
        }
    }
}
