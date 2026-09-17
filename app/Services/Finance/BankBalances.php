<?php

namespace App\Services\Finance;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BankBalances
{
    /** Historical statement/opening balances for reconciliation, never a live liquidity source. */
    public function current(?string $currency = null): Collection
    {
        $end = today()->endOfDay()->toDateTimeString();
        $statements = DB::table('bank_import_batches')->whereNull('rolled_back_at')->whereNotNull('reported_balance')->whereNotNull('balance_as_of')
            ->where('balance_as_of', '<=', $end)->selectRaw("bank, COALESCE(account_identifier, '') AS account_identifier, currency,
                reported_balance AS base_balance, balance_as_of, balance_origin, imported_at, id AS record_id, 2 AS priority");
        $openings = DB::table('finance_opening_balances')->where('source', 'bank')->whereDate('effective_date', '<=', today()->toDateString())
            ->selectRaw("bank, account_identifier, currency, amount AS base_balance, effective_date AS balance_as_of, 'opening' AS balance_origin, created_at AS imported_at, id AS record_id, 1 AS priority");
        $anchors = $statements->unionAll($openings);
        $ranked = DB::query()->fromSub(clone $anchors, 'anchors')->select('anchors.*')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY bank, account_identifier, currency ORDER BY priority DESC, balance_as_of DESC, record_id DESC) AS anchor_rank');
        $accounts = DB::table('bank_transactions')->where('transaction_date', '<=', $end)
            ->selectRaw("bank, COALESCE(account_identifier, '') AS account_identifier, currency")->distinct()
            ->union(DB::query()->fromSub($anchors, 'account_anchors')->select('bank', 'account_identifier', 'currency'));

        return DB::query()->fromSub($accounts, 'accounts')->leftJoinSub($ranked, 'a', fn ($join) => $join
            ->on('a.bank', '=', 'accounts.bank')->on('a.account_identifier', '=', 'accounts.account_identifier')->on('a.currency', '=', 'accounts.currency')->where('a.anchor_rank', 1))
            ->when(filled($currency), fn ($q) => $q->where('accounts.currency', $currency))
            ->selectRaw('accounts.bank, accounts.account_identifier, accounts.currency, a.base_balance, a.balance_as_of, a.balance_origin, a.imported_at, a.record_id,
                a.base_balance AS reported_balance, a.balance_as_of AS ledger_as_of')
            ->orderBy('accounts.currency')->orderBy('accounts.bank')->orderBy('accounts.account_identifier')->get();
    }
}
