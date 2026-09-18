<?php

namespace App\Services\Finance;

use App\Services\FinanceUsdUsageService;
use App\Support\CashboxManager;

class LiquidityReport
{
    /** Read-only: a reporting render never opens/closes a Cashier day. */
    public function current(string $businessSource = 'all', ?array $bogBalance = null): array
    {
        validator(compact('businessSource'), ['businessSource' => 'in:all,clinic,israeli'])->validate();
        $cash = app(CashboxManager::class)->physicalCashSnapshot();
        $balanceService = app(FinanceUsdUsageService::class);
        $clinic = $balanceService->cashBalances('clinic', array_map(fn (array $balance): float => $balance['amount'], $cash));
        $israeli = $balanceService->cashBalances('israeli');
        foreach ($cash as $currency => $balance) {
            $balance['clinic'] = $clinic[$currency] ?? 0.0;
            $balance['israeli'] = $israeli[$currency] ?? 0.0;
            $balance['amount'] = $businessSource === 'all'
                ? round($balance['clinic'] + $balance['israeli'], 2)
                : $balance[$businessSource];
            $cash[$currency] = $balance;
        }
        // Account rows and summary share the same API snapshot. Legacy account aliases are not inputs.
        $accounts = collect($bogBalance === null ? [] : [(object) $bogBalance]);
        $currencies = collect(['GEL', 'USD'])->merge($accounts->pluck('currency'))->unique()->sort();
        $totals = [];
        foreach ($currencies as $currency) {
            $bankRows = $accounts->where('currency', $currency);
            $bank = $bankRows->isEmpty() ? null : round((float) $bankRows->sum('reported_balance'), 2);
            $cashAmount = $cash[$currency]['amount'] ?? 0;
            $totals[$currency] = ['cash' => $cashAmount, 'bank' => $bank, 'available' => $bank === null || $businessSource !== 'all' ? null : round($cashAmount + $bank, 2)];
        }

        return ['cash' => $cash, 'accounts' => $accounts, 'totals' => $totals];
    }
}
