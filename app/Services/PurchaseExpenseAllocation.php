<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared analytical shares for confirmed Bank portions and RS cash payments; never posts money. */
class PurchaseExpenseAllocation
{
    /** Payments provide id, entry_id, purchase_id and positive amount. Unknown directions retain a share. */
    public function shares(Builder $payments): Builder
    {
        $totals = DB::table('purchase_items')->whereIn('purchase_id', DB::query()->fromSub(clone $payments, 'paid')->select('purchase_id'))
            ->selectRaw('purchase_id, SUM(line_total) AS total, MIN(line_total) AS minimum')->groupBy('purchase_id');
        $groups = DB::query()->fromSub($payments, 'm')->join('purchase_items as i', 'i.purchase_id', '=', 'm.purchase_id')
            ->leftJoin('purchase_products as p', 'p.id', '=', 'i.purchase_product_id')
            ->leftJoin('expense_categories as d', fn ($join) => $join->on('d.id', '=', 'p.expense_direction_id')->where('d.classification_dimension', 'direction')->whereNull('d.parent_id'))
            ->joinSub($totals, 'dt', 'dt.purchase_id', '=', 'm.purchase_id')
            ->where('dt.total', '>', 0)->where('dt.minimum', '>=', 0)
            ->selectRaw('m.id, m.entry_id, m.purchase_id, d.id AS expense_direction_id, m.amount, dt.total,
                SUM(i.line_total) AS group_total, ROUND(m.amount * 100, 0) AS payment_cents')
            ->groupBy('m.id', 'm.entry_id', 'm.purchase_id', 'd.id', 'm.amount', 'dt.total');
        $floor = DB::getDriverName() === 'sqlite' ? 'CAST(payment_cents * 1.0 * group_total / total AS INTEGER)' : 'FLOOR(payment_cents * 1.0 * group_total / total)';
        $shares = DB::query()->fromSub($groups, 'g')->select('g.*')
            ->selectRaw("{$floor} AS base_cents, payment_cents * 1.0 * group_total / total - {$floor} AS fraction");
        $ranked = DB::query()->fromSub($shares, 's')->select('s.*')->selectRaw('SUM(base_cents) OVER (PARTITION BY id) AS base_sum,
            ROW_NUMBER() OVER (PARTITION BY id ORDER BY fraction DESC, COALESCE(expense_direction_id, 0)) AS remainder_rank');

        return DB::query()->fromSub($ranked, 'a')->select('entry_id', 'purchase_id', 'expense_direction_id')
            ->selectRaw('(base_cents + CASE WHEN remainder_rank <= payment_cents - base_sum THEN 1 ELSE 0 END) / 100.0 AS amount');
    }

    public function cashDistribution(): Builder
    {
        $payments = DB::table('finance_transactions')->whereNotNull('purchase_id')->select('id', 'id as entry_id', 'purchase_id', 'amount');

        return $this->shares($payments);
    }

    public function advanceDistribution(?int $advanceId = null): Builder
    {
        $shares = $this->shares(DB::table('employee_advance_entries')->where('kind', 'rs')
            ->when($advanceId, fn ($q) => $q->where('employee_advance_id', $advanceId))
            ->select('id', 'id as entry_id', 'purchase_id', 'amount'));

        // Aggregate consumers must sum the calculated share, not the inner payment amount.
        return DB::query()->fromSub($shares, 'advance_shares');
    }
}
