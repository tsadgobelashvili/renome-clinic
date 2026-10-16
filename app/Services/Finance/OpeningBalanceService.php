<?php

namespace App\Services\Finance;

use App\Models\CashboxDay;
use App\Models\FinanceOpeningBalance;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OpeningBalanceService
{
    /** A suggestion only: the Owner must confirm the actual opening date. */
    public function accountingStartDate(): ?string
    {
        $dates = [CashboxDay::min('date'), DB::table('cashbox_transactions')->min('transaction_date'),
            DB::table('partner_finance_transactions')->where('source', 'clinic')
                ->where(fn ($q) => $q->where('from_account', 'cash')->orWhere('to_account', 'cash'))->min('transacted_at'),
            DB::table('finance_transactions')->where('payment_method', 'cash')->whereIn('cash_source', ['current_cashier', 'withdrawn_cash'])->min('transaction_date'),
            DB::table('payments')->join('payment_splits', 'payments.id', '=', 'payment_splits.payment_id')
                ->whereNull('payments.deleted_at')->where('payment_splits.payment_method', 'cash')->min('payments.payment_date')];

        return collect($dates)->filter()->map(fn ($date) => Carbon::parse($date)->toDateString())->min();
    }

    public function create(array $data, User $actor): FinanceOpeningBalance
    {
        abort_unless($actor->isOwner(), 403);
        $isCash = ($data['source'] ?? null) === 'cash';
        $data['bank'] = $isCash ? '' : strtoupper(trim($data['bank'] ?? 'BOG'));
        $data['account_identifier'] = $isCash ? 'cashier' : trim($data['account_identifier'] ?? '');
        $data['currency'] = strtoupper(trim($data['currency'] ?? ''));
        $data = validator($data, [
            'source' => 'required|in:cash,bank', 'bank' => 'nullable|required_if:source,bank|string|max:20',
            'account_identifier' => 'required|string|max:255', 'currency' => $isCash ? ['required', Rule::in(['GEL', 'USD'])] : ['required', 'regex:/^[A-Z]{3}$/'],
            'effective_date' => 'required|date_format:Y-m-d', 'amount' => ['required', 'numeric', 'decimal:0,2', 'between:-9999999999999.99,9999999999999.99'], 'note' => 'nullable|string|max:2000',
        ])->validate();

        return DB::transaction(function () use ($data, $actor): FinanceOpeningBalance {
            $identity = collect($data)->only(['source', 'bank', 'account_identifier', 'currency', 'effective_date'])->all();
            $query = FinanceOpeningBalance::where(collect($identity)->except('effective_date')->all())->whereDate('effective_date', $data['effective_date']);
            if ($existing = $query->first()) {
                return $this->replay($existing, $data, $actor);
            }
            if ($data['source'] === 'cash') {
                if ((float) $data['amount'] < 0) {
                    throw ValidationException::withMessages(['amount' => __('finance-overview.cash_opening_guard')]);
                }
                $day = CashboxDay::whereDate('date', $data['effective_date'])->lockForUpdate()->first();
                $historical = $data['effective_date'] < today()->toDateString();
                // Backdating is a starting anchor, never a mid-history reset that drops
                // previously recorded receipts/withdrawals from cumulative cash.
                $start = $this->accountingStartDate();
                if ($historical && $start && $data['effective_date'] > $start) {
                    throw ValidationException::withMessages(['effective_date' => __('finance-overview.historical_opening_guard', ['date' => $start])]);
                }
                $startingToday = $data['effective_date'] === today()->toDateString() && $start === $data['effective_date'];
                if (! $historical && ! $startingToday && $day && ($day->status !== 'open' || $day->transactions()->exists())) {
                    throw ValidationException::withMessages(['effective_date' => __('finance-overview.cash_opening_guard')]);
                }
            }

            // The existing unique account/currency/date constraint also serializes retries.
            $opening = $query->createOrFirst([], [...$data, 'created_by' => $actor->id]);
            if (! $opening->wasRecentlyCreated) {
                return $this->replay($opening, $data, $actor);
            }
            if ($data['source'] === 'cash') {
                // Never rewrite a posted/closed drawer or its historical closing snapshots.
                if ($day && $day->status === 'open' && ! $day->transactions()->exists()) {
                    $amounts = FinanceOpeningBalance::where('source', 'cash')->whereDate('effective_date', $data['effective_date'])->pluck('amount', 'currency');
                    $amounts->put($data['currency'], $data['amount']);
                    $day->update(['opening_balance' => $amounts->get('GEL', 0), 'opening_balance_usd' => $amounts->get('USD', 0)]);
                }
            }

            DB::afterCommit(fn () => Log::info('Finance opening balance created', [
                'opening_balance_id' => $opening->id, ...$identity, 'amount' => $opening->amount,
                'user_id' => $actor->id, 'timestamp' => now()->toIso8601String(),
            ]));

            return $opening;
        });
    }

    private function replay(FinanceOpeningBalance $existing, array $data, User $actor): FinanceOpeningBalance
    {
        if (Money::minorUnits($existing->amount) !== Money::minorUnits($data['amount'])
            || ($existing->note ?? '') !== ($data['note'] ?? '') || $existing->created_by !== $actor->id) {
            throw ValidationException::withMessages(['effective_date' => __('finance-overview.opening_exists')]);
        }

        return $existing;
    }
}
