<?php

namespace App\Services;

use App\Models\FinanceTransaction;
use App\Support\CashboxManager;
use Illuminate\Support\Facades\DB;

class FinanceManager
{
    public function __construct(private readonly CashboxManager $cashboxManager) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): FinanceTransaction
    {
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
            $this->cashboxManager->removeFinanceTransaction($transaction);
            $transaction->delete();
        });
    }
}
