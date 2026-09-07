<?php

namespace App\Services;

use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Support\CashboxManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FinanceManager
{
    public function __construct(private readonly CashboxManager $cashboxManager) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): FinanceTransaction|PartnerFinanceTransaction
    {
        if (($attributes['type'] ?? null) === 'expense'
            && ($attributes['cash_source'] ?? null) === PartnerFinanceTransaction::SOURCE_ISRAELI) {
            return app(FinanceUsdUsageService::class)->recordIsraeliCashExpense($attributes);
        }

        return DB::transaction(function () use ($attributes): FinanceTransaction {
            $transaction = FinanceTransaction::create($attributes);
            $this->cashboxManager->syncFinanceTransaction($transaction);

            return $transaction->load('cashboxTransaction');
        });
    }

    /** @param array<string, mixed> $attributes */
    public function update(FinanceTransaction $transaction, array $attributes): FinanceTransaction
    {
        return DB::transaction(function () use ($transaction, $attributes): FinanceTransaction {
            $transaction->update($attributes);
            $this->cashboxManager->syncFinanceTransaction($transaction);

            return $transaction->refresh()->load('cashboxTransaction');
        });
    }

    public function delete(FinanceTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction): void {
            if ($transaction->clinic_cash_gel !== null) {
                throw ValidationException::withMessages(['amount' => __('employees.salary.reverse_only')]);
            }
            $this->cashboxManager->removeFinanceTransaction($transaction);
            $transaction->delete();
        });
    }
}
