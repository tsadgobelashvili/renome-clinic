<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Read the original expense once; cashbox copies and linked settlement legs are never purchases. */
class ManualPurchaseAnalysis
{
    public static function concatenate(string $prefix, string $expression): string
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? "CONCAT('{$prefix}', {$expression})" : "'{$prefix}' || {$expression}";
    }

    public function lines(array $filters = []): Builder
    {
        return $this->ledger(false, $filters)->unionAll($this->ledger(true, $filters));
    }

    private function ledger(bool $partner, array $filters): Builder
    {
        $table = $partner ? 'partner_finance_transactions' : 'finance_transactions';
        $date = $partner ? 'transacted_at' : 'transaction_date';
        $name = $partner ? 'recipient' : 'description';
        $method = $partner ? 'from_account' : 'payment_method';
        $origin = $partner ? 'partner' : 'finance';
        $id = $partner ? '-e.id * 2 - 1' : '-e.id * 2';
        $integer = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'SIGNED' : 'BIGINT';
        $event = self::concatenate($origin.':', 'e.id');
        $product = self::concatenate('manual:', 'e.'.$name);
        $query = DB::table($table.' as e')
            ->join('purchase_product_groups as g', 'g.id', '=', 'e.purchase_product_group_id')
            ->where('e.type', 'expense')->whereNotNull('e.material_quantity')->where('e.material_quantity', '>', 0)
            ->whereNull('e.salary_settlement_id')->whereNull('e.lab_salary_settlement_id')->whereNull('e.salary_payout_allocation_id')
            ->when($partner, fn ($q) => $q->whereNull('e.finance_transaction_id'),
                fn ($q) => $q->whereNull('e.purchase_id')->whereNull('e.payroll_entry_id')->whereNull('e.employee_salary_settlement_id'))
            ->when($filters['from'] ?? null, fn ($q, $dateValue) => $q->whereDate('e.'.$date, '>=', Carbon::parse($dateValue)->toDateString()))
            ->when($filters['until'] ?? null, fn ($q, $dateValue) => $q->whereDate('e.'.$date, '<=', Carbon::parse($dateValue)->toDateString()))
            ->when($filters['direction'] ?? null, fn ($q, $value) => $q->where('e.expense_direction_id', $value))
            ->when($filters['group'] ?? null, fn ($q, $value) => $q->where('e.purchase_product_group_id', $value))
            ->when($filters['product'] ?? null, fn ($q, $value) => $q->where('e.purchase_product_id', $value))
            ->when($filters['currency'] ?? null, fn ($q, $value) => $q->where('e.currency', $value))
            ->when($filters['supplier'] ?? null, fn ($q) => $q->whereRaw('1 = 0'));
        match ($filters['payment'] ?? 'all') {
            'all' => null,
            'cash' => $query->where('e.'.$method, 'cash'),
            'bank' => $query->whereIn('e.'.$method, $partner ? ['bank'] : ['bank_transfer', 'card']),
            default => $query->whereRaw('1 = 0'),
        };

        // Type NULL identifiers before the nested UNION; PostgreSQL otherwise resolves them to text.
        return $query->selectRaw("CAST({$id} AS {$integer}) AS id,
            CAST(NULL AS {$integer}) AS purchase_id, CAST(NULL AS {$integer}) AS product_id,
            e.purchase_product_id, e.purchase_product_id AS resolved_product_id,
            e.material_quantity AS quantity, e.material_unit AS unit,
            e.amount * 1.0 / NULLIF(e.material_quantity, 0) AS unit_price, e.amount AS line_total,
            e.{$name} AS item_name, e.{$date} AS purchase_date, NULL AS document_number, NULL AS supplier_name,
            e.purchase_product_group_id AS group_id, g.name AS group_name, e.expense_direction_id AS direction_id,
            {$product} AS product_key, e.{$name} AS product_name, e.currency,
            {$event} AS event_key, 'manual' AS origin, '{$origin}' AS ledger_source");
    }
}
