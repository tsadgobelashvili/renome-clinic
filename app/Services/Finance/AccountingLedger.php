<?php

namespace App\Services\Finance;

use App\Services\Bank\BankAccounting;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseExpenseAllocation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** The same normalized SQL entries supply cards, category totals and paginated details. */
class AccountingLedger
{
    public function pnl(?string $from, ?string $until, string $source = 'all', string $businessSource = 'all'): Builder
    {
        $this->validateRange($from, $until, $source);
        validator(compact('businessSource'), ['businessSource' => 'in:all,clinic,israeli'])->validate();
        $financeAmount = match ($businessSource) {
            'clinic' => 'COALESCE(f.clinic_cash_gel, f.amount)',
            'israeli' => 'CASE WHEN f.clinic_cash_gel IS NOT NULL THEN COALESCE(f.israeli_cash_gel, 0) ELSE f.amount END',
            default => 'f.amount',
        };
        $queries = [];
        if ($source !== 'bank') {
            $queries = $this->erpReceipts($from, $until);
            // Older Cashier expenses may have no Finance mirror. Include those once only.
            $queries[] = $this->entry($this->expenseCategory(DB::table('cashbox_transactions as f')
                ->where('f.type', 'expense')->whereNull('f.finance_transaction_id')->whereNull('f.payment_id')->whereNull('f.product_sale_id'), true), [
                    'entry_key' => "'cashbox-expense:' || CAST(f.id AS VARCHAR)", 'entry_date' => 'f.transaction_date', 'origin' => "'expense'", 'metric' => "'expense'",
                    'amount' => 'f.amount', 'currency' => 'f.currency', 'category_key' => "COALESCE('expense:' || CAST(ec.id AS VARCHAR), 'other')",
                    'category_name' => 'ec.name', 'payment_method' => 'f.payment_method', 'description' => 'f.description',
                ], $from, $until);
            $queries[] = $this->entry($this->expenseCategory(DB::table('finance_transactions as f')), [
                'entry_key' => "'finance:' || CAST(f.id AS VARCHAR)", 'entry_date' => 'f.transaction_date', 'origin' => "'finance'",
                'metric' => "CASE WHEN f.type = 'expense' OR f.reversal_of_finance_transaction_id IS NOT NULL THEN 'expense' ELSE 'revenue' END",
                'amount' => "CASE WHEN f.type = 'income' AND f.reversal_of_finance_transaction_id IS NOT NULL THEN -({$financeAmount}) ELSE {$financeAmount} END",
                'currency' => 'f.currency', 'category_key' => "COALESCE('expense:' || CAST(ec.id AS VARCHAR), 'other')",
                'business_source' => "CASE WHEN f.clinic_cash_gel IS NOT NULL THEN CASE WHEN f.clinic_cash_gel > 0 AND f.israeli_cash_gel > 0 THEN 'mixed' WHEN f.israeli_cash_gel > 0 THEN 'israeli' ELSE 'clinic' END WHEN f.funding_source = 'mixed' THEN NULL WHEN f.funding_source IN ('clinic','israeli') THEN f.funding_source WHEN f.cash_source = 'israeli' THEN 'israeli' WHEN f.cash_source IN ('current_cashier','withdrawn_cash') OR f.type = 'income' THEN 'clinic' ELSE NULL END",
                'category_name' => 'ec.name', 'payment_method' => 'f.payment_method', 'description' => 'COALESCE(f.description, f.note)',
                'subcategory_key' => "COALESCE('subcategory:' || CAST(es.id AS VARCHAR), 'none')", 'subcategory_name' => 'es.name',
                'expense_direction_id' => 'f.expense_direction_id', 'expense_type_id' => 'f.expense_type_id',
            ], $from, $until);
            $queries[] = $this->entry($this->expenseCategory(DB::table('partner_finance_transactions as f')->where('f.source', 'israeli')->where('f.type', 'expense')->whereNull('f.finance_transaction_id')), [
                'entry_key' => "'partner-expense:' || CAST(f.id AS VARCHAR)", 'entry_date' => 'f.transacted_at', 'origin' => "'partner_expense'",
                'metric' => "'expense'", 'business_source' => "'israeli'", 'amount' => 'f.amount', 'currency' => 'f.currency', 'category_key' => "COALESCE('expense:' || CAST(ec.id AS VARCHAR), 'other')",
                'category_name' => 'ec.name', 'counterparty' => 'f.recipient', 'payment_method' => "CASE WHEN f.from_account = 'cash' THEN 'cash' ELSE 'bank_transfer' END", 'description' => 'f.notes',
                'subcategory_key' => "COALESCE('subcategory:' || CAST(es.id AS VARCHAR), 'none')", 'subcategory_name' => 'es.name',
                'expense_direction_id' => 'f.expense_direction_id', 'expense_type_id' => 'f.expense_type_id',
            ], $from, $until);
        }
        if ($source !== 'cash') {
            $bank = DB::table('bank_transactions as b')->leftJoin('bank_categories as bc', 'bc.id', '=', 'b.bank_category_id')
                ->leftJoin('expense_categories as ec', 'ec.id', '=', DB::raw('COALESCE(b.expense_category_id, bc.expense_category_id)'))
                ->leftJoin('expense_subcategories as es', 'es.id', '=', 'b.expense_subcategory_id')->where('b.exclude_from_pnl', false)->where('b.is_legacy', false);
            // Business revenue is recorded in ERP; imported credits never create a second receipt.
            $queries[] = $this->entry((clone $bank)->whereRaw(BankAccounting::expenseSql('b')), [
                ...$this->bankFields(), 'metric' => "'expense'",
                'category_key' => "COALESCE('expense:' || CAST(ec.id AS VARCHAR), 'uncategorized')", 'category_name' => 'ec.name',
                'subcategory_key' => "COALESCE('subcategory:' || CAST(es.id AS VARCHAR), 'none')", 'subcategory_name' => 'es.name',
            ], $from, $until);
            $queries[] = $this->entry($bank->whereRaw(BankAccounting::embeddedFeeSql('b').' > 0')
                ->leftJoin('expense_categories as fees', 'fees.reporting_code', '=', DB::raw("'bank_fee'")), [
                    ...$this->bankFields(), 'entry_key' => "'bank-fee:' || CAST(b.id AS VARCHAR)", 'metric' => "'expense'", 'amount' => BankAccounting::embeddedFeeSql('b'),
                    'category_key' => "COALESCE('expense:' || CAST(fees.id AS VARCHAR), 'other')", 'category_name' => 'fees.name', 'origin' => "'withheld_fee'",
                    'expense_direction_id' => "(SELECT id FROM expense_categories WHERE classification_dimension = 'direction' AND classification_code = 'general')",
                    'expense_type_id' => "(SELECT id FROM expense_categories WHERE classification_dimension = 'type' AND classification_code = 'bank_fee' AND parent_id = (SELECT id FROM expense_categories WHERE classification_dimension = 'direction' AND classification_code = 'general'))",
                ], $from, $until);
        }

        $queries[] = $this->entry(DB::table('employee_advance_entries as e')
            ->join('employee_advances as a', 'a.id', '=', 'e.employee_advance_id')
            ->join('employees as employee', 'employee.id', '=', 'a.employee_id')
            ->leftJoin('expense_categories as ed', 'ed.id', '=', 'e.expense_direction_id')
            ->leftJoin('expense_categories as et', 'et.id', '=', 'e.expense_type_id')
            ->whereIn('e.kind', ['rs', 'manual'])
            ->when($source === 'bank', fn ($q) => $q->where('a.source', 'bank'))
            ->when($source === 'cash', fn ($q) => $q->whereIn('a.source', ['cashbox', 'accumulated_cash'])), [
                'entry_key' => "'advance-expense:' || CAST(e.id AS VARCHAR)", 'entry_date' => 'e.expense_date',
                'source' => "CASE WHEN a.source = 'bank' THEN 'bank' WHEN a.source = 'other' THEN 'other' ELSE 'cash' END", 'origin' => "'employee_advance'", 'metric' => "'expense'",
                'amount' => 'e.amount', 'currency' => 'a.currency', 'expense_direction_id' => 'e.expense_direction_id', 'expense_type_id' => 'e.expense_type_id',
                'category_key' => "COALESCE('expense:' || CAST(ed.id AS VARCHAR), 'uncategorized')", 'category_name' => 'ed.name',
                'subcategory_key' => "COALESCE('subcategory:' || CAST(et.id AS VARCHAR), 'none')", 'subcategory_name' => 'et.name',
                'counterparty' => "TRIM(employee.first_name || ' ' || employee.last_name)",
                'payment_method' => "CASE WHEN a.source = 'bank' THEN 'bank_transfer' WHEN a.source = 'other' THEN NULL ELSE 'cash' END", 'description' => 'e.description',
            ], $from, $until);

        return $this->union($queries)->when($businessSource !== 'all', fn ($q) => $q
            ->whereIn('business_source', [$businessSource, 'mixed'])->where('amount', '!=', 0));
    }

