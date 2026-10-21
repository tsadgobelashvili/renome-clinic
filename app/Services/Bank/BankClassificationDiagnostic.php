<?php

namespace App\Services\Bank;

use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\EntryDetails;
use Illuminate\Support\Facades\DB;

/** Read-only comparison of stored Bank facts with the actual Finance query paths. */
class BankClassificationDiagnostic
{
    public function trace(array $ids = [], array $amounts = [], ?string $date = null): array
    {
        $ledger = app(AccountingLedger::class);
        $classifier = app(BankClassificationService::class);
        $context = $classifier->context();
        $categories = app(ExpenseDimensions::class)->registry();
        $legacyChildren = DB::table('expense_subcategories')->get()->keyBy('id');
        $bankCategories = DB::table('bank_categories')->get()->keyBy('id');
        $storedRules = DB::table('bank_categorization_rules')->get()->keyBy('id');
        $records = [];
        DB::table('bank_transactions')->where('direction', 'outflow')
            ->when($ids, fn ($q) => $q->whereIn('id', $ids))
            ->when($amounts, fn ($q) => $q->whereIn('amount', $amounts))
            ->when($date, fn ($q) => $q->whereDate('transaction_date', $date))
            ->orderBy('id')->chunkById(250, function ($rows) use (&$records, $ledger, $classifier, $context, $categories, $legacyChildren, $bankCategories, $storedRules) {
                $keys = $rows->map(fn ($row) => 'bank:'.$row->id)->all();
                $details = app(EntryDetails::class)->query($ledger->movements(null, null, 'bank'))
                    ->whereIn('entry_key', $keys)->get()->keyBy('entry_key');
                $projections = $ledger->dimensionEntries(null, null, source: 'bank')->whereIn('entry_key', $keys)->get()->groupBy('entry_key');
                $expenseDetails = app(EntryDetails::class)->query($ledger->dimensionEntries(null, null, source: 'bank'))
                    ->whereIn('entry_key', $keys)->get()->groupBy('entry_key');
                foreach ($rows as $row) {
                    $detail = $details->get('bank:'.$row->id);
                    $category = $bankCategories->get($row->bank_category_id);
                    $rule = app(BankRuleMatcher::class)->match((array) $row, $context['rules']);
                    $projected = $projections->get('bank:'.$row->id, collect());
                    $direction = $categories->get($row->expense_direction_id)?->name;
                    $type = $categories->get($row->expense_type_id)?->name;
                    $legacy = $categories->get($row->expense_category_id)?->name;
                    $legacyChild = $legacyChildren->get($row->expense_subcategory_id)?->name;
                    $suspect = ($direction === 'სტერილიზაცია' && $type === 'დასუფთავება')
                        || ($detail?->detail_category === 'სტერილიზაცია' && $detail?->detail_subcategory === 'დასუფთავება')
                        || $projected->contains(fn ($p) => $p->dimension_name === 'სტერილიზაცია' && $p->dimension_subname === 'დასუფთავება');
                    $expenseDisplay = $expenseDetails->get('bank:'.$row->id, collect());
                    $suspect = $suspect || $expenseDisplay->contains(fn ($p) => ($p->detail_category ?? $p->category_name) === 'სტერილიზაცია'
                        && $p->detail_subcategory === 'დასუფთავება');
                    $origin = match ($row->classification_source) {
                        'manual' => 'manual', 'rule' => 'vendor_rule', 'default' => 'system_default', 'repair' => 'repaired_unclassified',
                        default => $row->expense_direction_id || $row->expense_type_id || $row->expense_category_id || $row->expense_subcategory_id
                            ? 'historical_persisted_value_origin_unproven' : 'no_classification',
                    };
                    $rsMatches = DB::table('bank_purchase_matches')->where('bank_transaction_id', $row->id)->count();
                    $displayPath = match (true) {
                        $row->classification_source === 'repair' => 'repaired_unclassified',
                        $row->expense_direction_id !== null || $row->expense_type_id !== null => 'stored_bank_dimensions',
                        $row->expense_category_id !== null || $row->expense_subcategory_id !== null => 'stored_legacy_links',
                        $rsMatches > 0 => 'confirmed_rs_allocation',
                        $category?->expense_category_id !== null => 'shared_bank_category_link',
                        $detail?->detail_category !== null || $detail?->detail_subcategory !== null => 'projection_only_requires_investigation',
                        default => 'unclassified',
                    };
                    $records[] = ['id' => $row->id, 'date' => $row->transaction_date, 'vendor' => $row->counterparty_name,
                        'amount' => $row->amount, 'currency' => $row->currency, 'source' => $row->source,
                        'bank_operation_type' => $row->operation_type, 'suspect_pair' => $suspect,
                        'stored_ids' => ['expense_direction_id' => $row->expense_direction_id, 'expense_type_id' => $row->expense_type_id,
                            'expense_category_id' => $row->expense_category_id, 'expense_subcategory_id' => $row->expense_subcategory_id],
                        'stored_labels' => ['direction' => $direction, 'classification' => $type, 'legacy_category' => $legacy, 'legacy_subcategory' => $legacyChild],
                        'registry_records' => ['direction' => $categories->get($row->expense_direction_id)?->getAttributes(),
                            'type' => $categories->get($row->expense_type_id)?->getAttributes(),
                            'legacy_category' => $categories->get($row->expense_category_id)?->getAttributes()],
                        'classification_source' => $row->classification_source, 'origin' => $origin, 'display_path' => $displayPath,
                        'stored_rule_id' => $row->categorization_rule_id, 'stored_rule' => $storedRules->get($row->categorization_rule_id),
                        'current_matching_rule_id' => $rule?->id,
                        'classification_if_imported_today' => $classifier->classify((array) $row, $context),
                        'shared_bank_category' => ['id' => $category?->id, 'code' => $category?->code,
                            'expense_category_id' => $category?->expense_category_id,
                            'label' => $categories->get($category?->expense_category_id)?->name],
                        'finance_display' => ['direction' => $detail?->detail_category ?? __('expense-dimensions.review'),
                            'classification' => $detail?->detail_subcategory ?? __('expense-dimensions.review')],
                        'finance_history_category' => $detail?->detail_category ?? $detail?->category_name,
                        'finance_expense_display' => $expenseDisplay->map(fn ($p) => [
                            'direction' => $p->detail_category ?? $p->category_name ?? __('expense-dimensions.review'),
                            'classification' => $p->detail_subcategory ?? __('expense-dimensions.review'),
                        ])->all(),
                        'finance_analysis' => $projected->map(fn ($p) => array_intersect_key((array) $p,
                            array_flip(['expense_direction_id', 'expense_type_id', 'dimension_name', 'dimension_subname', 'amount'])))->all(),
                        'confirmed_rs_matches' => $rsMatches,
                        'snapshot_sha256' => BankClassificationRepair::snapshot($row), 'created_at' => $row->created_at, 'updated_at' => $row->updated_at];
                }
            });

        return ['lookup_check' => ['null_direction_id' => app(ExpenseDimensions::class)->id('direction', null),
            'null_type_id' => app(ExpenseDimensions::class)->id('type', null),
            'system_fee_ids' => $context['fee_dimensions']],
            'bank_records' => DB::table('bank_transactions')->count(), 'scanned_outflows' => count($records),
            'suspect_pair_count' => count(array_filter($records, fn ($row) => $row['suspect_pair'])),
            'records' => $records, 'pnl' => $ledger->pnlTotals(null, null), 'movements' => $ledger->movementTotals(null, null)];
    }
}
