<?php

namespace App\Services\Bank;

use App\Models\BankTransaction;
use App\Services\Finance\BankBalances;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Raw bank movement reporting; deliberately independent of Finance income and cash balances. */
class BankReport
{
    public function query(array $filters): Builder
    {
        $query = BankTransaction::query();
        if (($filters['viewMode'] ?? 'all') === 'relevant') {
            $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('bank_categories as hidden')
                ->whereColumn('hidden.id', 'bank_transactions.bank_category_id')->where('hidden.accounting_treatment', 'settlement'));
        }
        if (! empty($filters['dateFrom'])) {
            $query->where('transaction_date', '>=', $filters['dateFrom'].' 00:00:00');
        }
        if (! empty($filters['dateUntil'])) {
            $query->where('transaction_date', '<=', $filters['dateUntil'].' 23:59:59');
        }
        foreach (['direction' => 'direction', 'currency' => 'currency', 'category' => 'bank_category_id', 'operationType' => 'operation_type'] as $filter => $column) {
            if (filled($filters[$filter] ?? null)) {
                $query->where($column, $filters[$filter]);
            }
        }
        if (filled($filters['expenseCategory'] ?? null)) {
            $query->where('expense_category_id', $filters['expenseCategory']);
        }
        if (filled($filters['search'] ?? null)) {
            $query->where(function (Builder $query) use ($filters): void {
                foreach (['counterparty_name', 'counterparty_account', 'account_identifier', 'description', 'operation_id', 'reference'] as $field) {
                    $query->orWhere($field, 'like', '%'.$filters['search'].'%');
                }
            });
        }

        return $query;
    }

    public function totals(array $filters): Collection
    {
        unset($filters['viewMode']); // Visibility never changes financial totals.

        return $this->query($filters)->leftJoin('bank_categories as bc', 'bc.id', '=', 'bank_transactions.bank_category_id')->select('currency')
            ->selectRaw("SUM(CASE WHEN direction = 'inflow' THEN amount ELSE 0 END) AS inflow")
            ->selectRaw("SUM(CASE WHEN direction = 'outflow' THEN amount ELSE 0 END) AS outflow")
            ->selectRaw('SUM(CASE WHEN exclude_from_pnl = FALSE AND is_legacy = FALSE THEN CASE WHEN '.BankAccounting::expenseSql().' THEN amount ELSE '.BankAccounting::embeddedFeeSql().' END ELSE 0 END) AS expenses')
            ->selectRaw('SUM('.BankAccounting::feeSql().') AS fees')->groupBy('currency')->orderBy('currency')->get();
    }

    public function balances(?string $currency = null): Collection
    {
        return app(BankBalances::class)->current($currency)->filter(fn ($row) => $row->reported_balance !== null)->values();
    }
}
