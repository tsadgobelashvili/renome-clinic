<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared analytical shares for confirmed Bank portions and RS cash payments; never posts money. */
class PurchaseExpenseAllocation
{
    /** Payments provide id, entry_id, purchase_id and positive amount. Unknown directions retain a share. */
    public function shares(Builder $payments, bool $catalog = false): Builder
    {
        $integer = in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? 'SIGNED' : 'BIGINT';
        $totals = DB::table('purchase_items')->whereIn('purchase_id', DB::query()->fromSub(clone $payments, 'paid')->select('purchase_id'))
            ->selectRaw("purchase_id, SUM(CAST(ROUND(line_total * 100, 0) AS {$integer})) AS total, MIN(line_total) AS minimum")->groupBy('purchase_id');
        $groups = DB::query()->fromSub($payments, 'm');
        $direction = 'p.expense_direction_id';
        if ($catalog) {
            // Same canonical aliases and verified legacy identities as RS analysis.
            // Resolve on reads; original invoice lines and payment links stay intact.
            $mapping = app(PurchaseAnalysisMapping::class);
            $groupDirections = DB::table('purchase_products')->whereNotNull('purchase_product_group_id')->whereNotNull('expense_direction_id')
                ->groupBy('purchase_product_group_id')->havingRaw('COUNT(DISTINCT expense_direction_id) = 1')
                ->selectRaw('purchase_product_group_id, MIN(expense_direction_id) AS direction_id');
            $groups->joinSub($mapping->items(), 'i', 'i.purchase_id', '=', 'm.purchase_id')
                ->leftJoin('purchase_products as p', 'p.id', '=', 'i.resolved_product_id')
                ->leftJoin('purchase_product_groups as pg', 'pg.id', '=', 'p.purchase_product_group_id')
                ->leftJoinSub($groupDirections, 'gd', 'gd.purchase_product_group_id', '=', 'p.purchase_product_group_id');
            $groupDirection = $mapping->capabilities()['group_direction'] ? 'pg.expense_direction_id' : 'NULL';
            $direction = "COALESCE(p.expense_direction_id, {$groupDirection}, gd.direction_id, i.legacy_direction_id)";
        } else {
            $groups->join('purchase_items as i', 'i.purchase_id', '=', 'm.purchase_id')
                ->leftJoin('purchase_products as p', 'p.id', '=', 'i.purchase_product_id');
        }
        $groups->leftJoin('expense_categories as d', fn ($join) => $join->on('d.id', '=', DB::raw($direction))->where('d.classification_dimension', 'direction')->whereNull('d.parent_id'))
            ->joinSub($totals, 'dt', 'dt.purchase_id', '=', 'm.purchase_id')
            ->where('dt.total', '>', 0)->where('dt.minimum', '>=', 0)
            ->selectRaw("m.id, m.entry_id, m.purchase_id, d.id AS expense_direction_id, m.amount, dt.total,
                SUM(CAST(ROUND(i.line_total * 100, 0) AS {$integer})) AS group_total, CAST(ROUND(m.amount * 100, 0) AS {$integer}) AS payment_cents")
            ->groupBy('m.id', 'm.entry_id', 'm.purchase_id', 'd.id', 'm.amount', 'dt.total');
        if ($catalog) {
            $groups->addSelect('p.id as purchase_product_id', 'p.purchase_product_group_id')
                ->groupBy('p.id', 'p.purchase_product_group_id');
        }
        // Largest remainder in integer tetri. Rank integer remainders rather than
        // subtracting floating-point quotas, including for fully paid invoices.
        $numerator = 'CAST(payment_cents AS DECIMAL(38, 0)) * group_total';
        $floor = match (DB::getDriverName()) {
            'pgsql' => "DIV({$numerator}, total)",
            'mysql', 'mariadb' => "(({$numerator}) DIV total)",
            default => "CAST(({$numerator}) / total AS INTEGER)",
        };
        $remainder = DB::getDriverName() === 'sqlite' ? "({$numerator}) % total" : "MOD({$numerator}, total)";
        $shares = DB::query()->fromSub($groups, 'g')->select('g.*')
            ->selectRaw("{$floor} AS base_cents, {$remainder} AS fraction");
        $ranked = DB::query()->fromSub($shares, 's')->select('s.*')->selectRaw('SUM(base_cents) OVER (PARTITION BY id) AS base_sum,
            ROW_NUMBER() OVER (PARTITION BY id ORDER BY fraction DESC, COALESCE(expense_direction_id, 0)'.($catalog ? ', COALESCE(purchase_product_id, 0), COALESCE(purchase_product_group_id, 0)' : '').') AS remainder_rank');

        $result = DB::query()->fromSub($ranked, 'a')->select('entry_id', 'purchase_id', 'expense_direction_id')
            ->selectRaw('(base_cents + CASE WHEN remainder_rank <= payment_cents - base_sum THEN 1 ELSE 0 END) / 100.0 AS amount');
        if ($catalog) {
            $result->addSelect('purchase_product_id', 'purchase_product_group_id');
        }

        return $result;
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
