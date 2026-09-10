<?php

namespace App\Support;

use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Models\CashTransfer;
use App\Models\FinanceTransaction;
use App\Models\Payment;
use App\Models\ProductSale;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CashboxManager
{
    private const CLOSING_HANDOVER_DESCRIPTION = 'დღის დახურვისას სალაროდან ამოღებული ქეში';

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
        $this->ensureCalendarDaysThroughToday();

        return CashboxDay::whereDate('date', '<', today())->where('status', 'open')->oldest('date')->first();
    }

    public function oldestUnclosedDay(): CashboxDay
    {
        $this->ensureCalendarDaysThroughToday();

        return CashboxDay::query()
            ->whereDate('date', '<=', today())
            ->where('status', 'open')
            ->oldest('date')
            ->first() ?? $this->today();
    }

    public function ensureCalendarDaysThroughToday(): void
    {
        $firstDate = CashboxDay::query()->oldest('date')->value('date');

        if (! $firstDate) {
            $this->today();

            return;
        }

        $date = Carbon::parse($firstDate)->startOfDay();
        $today = today()->startOfDay();

        while ($date->lte($today)) {
            $this->dayFor($date->toDateString());
            $date->addDay();
        }
    }

    public function syncPayment(Payment $payment, bool $allowClosedDayCorrection = false): void
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

        if ($day->status === 'closed' && ! $allowClosedDayCorrection) {
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
        $amount = $finance->clinic_cash_gel ?? $finance->amount;
        $shouldPost = $finance->payment_method === 'cash' && $finance->cash_source === 'current_cashier' && (float) $amount > 0;

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
            'amount' => $amount,
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

    /** SQL aggregates for one or many days, including the canonical patient card splits. */
    public function summaryTotals(Collection $days): Collection
    {
        if ($days->isEmpty()) {
            return collect();
        }
        $ids = $days->pluck('id');
        $ledger = DB::table('cashbox_transactions')->whereIn('cashbox_day_id', $ids)
            ->where(fn ($query) => $query->where('type', '!=', 'patient_payment')->orWhere('payment_method', '!=', 'card'))
            ->selectRaw('cashbox_day_id, currency, type, payment_method, SUM(amount) AS aggregate_amount')
            ->groupBy('cashbox_day_id', 'currency', 'type', 'payment_method');
        $cards = DB::table('payment_splits')
            ->join('payments', 'payments.id', '=', 'payment_splits.payment_id')
            ->join('cashbox_days', DB::raw('DATE(cashbox_days.date)'), '=', DB::raw('DATE(payments.payment_date)'))
            ->whereIn('cashbox_days.id', $ids)->whereNull('payments.deleted_at')
            ->where('payment_splits.payment_method', 'card')
            ->whereBetween('payments.payment_date', [$days->min('date')->toDateString(), $days->max('date')->copy()->endOfDay()])
            ->selectRaw("cashbox_days.id AS cashbox_day_id, payment_splits.currency, 'patient_payment' AS type, 'card' AS payment_method, SUM(payment_splits.amount) AS aggregate_amount")
            ->groupBy('cashbox_days.id', 'payment_splits.currency');

        return $ledger->unionAll($cards)->get()->groupBy('cashbox_day_id');
    }

    public function summary(CashboxDay $day, ?Collection $totals = null): array
    {
        $totals ??= $this->summaryTotals(collect([$day]))->get($day->id, collect());
        $sum = function (string $currency, array $types, ?string $method = null) use ($totals): float {
            return round((float) $totals->where('currency', $currency)->whereIn('type', $types)
                ->when($method, fn ($rows) => $rows->where('payment_method', $method))
                ->sum('aggregate_amount'), 2);
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
            $expected[$currency] = round($opening[$currency] + $cashIncome[$currency] + $transferIn[$currency] - $cashExpenses[$currency] - $withdrawals[$currency], 2);
            $actual = $currency === 'GEL' ? $day->actual_closing_balance : $day->actual_closing_balance_usd;
            $closedExpected = $currency === 'GEL' ? $day->expected_closing_balance : $day->expected_closing_balance_usd;
            $carry = $currency === 'GEL' ? $day->carry_forward_balance : $day->carry_forward_balance_usd;
            $retained[$currency] = $day->status === 'closed' && $actual !== null
                ? max(round((float) $actual - (float) $carry - $transferOut[$currency], 2), 0)
                : max(round($withdrawals[$currency] - $transferOut[$currency], 2), 0);
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
        $gel = round($gel, 2);
        $usd = round($usd, 2);

        if ($day->status === 'closed' || ($gel <= 0 && $usd <= 0)) {
            throw ValidationException::withMessages(['opening_balance' => 'მიუთითეთ დასამატებელი საწყისი ნაშთი.']);
        }

        DB::transaction(function () use ($day, $gel, $usd): void {
            $lockedDay = CashboxDay::query()->lockForUpdate()->findOrFail($day->getKey());
            $available = $this->availableCashForOpening($lockedDay);

            if ($gel > $available['GEL'] || $usd > $available['USD']) {
                throw ValidationException::withMessages([
                    'opening_balance' => 'საწყისი ნაშთი ხელმისაწვდომ ნაღდ თანხას ვერ გადააჭარბებს.',
                ]);
            }

            $lockedDay->increment('opening_balance', $gel);
            $lockedDay->increment('opening_balance_usd', $usd);
        });
    }

    /** @return array{GEL: float, USD: float} */
    public function availableCashForOpening(CashboxDay $day): array
    {
        $balances = $this->retainedCashByCurrency($day->date->copy()->startOfDay());
        $previous = CashboxDay::query()->whereDate('date', '<', $day->date)->latest('date')->first();

        foreach (array_keys(Currency::OPTIONS) as $currency) {
            $opening = $currency === 'GEL'
                ? (float) $day->opening_balance
                : (float) $day->opening_balance_usd;
            $inheritedCarry = $previous?->status === 'closed'
                ? (float) ($currency === 'GEL' ? $previous->carry_forward_balance : $previous->carry_forward_balance_usd)
                : 0.0;
            $allocated = max(round($opening - $inheritedCarry, 2), 0);

            $balances[$currency] = max(round($balances[$currency] - $allocated, 2), 0);
        }

        return $balances;
    }

    /** @return array{GEL: float, USD: float} */
    public function retainedCashByCurrency(?\DateTimeInterface $before = null): array
    {
        $pool = array_fill_keys(array_keys(Currency::OPTIONS), 0.0);
        $days = CashboxDay::query()
            ->when($before, fn ($query) => $query->where('date', '<', $before))
            ->with('transactions')
            ->orderBy('date')
            ->orderBy('id')
            ->get();
        $previous = null;

        foreach ($days as $day) {
            foreach (array_keys(Currency::OPTIONS) as $currency) {
                $opening = (float) ($currency === 'GEL' ? $day->opening_balance : $day->opening_balance_usd);
                $inheritedCarry = $previous?->status === 'closed'
                    ? (float) ($currency === 'GEL' ? $previous->carry_forward_balance : $previous->carry_forward_balance_usd)
                    : 0.0;
                $poolAllocation = max(round($opening - $inheritedCarry, 2), 0);
                $pool[$currency] = max(round($pool[$currency] - $poolAllocation, 2), 0);

                if ($day->status !== 'closed') {
                    continue;
                }

                $cash = $day->transactions
                    ->where('currency', $currency)
                    ->where('payment_method', 'cash');
                $inflows = (float) $cash->whereIn('type', ['patient_payment', 'other_income', 'product_sale', 'cash_transfer_in'])->sum('amount');
                $expenses = (float) $cash->where('type', 'expense')->sum('amount');
                $withdrawals = (float) $cash->where('type', 'cash_withdrawal')
                    ->filter(fn (CashboxTransaction $transaction): bool => $transaction->description !== self::CLOSING_HANDOVER_DESCRIPTION)
                    ->sum('amount');
                $transferOut = (float) $cash->where('type', 'cash_transfer_out')->sum('amount');
                $carry = (float) ($currency === 'GEL' ? $day->carry_forward_balance : $day->carry_forward_balance_usd);
                $actual = $currency === 'GEL' ? $day->actual_closing_balance : $day->actual_closing_balance_usd;
                $ledgerRetained = max(round($opening + $inflows - $expenses - $withdrawals - $carry, 2), 0);
                $countedRetained = max($ledgerRetained, max(round((float) ($actual ?? 0) - $carry, 2), 0));

                $pool[$currency] = max(round($pool[$currency] + $countedRetained - $transferOut, 2), 0);
            }

            $previous = $day;
        }

        return $pool;
    }

    /** @return array{GEL: float, USD: float} */
    public function physicalCashBalances(?\DateTimeInterface $before = null): array
    {
        $balances = [];

        foreach (array_keys(Currency::OPTIONS) as $currency) {
            $transactions = CashboxTransaction::query()
                ->when($before, fn ($query) => $query->where('transaction_date', '<', $before))
                ->where('currency', $currency)
                ->where('payment_method', 'cash');
            $inflows = (float) (clone $transactions)
                ->whereIn('type', ['patient_payment', 'other_income', 'product_sale', 'cash_transfer_in'])
                ->sum('amount');
            $spent = (float) (clone $transactions)
                ->whereIn('type', ['expense', 'cash_transfer_out'])
                ->sum('amount');
            $withdrawn = (float) (clone $transactions)
                ->where('type', 'cash_withdrawal')
                ->where(function ($query): void {
                    $query->whereNull('description')
                        ->orWhere('description', '!=', self::CLOSING_HANDOVER_DESCRIPTION);
                })
                ->sum('amount');

            $balances[$currency] = round($inflows - $spent - $withdrawn, 2);
        }

        return $balances;
    }

    public function close(CashboxDay $day, float $actual, float $carry, ?string $notes = null, float $actualUsd = 0, float $carryUsd = 0): void
    {
        $oldestUnclosed = $this->oldestUnclosedDay();

        if (! $day->date->isSameDay($oldestUnclosed->date)) {
            throw ValidationException::withMessages([
                'actual_closing_balance' => 'ჯერ უნდა დაიხუროს '.$oldestUnclosed->date->format('d.m.Y').' დღის სალარო.',
            ]);
        }

        if ($day->status === 'closed') {
            throw ValidationException::withMessages(['actual_closing_balance' => 'ეს დღე უკვე დახურულია.']);
        }
        if ($actual < 0 || $carry < 0 || $carry > $actual || $actualUsd < 0 || $carryUsd < 0 || $carryUsd > $actualUsd) {
            throw ValidationException::withMessages(['carry_forward_balance' => 'დასატოვებელი თანხა უნდა იყოს 0-დან ფაქტობრივ ნაშთამდე.']);
        }

        DB::transaction(function () use ($day, $actual, $carry, $notes, $actualUsd, $carryUsd): void {
            $lockedDay = CashboxDay::query()->lockForUpdate()->findOrFail($day->getKey());

            if ($lockedDay->status === 'closed') {
                throw ValidationException::withMessages(['actual_closing_balance' => 'ეს დღე უკვე დახურულია.']);
            }

            $lockedOldest = CashboxDay::query()
                ->whereDate('date', '<=', today())
                ->where('status', 'open')
                ->oldest('date')
                ->lockForUpdate()
                ->first();

            if (! $lockedOldest || ! $lockedDay->date->isSameDay($lockedOldest->date)) {
                throw ValidationException::withMessages([
                    'actual_closing_balance' => 'ჯერ უნდა დაიხუროს '.($lockedOldest?->date->format('d.m.Y') ?? 'უფრო ძველი').' დღის სალარო.',
                ]);
            }

            $summary = $this->summary($lockedDay);
            $lockedDay->update([
                'expected_closing_balance' => $summary['expectedByCurrency']['GEL'],
                'expected_closing_balance_usd' => $summary['expectedByCurrency']['USD'],
                'actual_closing_balance' => $actual, 'actual_closing_balance_usd' => $actualUsd,
                'cash_withdrawal_total' => $summary['withdrawalsByCurrency']['GEL'],
                'cash_withdrawal_total_usd' => $summary['withdrawalsByCurrency']['USD'],
                'carry_forward_balance' => $carry, 'carry_forward_balance_usd' => $carryUsd,
                'status' => 'closed', 'closed_at' => now(), 'closed_by' => auth()->id(), 'notes' => $notes,
            ]);

            CashboxDay::whereDate('date', '>', $lockedDay->date)
                ->where('status', 'open')
                ->oldest('date')
                ->first()?->update(['opening_balance' => $carry, 'opening_balance_usd' => $carryUsd]);
        });

        $day->refresh();
    }
}
