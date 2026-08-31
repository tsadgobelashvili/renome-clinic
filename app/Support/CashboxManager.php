<?php

namespace App\Support;

use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Models\CashTransfer;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\ProductSale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashboxManager
{
    public function dayFor(string $date): CashboxDay
    {
        if ($day = CashboxDay::whereDate('date', $date)->first()) {
            return $day;
        }

        $previous = CashboxDay::whereDate('date', '<', $date)->latest('date')->first();

        try {
            return CashboxDay::create([
                'date' => $date,
                'opening_balance' => $previous?->status === 'closed' ? $previous->carry_forward_balance : 0,
                'opening_balance_usd' => $previous?->status === 'closed' ? $previous->carry_forward_balance_usd : 0,
                'opened_at' => now(),
                'status' => 'open',
            ]);
        } catch (QueryException $exception) {
            return CashboxDay::whereDate('date', $date)->firstOrFail();
        }
    }

    public function today(): CashboxDay
    {
        return $this->dayFor(today()->toDateString());
    }

    public function unresolvedPreviousDay(): ?CashboxDay
    {
        return CashboxDay::whereDate('date', '<', today())->where('status', 'open')->oldest('date')->first();
    }

    public function syncPayment(Payment $payment): void
    {
        if ($payment->trashed()) {
            CashboxTransaction::where('payment_id', $payment->getKey())->delete();

            return;
        }

        $payment->loadMissing('visit', 'splits');

        if ($payment->splits->isEmpty()) {
            CashboxTransaction::where('payment_id', $payment->getKey())->delete();

            return;
        }

        $day = $this->dayFor($payment->payment_date->toDateString());

        if ($day->status === 'closed') {
            throw ValidationException::withMessages([
                'payment_date' => 'ამ თარიღის სალარო უკვე დახურულია. აირჩიეთ ღია სალაროს დღე.',
            ]);
        }

        $transactionTime = ($payment->created_at ?? now())->copy()->timezone(config('app.timezone'));

        CashboxTransaction::where('payment_id', $payment->getKey())
            ->where(fn ($query) => $query->whereNull('payment_split_id')
                ->orWhereNotIn('payment_split_id', $payment->splits->modelKeys()))
            ->delete();
        foreach ($payment->splits as $split) {
            CashboxTransaction::updateOrCreate(['payment_split_id' => $split->getKey()], [
                'cashbox_day_id' => $day->getKey(), 'type' => 'patient_payment', 'amount' => $split->amount,
                'currency' => $split->currency, 'payment_method' => $split->payment_method,
                'transaction_date' => $payment->payment_date->copy()->setTimeFrom($transactionTime),
                'payment_id' => $payment->getKey(),
                'patient_id' => $payment->visit?->patient_id, 'visit_id' => $payment->visit_id,
                'description' => $payment->comment, 'created_by' => $payment->created_by,
            ]);
        }
    }

    public function syncFinanceTransaction(FinanceTransaction $finance): void
    {
        $shouldPost = $finance->payment_method === 'cash' && $finance->cash_source === 'current_cashier';

        if (! $shouldPost) {
            $this->removeFinanceTransaction($finance);

            return;
        }

        $day = $this->dayFor($finance->transaction_date->timezone(config('app.timezone'))->toDateString());

        if ($day->status === 'closed') {
            throw ValidationException::withMessages([
                'transaction_date' => 'ამ თარიღის სალარო უკვე დახურულია.',
            ]);
        }

        CashboxTransaction::updateOrCreate(['finance_transaction_id' => $finance->getKey()], [
            'cashbox_day_id' => $day->getKey(),
            'type' => $finance->type === 'expense' ? 'expense' : 'other_income',
            'amount' => $finance->amount,
            'currency' => $finance->currency,
            'payment_method' => 'cash',
            'transaction_date' => $finance->transaction_date,
            'expense_category' => $finance->type === 'expense' ? $finance->category : null,
            'description' => $finance->description,
            'created_by' => $finance->created_by,
        ]);
    }

    /** @param array<int, array<string, mixed>>|null $rows */
    public function syncProductSale(ProductSale $sale, ?array $rows = null): void
    {
        if (! in_array($sale->payment_method, ['cash', 'card'], true)) {
            CashboxTransaction::where('product_sale_id', $sale->getKey())->delete();

            return;
        }

        $localTime = $sale->sold_at->copy()->timezone(config('app.timezone'));
        $day = $this->dayFor($localTime->toDateString());

        if ($day->status === 'closed') {
            throw ValidationException::withMessages(['sold_at' => 'ამ თარიღის სალარო უკვე დახურულია.']);
        }

        $rows ??= CashboxTransaction::query()->where('product_sale_id', $sale->getKey())->get()
            ->map(fn (CashboxTransaction $transaction): array => [
                'payment_method' => $transaction->payment_method,
                'amount' => $transaction->amount,
                'currency' => $transaction->currency,
            ])->all();
        $rows = $rows ?: [['payment_method' => $sale->payment_method, 'amount' => $sale->total, 'currency' => $sale->currency]];
        $keys = collect($rows)->map(fn (array $row): string => $row['payment_method'].'|'.($row['currency'] ?? $sale->currency));
        CashboxTransaction::where('product_sale_id', $sale->getKey())->get()->each(function (CashboxTransaction $transaction) use ($keys): void {
            if (! $keys->contains($transaction->payment_method.'|'.$transaction->currency)) {
                $transaction->delete();
            }
        });
        foreach ($rows as $row) {
            CashboxTransaction::updateOrCreate([
                'product_sale_id' => $sale->getKey(),
                'payment_method' => $row['payment_method'],
                'currency' => $row['currency'] ?? $sale->currency,
            ], [
                'cashbox_day_id' => $day->getKey(), 'type' => 'product_sale', 'amount' => $row['amount'],
                'transaction_date' => $sale->sold_at, 'patient_id' => $sale->patient_id,
                'visit_id' => $sale->visit_id, 'description' => 'პროდუქტის გაყიდვა', 'created_by' => $sale->created_by,
            ]);
        }
    }

    public function removeFinanceTransaction(FinanceTransaction $finance): void
    {
        $cashbox = CashboxTransaction::where('finance_transaction_id', $finance->getKey())->first();

        if (! $cashbox) {
            return;
        }
        if ($cashbox->day()->where('status', 'closed')->exists()) {
            throw ValidationException::withMessages(['transaction_date' => 'დახურული სალაროს მოძრაობის შეცვლა შეუძლებელია.']);
        }

        $cashbox->delete();
    }

    public function summary(CashboxDay $day): array
    {
        $transactions = $day->transactions();
        $loadedTransactions = $day->relationLoaded('transactions') ? $day->transactions : null;
        $sum = function (string $currency, array $types, ?string $method = null) use ($transactions, $loadedTransactions): float {
            if ($loadedTransactions !== null) {
                return round((float) $loadedTransactions
                    ->where('currency', $currency)
                    ->whereIn('type', $types)
                    ->when($method, fn ($rows) => $rows->where('payment_method', $method))
                    ->sum('amount'), 2);
            }

            return round((float) (clone $transactions)
                ->where('currency', $currency)->whereIn('type', $types)
                ->when($method, fn ($query) => $query->where('payment_method', $method))
                ->sum('amount'), 2);
        };

        $opening = ['GEL' => (float) $day->opening_balance, 'USD' => (float) $day->opening_balance_usd];
        $cashIncome = $cardIncome = $cashExpenses = $cardExpenses = $expenses = $productSales = $withdrawals = $transferIn = $transferOut = $retained = $expected = $difference = [];

        foreach (array_keys(Currency::OPTIONS) as $currency) {
            $cashIncome[$currency] = $sum($currency, ['patient_payment', 'other_income', 'product_sale'], 'cash');
            $cardIncome[$currency] = $sum($currency, ['patient_payment', 'other_income', 'product_sale'], 'card');
            $cashExpenses[$currency] = $sum($currency, ['expense'], 'cash');
            $cardExpenses[$currency] = $sum($currency, ['expense'], 'card');
            $expenses[$currency] = $sum($currency, ['expense']);
            $productSales[$currency] = $sum($currency, ['product_sale']);
            $withdrawals[$currency] = $sum($currency, ['cash_withdrawal']);
            $transferIn[$currency] = $sum($currency, ['cash_transfer_in']);
            $transferOut[$currency] = $sum($currency, ['cash_transfer_out']);
            $retained[$currency] = max(round($withdrawals[$currency] - $transferOut[$currency], 2), 0);
            $expected[$currency] = round($opening[$currency] + $cashIncome[$currency] + $transferIn[$currency] - $cashExpenses[$currency] - $withdrawals[$currency], 2);
            $actual = $currency === 'GEL' ? $day->actual_closing_balance : $day->actual_closing_balance_usd;
            $closedExpected = $currency === 'GEL' ? $day->expected_closing_balance : $day->expected_closing_balance_usd;
            $difference[$currency] = $actual === null ? null : round((float) $actual - (float) ($day->status === 'closed' ? $closedExpected : $expected[$currency]), 2);
        }

        return [
            'opening' => $opening, 'cashIncomeByCurrency' => $cashIncome, 'cardIncomeByCurrency' => $cardIncome,
            'cashExpensesByCurrency' => $cashExpenses, 'cardExpensesByCurrency' => $cardExpenses,
            'expensesByCurrency' => $expenses, 'productSalesByCurrency' => $productSales,
            'withdrawalsByCurrency' => $withdrawals, 'expectedByCurrency' => $expected, 'differenceByCurrency' => $difference,
            'transferInByCurrency' => $transferIn, 'transferOutByCurrency' => $transferOut, 'retainedCashByCurrency' => $retained,
            'cashIncome' => $cashIncome['GEL'], 'cardIncome' => $cardIncome['GEL'], 'bankTransferIncome' => 0.0,
            'cashExpenses' => $cashExpenses['GEL'], 'cardExpenses' => $cardExpenses['GEL'],
            'withdrawals' => $withdrawals['GEL'], 'expected' => $expected['GEL'], 'difference' => $difference['GEL'],
        ];
    }

    public function retainedCash(CashboxDay $day, string $currency): float
    {
        if (! array_key_exists($currency, Currency::OPTIONS)) {
            return 0;
        }

        return $this->summary($day)['retainedCashByCurrency'][$currency];
    }

    public function transferCash(
        CashboxDay $source,
        CashboxDay $destination,
        float $amount,
        string $currency,
        ?string $note,
        string $idempotencyKey,
    ): CashTransfer {
        $amount = round($amount, 2);
        if ($amount <= 0 || ! array_key_exists($currency, Currency::OPTIONS)) {
            throw ValidationException::withMessages(['amount' => 'მიუთითეთ სწორი თანხა და ვალუტა.']);
        }
        if ($source->is($destination) || $source->date->gte($destination->date)) {
            throw ValidationException::withMessages(['source_cashbox_day_id' => 'წყარო უნდა იყოს წინა დახურული სალაროს დღე.']);
        }

        $key = Str::isUuid($idempotencyKey) ? $idempotencyKey : (string) Str::uuid();

        return DB::transaction(function () use ($source, $destination, $amount, $currency, $note, $key): CashTransfer {
            $days = CashboxDay::query()->whereKey([$source->getKey(), $destination->getKey()])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $lockedSource = $days->get($source->getKey());
            $lockedDestination = $days->get($destination->getKey());

            if ($existing = CashTransfer::where('idempotency_key', $key)->first()) {
                return $existing;
            }
            if (! $lockedSource || $lockedSource->status !== 'closed') {
                throw ValidationException::withMessages(['source_cashbox_day_id' => 'წყარო სალაროს დღე დახურული უნდა იყოს.']);
            }
            if (! $lockedDestination || $lockedDestination->status !== 'open') {
                throw ValidationException::withMessages(['destination_cashbox_day_id' => 'მიმღები სალაროს დღე ღია უნდა იყოს.']);
            }
            if ($amount > $this->retainedCash($lockedSource, $currency)) {
                throw ValidationException::withMessages(['amount' => 'წყაროში საკმარისი შენახული ქეში არ არის.']);
            }

            $transfer = CashTransfer::create([
                'source_cashbox_day_id' => $lockedSource->getKey(),
                'destination_cashbox_day_id' => $lockedDestination->getKey(),
                'amount' => $amount, 'currency' => $currency, 'transferred_at' => now(),
                'created_by' => auth()->id(), 'note' => $note, 'idempotency_key' => $key,
            ]);
            $description = 'ქეშის გადატანა '.$lockedSource->date->format('d.m.Y').' → '.$lockedDestination->date->format('d.m.Y');
            foreach ([[$lockedSource, 'cash_transfer_out'], [$lockedDestination, 'cash_transfer_in']] as [$day, $type]) {
                $day->transactions()->create([
                    'cash_transfer_id' => $transfer->getKey(), 'type' => $type,
                    'amount' => $amount, 'currency' => $currency, 'payment_method' => 'cash',
                    'transaction_date' => $transfer->transferred_at, 'description' => $description.($note ? ' · '.$note : ''),
                    'created_by' => auth()->id(),
                ]);
            }

            return $transfer->load('transactions');
        }, 3);
    }

    public function addOpeningBalance(CashboxDay $day, float $gel = 0, float $usd = 0): void
    {
        if ($day->status === 'closed' || ($gel <= 0 && $usd <= 0)) {
            throw ValidationException::withMessages(['opening_balance' => 'მიუთითეთ დასამატებელი საწყისი ნაშთი.']);
        }

        DB::transaction(function () use ($day, $gel, $usd): void {
            $day->increment('opening_balance', $gel);
            $day->increment('opening_balance_usd', $usd);

            $previous = CashboxDay::whereDate('date', '<', $day->date)->latest('date')->first();
            if ($previous?->status === 'closed') {
                $this->adjustClosedDayCarry($previous, $gel, $usd);
            }
        });
    }

    public function close(CashboxDay $day, float $actual, float $carry, ?string $notes = null, float $actualUsd = 0, float $carryUsd = 0): void
    {
        if ($day->status === 'closed') {
            throw ValidationException::withMessages(['actual_closing_balance' => 'ეს დღე უკვე დახურულია.']);
        }
        if ($actual < 0 || $carry < 0 || $carry > $actual || $actualUsd < 0 || $carryUsd < 0 || $carryUsd > $actualUsd) {
            throw ValidationException::withMessages(['carry_forward_balance' => 'დასატოვებელი თანხა უნდა იყოს 0-დან ფაქტობრივ ნაშთამდე.']);
        }

        DB::transaction(function () use ($day, $actual, $carry, $notes, $actualUsd, $carryUsd): void {
            $expectedBeforeClosingWithdrawal = $this->summary($day)['expectedByCurrency'];
            foreach (['GEL' => [$actual, $carry], 'USD' => [$actualUsd, $carryUsd]] as $currency => [$actualAmount, $carryAmount]) {
                $withdrawal = round($actualAmount - $carryAmount, 2);
                if ($withdrawal > 0) {
                    $day->transactions()->create([
                        'type' => 'cash_withdrawal', 'amount' => $withdrawal, 'currency' => $currency,
                        'payment_method' => 'cash', 'transaction_date' => $day->date->copy()->endOfDay(),
                        'description' => 'დღის დახურვისას სალაროდან ამოღებული ქეში',
                    ]);
                }
            }
            $summary = $this->summary($day->refresh());
            $day->update([
                'expected_closing_balance' => $expectedBeforeClosingWithdrawal['GEL'],
                'expected_closing_balance_usd' => $expectedBeforeClosingWithdrawal['USD'],
                'actual_closing_balance' => $actual, 'actual_closing_balance_usd' => $actualUsd,
                'cash_withdrawal_total' => $summary['withdrawalsByCurrency']['GEL'],
                'cash_withdrawal_total_usd' => $summary['withdrawalsByCurrency']['USD'],
                'carry_forward_balance' => $carry, 'carry_forward_balance_usd' => $carryUsd,
                'status' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id(), 'notes' => $notes,
            ]);

            CashboxDay::whereDate('date', '>', $day->date)
                ->where('status', 'open')
                ->oldest('date')
                ->first()?->update(['opening_balance' => $carry, 'opening_balance_usd' => $carryUsd]);
        });
    }

    private function adjustClosedDayCarry(CashboxDay $day, float $gel, float $usd): void
    {
        foreach (['GEL' => $gel, 'USD' => $usd] as $currency => $addition) {
            if ($addition <= 0) {
                continue;
            }

            $carryField = $currency === 'GEL' ? 'carry_forward_balance' : 'carry_forward_balance_usd';
            $withdrawalField = $currency === 'GEL' ? 'cash_withdrawal_total' : 'cash_withdrawal_total_usd';
            $newCarry = round((float) $day->{$carryField} + $addition, 2);
            $actual = (float) ($currency === 'GEL' ? $day->actual_closing_balance : $day->actual_closing_balance_usd);

            if ($newCarry > $actual) {
                throw ValidationException::withMessages(['opening_balance' => 'Carry ფაქტობრივ დახურვის ნაშთს ვერ გადააჭარბებს.']);
            }

            $handover = round($actual - $newCarry, 2);
            $alreadyTransferred = (float) $day->transactions()->where('type', 'cash_transfer_out')->where('currency', $currency)->sum('amount');
            if ($handover < $alreadyTransferred) {
                throw ValidationException::withMessages(['opening_balance' => 'Carry ვერ გაიზრდება: ამ დღის შენახული ქეშის ნაწილი უკვე გადატანილია.']);
            }
            $withdrawal = $day->transactions()->where('type', 'cash_withdrawal')->where('currency', $currency)
                ->where('description', 'დღის დახურვისას სალაროდან ამოღებული ქეში')->first();

            if ($handover > 0) {
                $withdrawal
                    ? CashboxTransaction::whereKey($withdrawal->getKey())->update(['amount' => $handover, 'updated_at' => now()])
                    : $day->transactions()->create([
                        'type' => 'cash_withdrawal', 'amount' => $handover, 'currency' => $currency,
                        'payment_method' => 'cash', 'transaction_date' => $day->date->copy()->endOfDay(),
                        'description' => 'დღის დახურვისას სალაროდან ამოღებული ქეში',
                    ]);
            } else {
                if ($withdrawal) {
                    CashboxTransaction::whereKey($withdrawal->getKey())->delete();
                }
            }

            $day->update([$carryField => $newCarry, $withdrawalField => $handover]);
        }
    }
}