    public function movements(?string $from, ?string $until, string $source = 'all'): Builder
    {
        $this->validateRange($from, $until, $source);
        $queries = [];
        if ($source !== 'bank') {
            $queries = $this->erpReceipts($from, $until, true);
            $queries[] = $this->entry(DB::table('finance_transactions as f'), [
                'entry_key' => "'finance:' || CAST(f.id AS VARCHAR)", 'entry_date' => 'f.transaction_date', 'origin' => "'finance'",
                'metric' => "CASE WHEN f.type = 'expense' THEN 'outflow' ELSE 'inflow' END", 'amount' => 'f.amount', 'currency' => 'f.currency',
                'description' => 'COALESCE(f.description, f.note)', 'payment_method' => 'f.payment_method',
            ], $from, $until);
            // Never add the Cashier mirror of an ERP payment/expense a second time.
            $queries[] = $this->cashMovements($from, $until)->whereNull('c.payment_id')->whereNull('c.finance_transaction_id')->whereNull('c.product_sale_id');
            foreach (['outflow' => 'from', 'inflow' => 'to'] as $direction => $side) {
                $query = DB::table('partner_finance_transactions as f')->whereNull('f.finance_transaction_id');
                if ($direction === 'outflow') {
                    $query->where(fn ($q) => $q->where('f.type', 'expense')->orWhere(fn ($q) => $q->where('f.from_account', 'cash')->whereIn('f.type', ['transfer', 'owner_withdrawal', 'currency_exchange', 'employee_advance'])));
                } else {
                    $query->where('f.to_account', 'cash')->whereIn('f.type', ['transfer', 'currency_exchange', 'employee_advance']);
                }
                // Bank legs come from imported Bank records, not a second synthetic ERP bank credit.
                $queries[] = $this->entry($query, [
                    'entry_key' => "'partner-{$direction}:' || CAST(f.id AS VARCHAR)", 'entry_date' => 'f.transacted_at', 'origin' => 'f.type', 'metric' => "'{$direction}'",
                    'amount' => "CASE WHEN f.type = 'currency_exchange' THEN f.{$side}_amount ELSE f.amount END",
                    'currency' => "CASE WHEN f.type = 'currency_exchange' THEN f.{$side}_currency ELSE f.currency END", 'counterparty' => 'f.recipient', 'description' => 'f.notes',
                    'payment_method' => "CASE WHEN f.{$side}_account = 'cash' THEN 'cash' ELSE 'bank_transfer' END",
                    'is_transfer' => "CASE WHEN f.type IN ('transfer', 'currency_exchange', 'owner_withdrawal', 'employee_advance') THEN 1 ELSE 0 END",
                ], $from, $until);
            }
        }
        if ($source !== 'cash') {
            $queries[] = $this->entry(DB::table('bank_transactions as b')->leftJoin('bank_categories as bc', 'bc.id', '=', 'b.bank_category_id'), [
                ...$this->bankFields(), 'metric' => 'b.direction', 'category_name' => 'bc.name',
                'is_transfer' => "CASE WHEN bc.accounting_treatment IN ('transfer', 'settlement', 'exclude') THEN 1 ELSE 0 END",
            ], $from, $until);
        }

        if ($source === 'all') {
            $queries[] = $this->entry(DB::table('employee_advances as a')->where('a.source', 'other'), [
                'entry_key' => "'advance-other:' || CAST(a.id AS VARCHAR)", 'entry_date' => 'a.date', 'source' => "'other'",
                'origin' => "'employee_advance'", 'metric' => "'outflow'", 'amount' => 'a.amount', 'currency' => 'a.currency', 'description' => 'a.note', 'is_transfer' => '1',
            ], $from, $until);
            $queries[] = $this->entry(DB::table('employee_advance_entries as e')->join('employee_advances as a', 'a.id', '=', 'e.employee_advance_id')
                ->where('a.source', 'other')->where('e.kind', 'return'), [
                    'entry_key' => "'advance-return:' || CAST(e.id AS VARCHAR)", 'entry_date' => 'e.expense_date', 'source' => "'other'",
                    'origin' => "'employee_advance'", 'metric' => "'inflow'", 'amount' => 'e.amount', 'currency' => 'a.currency', 'description' => 'e.description', 'is_transfer' => '1',
                ], $from, $until);
        }

        return $this->union($queries);
    }

