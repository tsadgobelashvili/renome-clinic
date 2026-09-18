<?php

namespace App\Services;

use App\Models\FinanceTransaction;
use App\Models\Purchase;
use App\Models\User;
use App\Support\CashboxManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A confirmed RS payment uses the existing Finance posting and Cashier mirror. */
class PurchaseCashPayment
{
    public function post(int $purchaseId, User $actor, ?string $expectedAmount = null): FinanceTransaction
    {
        abort_unless($actor->isOwner(), 403);

        return DB::transaction(function () use ($purchaseId, $actor, $expectedAmount): FinanceTransaction {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchaseId);
            if ($posted = $purchase->cashExpense()->first()) {
                return $posted;
            }
            if ($purchase->source !== 'rs' || $purchase->bankTransactions()->exists()) {
                throw ValidationException::withMessages(['cash_paid' => 'ჯერ მოხსენით RS დოკუმენტის ბანკთან მიბმა.']);
            }
            $totals = $purchase->items()->selectRaw('SUM(line_total) AS total, MIN(line_total) AS minimum')->first();
            $amount = Money::minorUnits($totals->total);
            if ($amount <= 0 || $totals->minimum < 0 || $amount !== Money::minorUnits($purchase->total_amount)
                || ($expectedAmount !== null && $amount !== Money::minorUnits($expectedAmount))) {
                throw ValidationException::withMessages(['cash_paid' => 'დოკუმენტის თანხა შეიცვალა ან არასწორია. განაახლეთ გვერდი და ხელახლა დაადასტურეთ.']);
            }
            $this->lockCashDay();
            $balances = app(FinanceUsdUsageService::class)->cashBalances('clinic');
            if ($amount > Money::minorUnits($balances['GEL'] ?? 0)) {
                throw ValidationException::withMessages(['cash_paid' => 'კლინიკის სალაროში არასაკმარისი GEL თანხაა.']);
            }

            return app(FinanceManager::class)->create([
                'purchase_id' => $purchase->id, 'type' => 'expense', 'transaction_date' => now(),
                'category' => 'supplier', 'amount' => $purchase->total_amount, 'currency' => 'GEL',
                'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'funding_source' => 'clinic',
                'description' => 'RS — '.($purchase->document_number ?: $purchase->id).' — '.$purchase->supplier->name,
                'created_by' => $actor->id,
            ]);
        });
    }

    public function reverse(int $purchaseId, int $postingId, User $actor): void
    {
        abort_unless($actor->isOwner(), 403);
        DB::transaction(function () use ($purchaseId, $postingId, $actor): void {
            Purchase::lockForUpdate()->findOrFail($purchaseId);
            $original = FinanceTransaction::where('purchase_id', $purchaseId)->where('type', 'expense')->lockForUpdate()->findOrFail($postingId);
            if ($original->reversal()->exists()) {
                return;
            }
            $this->lockCashDay();
            app(FinanceManager::class)->create([
                'purchase_id' => $purchaseId, 'reversal_of_finance_transaction_id' => $original->id,
                'type' => 'income', 'transaction_date' => now(), 'category' => 'supplier',
                'amount' => $original->amount, 'currency' => $original->currency,
                'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'funding_source' => 'clinic',
                'description' => 'გაუქმება: '.$original->description, 'created_by' => $actor->id,
            ]);
        });
    }

    private function lockCashDay(): void
    {
        $day = app(CashboxManager::class)->today();
        $day = $day->newQuery()->whereKey($day->id)->lockForUpdate()->firstOrFail();
        if ($day->status === 'closed') {
            throw ValidationException::withMessages(['cash_paid' => 'დღევანდელი სალარო დახურულია.']);
        }
    }
}
