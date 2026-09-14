<?php

namespace App\Services\Finance;

use App\Models\CashboxDay;
use App\Models\FinanceOpeningBalance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OpeningBalanceService
{
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
            if (FinanceOpeningBalance::where(collect($data)->only(['source', 'bank', 'account_identifier', 'currency'])->all())->whereDate('effective_date', $data['effective_date'])->exists()) {
                throw ValidationException::withMessages(['effective_date' => __('finance-overview.opening_exists')]);
            }
            if ($data['source'] === 'cash') {
                if ($data['effective_date'] < today()->toDateString() || (float) $data['amount'] < 0) {
                    throw ValidationException::withMessages(['effective_date' => __('finance-overview.cash_opening_guard')]);
                }
                $day = CashboxDay::whereDate('date', $data['effective_date'])->lockForUpdate()->first();
                if ($day && ($day->status !== 'open' || $day->transactions()->exists())) {
                    throw ValidationException::withMessages(['effective_date' => __('finance-overview.cash_opening_guard')]);
                }
                // An explicit opening replaces the carried starting amount, never posts income.
                if ($day) {
                    $amounts = FinanceOpeningBalance::where('source', 'cash')->whereDate('effective_date', $data['effective_date'])->pluck('amount', 'currency');
                    $amounts->put($data['currency'], $data['amount']);
                    $day->update(['opening_balance' => $amounts->get('GEL', 0), 'opening_balance_usd' => $amounts->get('USD', 0)]);
                }
            }

            return FinanceOpeningBalance::create([...$data, 'created_by' => $actor->id]);
        });
    }
}