    public function cashMovements(?string $from, ?string $until): Builder
    {
        return $this->entry(DB::table('cashbox_transactions as c')->leftJoin('patients as patient', 'patient.id', '=', 'c.patient_id')
            ->whereIn('c.type', ['patient_payment', 'product_sale', 'other_income', 'expense', 'cash_withdrawal', 'cash_transfer_in', 'cash_transfer_out']), [
                'entry_key' => "'cashbox:' || CAST(c.id AS VARCHAR)", 'entry_date' => 'c.transaction_date', 'origin' => 'c.type',
                'metric' => "CASE WHEN c.type IN ('expense', 'cash_withdrawal', 'cash_transfer_out') THEN 'outflow' ELSE 'inflow' END",
                'amount' => 'c.amount', 'currency' => 'c.currency', 'counterparty' => $this->patientName(), 'description' => 'c.description', 'payment_method' => 'c.payment_method',
                'is_transfer' => "CASE WHEN c.type IN ('cash_withdrawal', 'cash_transfer_in', 'cash_transfer_out') THEN 1 ELSE 0 END",
            ], $from, $until);
    }

    public function pnlTotals(?string $from, ?string $until, string $source = 'all', string $currency = '', string $businessSource = 'all'): Collection
    {
        return $this->pnl($from, $until, $source, $businessSource)->when($currency !== '', fn ($q) => $q->where('currency', $currency))
            ->selectRaw("currency, SUM(CASE WHEN metric = 'revenue' THEN amount ELSE 0 END) AS revenue,
                SUM(CASE WHEN metric = 'expense' THEN amount ELSE 0 END) AS expenses,
                SUM(CASE WHEN metric = 'revenue' THEN amount ELSE -amount END) AS profit")
            ->groupBy('currency')->orderBy('currency')->get();
    }

    public function movementTotals(?string $from, ?string $until, string $source = 'all'): Collection
    {
        return $this->movements($from, $until, $source)->selectRaw("currency, SUM(CASE WHEN metric = 'inflow' THEN amount ELSE 0 END) AS inflow,
            SUM(CASE WHEN metric = 'outflow' THEN amount ELSE 0 END) AS outflow")->groupBy('currency')->orderBy('currency')->get();
    }

    public function expenseGroups(?string $from, ?string $until, string $source = 'all', string $currency = '', string $businessSource = 'all'): Collection
    {
        return $this->pnl($from, $until, $source, $businessSource)->where('metric', 'expense')->when($currency !== '', fn ($q) => $q->where('currency', $currency))
            ->selectRaw('category_key, category_name, currency, SUM(amount) AS amount, COUNT(*) AS entries_count')
            ->groupBy('category_key', 'category_name', 'currency')->orderBy('category_name')->orderBy('currency')->get();
    }

    /** Reorient the same P&L expense rows; neither postings nor amounts change. */
    public function dimensionEntries(?string $from, ?string $until, string $source = 'all', string $currency = '', string $businessSource = 'all', string $grouping = 'direction', ?int $direction = null, ?int $type = null): Builder
    {
        validator(compact('grouping'), ['grouping' => 'in:direction,type'])->validate();
        $primary = $grouping === 'direction' ? 'ed' : 'et';
        $secondary = $grouping === 'direction' ? 'et' : 'ed';
        $expenses = $this->pnl($from, $until, $source, $businessSource)->where('metric', 'expense');
        $rsDistributions = [];
        if ($businessSource !== 'israeli') {
            $rsDistributions['advance-expense:'] = app(PurchaseExpenseAllocation::class)->advanceDistribution();
            if ($source !== 'bank') {
                $rsDistributions['finance:'] = app(PurchaseExpenseAllocation::class)->cashDistribution();
            }
        }
        foreach ($rsDistributions as $prefix => $distribution) {
            $allocated = DB::query()->fromSub($expenses, 'original')
                ->leftJoinSub($distribution, 'rs_cash', fn ($join) => $join
                    ->whereRaw('original.entry_key = ? || CAST(rs_cash.entry_id AS VARCHAR)', [$prefix]));
            foreach (['entry_key', 'entry_date', 'source', 'business_source', 'origin', 'metric', 'currency', 'category_key', 'category_name',
                'subcategory_key', 'subcategory_name', 'counterparty', 'payment_method', 'description', 'is_transfer'] as $column) {
                $allocated->addSelect('original.'.$column);
            }
            $allocated->selectRaw('CASE WHEN rs_cash.entry_id IS NULL THEN original.amount WHEN original.amount < 0 THEN -rs_cash.amount ELSE rs_cash.amount END AS amount,
                CASE WHEN rs_cash.entry_id IS NULL THEN original.expense_direction_id ELSE rs_cash.expense_direction_id END AS expense_direction_id,
                CASE WHEN rs_cash.entry_id IS NULL THEN original.expense_type_id ELSE NULL END AS expense_type_id');
            $expenses = DB::query()->fromSub($allocated, 'cash_allocated_expenses');
        }
        // Replace only analytical classifications. P&L totals and bank movements retain one original entry.
        if ($source !== 'cash' && $businessSource === 'all') {
            $allocated = DB::query()->fromSub($expenses, 'original')
                ->leftJoinSub(app(BankPurchaseMatching::class)->distribution(), 'rs', fn ($join) => $join
                    ->whereRaw("original.entry_key = 'bank:' || CAST(rs.bank_transaction_id AS VARCHAR)"));
            foreach (['entry_key', 'entry_date', 'source', 'business_source', 'origin', 'metric', 'currency', 'category_key', 'category_name',
                'subcategory_key', 'subcategory_name', 'counterparty', 'payment_method', 'description', 'is_transfer'] as $column) {
                $allocated->addSelect('original.'.$column);
            }
            $allocated->selectRaw('CASE WHEN rs.bank_transaction_id IS NULL THEN original.amount ELSE rs.amount END AS amount,
            CASE WHEN rs.bank_transaction_id IS NULL THEN original.expense_direction_id ELSE rs.expense_direction_id END AS expense_direction_id,
            CASE WHEN rs.bank_transaction_id IS NULL THEN original.expense_type_id ELSE NULL END AS expense_type_id');
            $expenses = DB::query()->fromSub($allocated, 'allocated_expenses');
        }
        $base = $expenses
            ->when($currency !== '', fn ($q) => $q->where('currency', $currency))
            ->when($direction, fn ($q) => $q->where('expense_direction_id', $direction))
            ->when($type, fn ($q) => $q->whereIn('expense_type_id', app(ExpenseDimensions::class)->typeIdsForFilter($type)));
        $entries = DB::query()->fromSub($base, 'e')
            ->leftJoin('expense_categories as ed', 'ed.id', '=', 'e.expense_direction_id')
            ->leftJoin('expense_categories as et', 'et.id', '=', 'e.expense_type_id')
            ->select('e.*')->selectRaw(($grouping === 'type' ? "COALESCE(CAST((SELECT MIN(named_type.id) FROM expense_categories named_type WHERE named_type.classification_dimension = 'type' AND named_type.name = et.name) AS VARCHAR), 'review')" : "COALESCE(CAST(ed.id AS VARCHAR), 'review')")." AS dimension_group,
                COALESCE(CAST({$secondary}.id AS VARCHAR), 'review') AS dimension_subgroup,
                {$primary}.name AS dimension_name, {$secondary}.name AS dimension_subname,
                {$primary}.classification_code AS dimension_code, {$secondary}.classification_code AS dimension_subcode");

        return DB::query()->fromSub($entries, 'dimension_entries');
    }

    public function dimensionGroups(Builder $entries, ?string $parent = null): Collection
    {
        $group = $parent === null ? 'dimension_group' : 'dimension_subgroup';
        $name = $parent === null ? 'dimension_name' : 'dimension_subname';
        $code = $parent === null ? 'dimension_code' : 'dimension_subcode';

        return (clone $entries)->when($parent !== null, fn ($q) => $q->where('dimension_group', $parent))
            ->selectRaw("{$group} AS category_key, {$group} AS subcategory_key, {$name} AS category_name,
                {$name} AS subcategory_name, MIN({$code}) AS dimension_code, currency, SUM(amount) AS amount, COUNT(*) AS entries_count")
            ->groupBy($group, $name, 'currency')->orderBy($name)->orderBy('currency')->get();
    }

    public function expenseSubgroups(?string $from, ?string $until, string $source, string $currency, string $category, string $businessSource = 'all'): Collection
    {
        return $this->pnl($from, $until, $source, $businessSource)->where('metric', 'expense')->where('category_key', $category)
            ->when($currency !== '', fn ($q) => $q->where('currency', $currency))
            ->selectRaw('subcategory_key, subcategory_name, currency, SUM(amount) AS amount, COUNT(*) AS entries_count')
            ->groupBy('subcategory_key', 'subcategory_name', 'currency')->orderBy('subcategory_name')->orderBy('currency')->get();
    }

    private function erpReceipts(?string $from, ?string $until, bool $movement = false): array
    {
        $metric = $movement ? "'inflow'" : "'revenue'";
        $queries = [$this->entry(DB::table('payment_splits as ps')->join('payments as p', 'p.id', '=', 'ps.payment_id')
            ->leftJoin('visits as v', 'v.id', '=', 'p.visit_id')->leftJoin('patients as patient', 'patient.id', '=', 'v.patient_id')->whereNull('p.deleted_at'), [
                'entry_key' => "'payment-split:' || CAST(ps.id AS VARCHAR)", 'entry_date' => 'p.payment_date', 'origin' => "'patient_payment'", 'metric' => $metric,
                'amount' => 'ps.amount', 'currency' => 'ps.currency', 'counterparty' => $this->patientName(), 'payment_method' => 'ps.payment_method', 'description' => 'p.comment',
            ], $from, $until)];
        $queries[] = $this->entry(DB::table('partner_patient_payments as p')->leftJoin('patients as patient', 'patient.id', '=', 'p.patient_id'), [
            'entry_key' => "'partner-payment:' || CAST(p.id AS VARCHAR)", 'entry_date' => 'p.paid_at', 'origin' => "'partner_payment'", 'metric' => $metric,
            'business_source' => "'israeli'",
            'amount' => 'p.amount', 'currency' => 'p.currency', 'counterparty' => $this->patientName(), 'payment_method' => 'p.payment_method', 'description' => 'p.notes',
        ], $from, $until);
        $sales = DB::table('product_sales as s')->leftJoin('patients as patient', 'patient.id', '=', 's.patient_id');
        if ($movement) {
            $queries[] = $this->cashMovements($from, $until)->whereNotNull('c.product_sale_id');
            $sales->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cashbox_transactions as c')->whereColumn('c.product_sale_id', 's.id'));
        }
        $queries[] = $this->entry($sales, [
            'entry_key' => "'sale:' || CAST(s.id AS VARCHAR)", 'entry_date' => 's.sold_at', 'origin' => "'product_sale'", 'metric' => $metric,
            'amount' => 's.total', 'currency' => 's.currency', 'counterparty' => $this->patientName(), 'payment_method' => 's.payment_method', 'description' => 's.note',
        ], $from, $until);

        return $queries;
    }

    private function bankFields(): array
    {
        return ['entry_key' => "'bank:' || CAST(b.id AS VARCHAR)", 'entry_date' => 'b.transaction_date', 'source' => "'bank'", 'business_source' => 'CAST(NULL AS VARCHAR)', 'origin' => "'bank'",
            'expense_direction_id' => 'b.expense_direction_id', 'expense_type_id' => 'b.expense_type_id',
            'amount' => 'b.amount', 'currency' => 'b.currency', 'counterparty' => 'COALESCE(b.counterparty_name, b.counterparty_account)', 'payment_method' => "'bank_transfer'", 'description' => 'b.description'];
    }

    private function expenseCategory(Builder $query, bool $cashbox = false): Builder
    {
        $legacy = "CASE WHEN f.category IN ('materials','supplier') THEN 'materials' WHEN f.category IN ('salary','doctor_salary','lab_salary','technician') THEN 'salary'
            WHEN f.category = 'bank_fees' THEN 'bank_fee' WHEN f.category IN ('rent','utilities','taxes','equipment','payroll_tax') THEN f.category ELSE 'operating_expense' END";

        if ($cashbox) {
            $legacy = str_replace('f.category', 'f.expense_category', $legacy);

            return $query->leftJoin('expense_categories as ec', fn ($join) => $join->whereRaw("ec.reporting_code = {$legacy}"));
        }

        return $query->leftJoin('expense_categories as ec', fn ($join) => $join->on('ec.id', '=', 'f.expense_category_id')
            ->orWhere(fn ($q) => $q->whereNull('f.expense_category_id')->whereRaw("ec.reporting_code = {$legacy}")))
            ->leftJoin('expense_subcategories as es', 'es.id', '=', 'f.expense_subcategory_id');
    }

    private function entry(Builder $query, array $columns, ?string $from, ?string $until): Builder
    {
        $columns = array_replace(['entry_key' => "''", 'entry_date' => 'NULL', 'source' => "'cash'", 'business_source' => "'clinic'", 'origin' => "''", 'metric' => "''", 'amount' => '0', 'currency' => "'GEL'",
            'expense_direction_id' => 'CAST(NULL AS BIGINT)', 'expense_type_id' => 'CAST(NULL AS BIGINT)',
            'category_key' => "'other'", 'category_name' => 'CAST(NULL AS VARCHAR)', 'subcategory_key' => "'none'", 'subcategory_name' => 'CAST(NULL AS VARCHAR)', 'counterparty' => 'CAST(NULL AS VARCHAR)', 'payment_method' => 'CAST(NULL AS VARCHAR)',
            'description' => 'CAST(NULL AS VARCHAR)', 'is_transfer' => '0'], $columns);
        $date = $columns['entry_date'];

        return $query->selectRaw(collect($columns)->map(fn ($sql, $name) => "{$sql} AS {$name}")->implode(', '))
            ->when(filled($from), fn ($q) => $q->where($date, '>=', $from))
            ->when(filled($until), fn ($q) => $q->where($date, '<', CarbonImmutable::parse($until)->addDay()->toDateString()));
    }

    private function union(array $queries): Builder
    {
        $union = array_shift($queries);
        foreach ($queries as $query) {
            $union->unionAll($query);
        }

        return DB::query()->fromSub($union, 'ledger');
    }

    private function patientName(): string
    {
        return "TRIM(COALESCE(patient.first_name, '') || ' ' || COALESCE(patient.last_name, ''))";
    }

    private function validateRange(?string $from, ?string $until, string $source): void
    {
        validator(compact('from', 'until', 'source'), ['from' => 'nullable|date_format:Y-m-d', 'until' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'source' => 'in:all,cash,bank'])->validate();
    }
}
