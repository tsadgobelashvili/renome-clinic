<?php

namespace App\Services\Finance;

use App\Services\PurchaseExpenseAllocation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Adds presentation fields only. Every join targets a primary key; ledger amounts stay untouched. */
final class EntryDetails
{
    public function query(Builder $entries, bool $rsClassification = false): Builder
    {
        // Extract the ledger key once on the outer row, retaining indexed primary-key lookups.
        $id = fn (array $prefixes): string => 'CASE '.implode(' ', array_map(
            fn ($prefix) => "WHEN e.entry_key LIKE '{$prefix}:%' THEN CAST(SUBSTR(e.entry_key, ".(strlen($prefix) + 2).') AS BIGINT)', $prefixes,
        )).' END';
        $match = fn (string $alias, array $prefixes): string => "{$alias}.id = ".$id($prefixes);
        $category = "CASE WHEN dbank.classification_source = 'repair' THEN NULL ELSE COALESCE(direction.name, dcat.name, df.category, dt.category, dc.expense_category) END";
        $subcategory = "CASE WHEN dbank.classification_source = 'repair' THEN NULL ELSE COALESCE(subtype.name, dsub.name) END";
        if ($rsClassification) {
            // The Direction drilldown already resolved current RS catalog labels.
            // Do not replace them with the bank's unrelated manual classification.
            $category = "CASE WHEN e.rs_classified = 1 THEN e.dimension_name ELSE {$category} END";
            $subcategory = "CASE WHEN e.rs_classified = 1 THEN e.dimension_subname ELSE {$subcategory} END";
        }
        $query = DB::query()->fromSub($entries, 'e')
            ->leftJoin('cashbox_transactions as dc', fn ($join) => $join->whereRaw($match('dc', ['cashbox', 'cashbox-expense'])))
            ->leftJoin('payment_splits as ds', fn ($join) => $join->whereRaw($match('ds', ['payment-split', 'historical-split'])))
            ->leftJoin('payments as dp', fn ($join) => $join->on('dp.id', '=', DB::raw('COALESCE(ds.payment_id, dc.payment_id)')))
            ->leftJoin('visits as dv', 'dv.id', '=', 'dp.visit_id')
            ->leftJoin('finance_transactions as df', fn ($join) => $join->whereRaw('df.id = COALESCE('.$id(['finance', 'finance-cash', 'finance-held-clinic']).', dc.finance_transaction_id)'))
            ->leftJoin('partner_finance_transactions as dt', fn ($join) => $join->whereRaw($match('dt', ['partner-expense', 'partner-inflow', 'partner-outflow'])))
            ->leftJoin('partner_patient_payments as dr', fn ($join) => $join->whereRaw($match('dr', ['partner-payment'])))
            ->leftJoin('product_sales as sale', fn ($join) => $join->whereRaw('sale.id = COALESCE('.$id(['sale']).', dc.product_sale_id)'))
            ->leftJoin('patients as patient', 'patient.id', '=', DB::raw('COALESCE(dv.patient_id, dr.patient_id, dc.patient_id, sale.patient_id)'))
            ->leftJoin('bank_transactions as dbank', fn ($join) => $join->whereRaw($match('dbank', ['bank', 'bank-fee'])))
            ->leftJoin('expense_categories as dcat', 'dcat.id', '=', DB::raw('COALESCE(df.expense_category_id, dt.expense_category_id, dbank.expense_category_id)'))
            ->leftJoin('expense_subcategories as dsub', 'dsub.id', '=', DB::raw('COALESCE(df.expense_subcategory_id, dt.expense_subcategory_id, dbank.expense_subcategory_id)'))
            ->leftJoin('expense_categories as direction', 'direction.id', '=', DB::raw('COALESCE(df.expense_direction_id, dt.expense_direction_id, dbank.expense_direction_id)'))
            ->leftJoin('expense_categories as subtype', 'subtype.id', '=', DB::raw('COALESCE(df.expense_type_id, dt.expense_type_id, dbank.expense_type_id)'))
            ->select('e.*')
            ->selectRaw("CASE WHEN dp.id IS NOT NULL THEN 'payment:' || CAST(dp.id AS VARCHAR)
                WHEN dr.id IS NOT NULL THEN 'partner-payment:' || CAST(dr.id AS VARCHAR)
                WHEN df.id IS NOT NULL THEN 'finance:' || CAST(df.id AS VARCHAR)
                WHEN dt.id IS NOT NULL THEN 'partner:' || CAST(dt.id AS VARCHAR)
                ELSE e.entry_key END AS detail_key,
                patient.id AS detail_patient_id, TRIM(patient.first_name || ' ' || patient.last_name) AS detail_patient,
                COALESCE(dt.recipient, dbank.counterparty_name, df.description) AS detail_recipient,
                COALESCE(dt.source, CASE WHEN dr.id IS NOT NULL THEN 'israeli' END, e.business_source) AS detail_source,
                {$category} AS detail_category,
                {$subcategory} AS detail_subcategory,
                dbank.reference AS detail_bank_reference,
                CASE WHEN dbank.id IS NULL THEN NULL
                    WHEN dbank.classification_source = 'manual' THEN 'manual'
                    WHEN dbank.classification_source = 'rule' THEN 'rule'
                    WHEN dbank.classification_source = 'default' THEN 'system'
                    WHEN dbank.classification_source = 'repair' THEN 'none'
                    WHEN dbank.expense_direction_id IS NULL AND dbank.expense_type_id IS NULL AND dbank.expense_category_id IS NULL THEN 'none'
                    ELSE 'unknown' END AS detail_bank_classification,
                COALESCE(dp.comment, dr.notes, dt.notes, df.note, sale.note, dbank.description, dc.description, e.description) AS detail_notes,
                COALESCE(df.cash_source, dt.from_account, dbank.account_identifier, dp.payment_method, dr.payment_method, CASE WHEN dc.type = 'irrigator_withdrawal' THEN COALESCE(dc.cash_usage_source, 'current_cashier') ELSE dc.payment_method END) AS detail_account,
                dt.to_account AS detail_to_account, COALESCE(dt.type, df.type, e.origin) AS detail_type,
                dt.from_amount AS detail_from_amount, dt.from_currency AS detail_from_currency,
                dt.to_amount AS detail_to_amount, dt.to_currency AS detail_to_currency, dt.exchange_rate AS detail_rate");

        return DB::query()->fromSub($query, 'detailed_entries');
    }

    /** Enrich only the visible Direction drilldown page; never join references onto money rows. */
    public function withRsInvoices(Collection $entries): Collection
    {
        $bankId = fn ($row) => ($row->rs_classified ?? 0) == 1 && preg_match('/^bank:([0-9]+)$/D', $row->entry_key ?? '', $match)
            ? (int) $match[1] : null;
        $ids = $entries->map($bankId)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return $entries;
        }
        $payments = DB::table('bank_purchase_matches')->whereIn('bank_transaction_id', $ids)
            ->select('id', 'bank_transaction_id as entry_id', 'purchase_id', 'amount');
        $shares = app(PurchaseExpenseAllocation::class)->shares($payments, catalog: true);
        $key = fn ($bank, $direction, $group) => implode(':', [$bank, $direction ?? 'review', $group ?? 'review']);
        $invoices = DB::query()->fromSub($shares, 's')->join('purchases as p', 'p.id', '=', 's.purchase_id')
            ->where('p.source', 'rs')->where('s.amount', '>', 0)
            ->select('s.entry_id', 's.expense_direction_id', 's.purchase_product_group_id', 'p.id', 'p.document_number')
            ->distinct()->orderBy('p.id')->get()
            ->groupBy(fn ($row) => $key($row->entry_id, $row->expense_direction_id, $row->purchase_product_group_id));
        foreach ($entries as $entry) {
            $id = $bankId($entry);
            if ($id === null) {
                continue;
            }
            $entry->detail_rs_invoices = $invoices->get($key($id, $entry->expense_direction_id, $entry->purchase_product_group_id), collect())
                ->map(fn ($row) => ['id' => (int) $row->id, 'number' => filled($row->document_number) ? trim($row->document_number) : null])
                ->unique(fn ($row) => $row['number'] === null ? 'id:'.$row['id'] : 'number:'.$row['number'])->values()->all();
        }

        return $entries;
    }
}
