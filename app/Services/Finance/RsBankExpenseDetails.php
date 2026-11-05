<?php

namespace App\Services\Finance;

use App\Models\BankTransaction;
use App\Models\Purchase;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\PurchaseAnalysis;
use App\Services\PurchaseAnalysisMapping;
use App\Services\PurchaseExpenseAllocation;
use Illuminate\Support\Facades\DB;

/** Read-only invoice evidence for one live Finance Direction/Product Group portion. */
final class RsBankExpenseDetails
{
    public function read(array $arguments): array
    {
        abort_unless(auth()->user()?->canManageOwnerModules(), 403);
        $data = validator($arguments, ['bank_id' => 'required|integer|min:1', 'direction_id' => 'nullable|integer|min:1', 'group_id' => 'nullable|integer|min:1'])->validate();
        $direction = isset($data['direction_id']) ? (int) $data['direction_id'] : null;
        $group = isset($data['group_id']) ? (int) $data['group_id'] : null;
        $bank = BankTransaction::findOrFail($data['bank_id']);
        $expense = app(AccountingLedger::class)->dimensionEntries(null, null, source: 'bank')
            ->where('entry_key', 'bank:'.$bank->id)->where('rs_classified', 1)->where('amount', '>', 0)
            ->where('expense_direction_id', $direction)->where('purchase_product_group_id', $group)->first();
        abort_unless($expense, 404);

        $payments = DB::table('bank_purchase_matches')->where('bank_transaction_id', $bank->id)
            ->select('id', 'bank_transaction_id as entry_id', 'purchase_id', 'amount');
        $allocation = app(PurchaseExpenseAllocation::class)->shares($payments, catalog: true);
        $shares = DB::query()->fromSub($allocation, 's')
            ->where('expense_direction_id', $direction)->where('purchase_product_group_id', $group)->where('amount', '>', 0)->get();
        $money = app(BankPurchaseMatching::class);
        $portions = $shares->groupBy('purchase_id')->map(fn ($rows) => $rows->sum(fn ($row) => $money->cents($row->amount)));
        $purchases = Purchase::whereIn('id', $portions->keys())->where('source', 'rs')->with('supplier')->orderBy('purchase_date')->orderBy('id')->get();
        abort_if($purchases->isEmpty(), 404);
        $bankAmounts = DB::table('bank_purchase_matches')->where('bank_transaction_id', $bank->id)->whereIn('purchase_id', $purchases->modelKeys())->pluck('amount', 'purchase_id');

        // Reuse the same canonical/legacy identities and effective Direction as RS analysis.
        // No eligibility filter here: the modal shows the full original linked invoice.
        $defaults = DB::table('purchase_products')->whereNotNull('purchase_product_group_id')->whereNotNull('expense_direction_id')
            ->groupBy('purchase_product_group_id')->havingRaw('COUNT(DISTINCT expense_direction_id) = 1')
            ->selectRaw('purchase_product_group_id, MIN(expense_direction_id) AS direction_id');
        $expression = app(PurchaseAnalysis::class)->directionExpression();
        $items = DB::query()->fromSub(app(PurchaseAnalysisMapping::class)->items(), 'purchase_items')
            ->leftJoin('purchase_products as product', 'product.id', '=', 'purchase_items.resolved_product_id')
            ->leftJoin('purchase_product_groups as product_group', 'product_group.id', '=', 'product.purchase_product_group_id')
            ->leftJoinSub($defaults, 'group_direction', 'group_direction.purchase_product_group_id', '=', 'product.purchase_product_group_id')
            ->leftJoin('expense_categories as direction', fn ($join) => $join->on('direction.id', '=', DB::raw($expression))
                ->where('direction.classification_dimension', 'direction')->whereNull('direction.parent_id'))
            ->leftJoin('products as retail', 'retail.id', '=', 'purchase_items.product_id')
            ->whereIn('purchase_items.purchase_id', $purchases->modelKeys())->orderBy('purchase_items.id')
            ->select('purchase_items.*', 'product.name as catalog_name', 'retail.name as legacy_name', 'product.purchase_product_group_id', 'product_group.name as group_name', 'direction.id as direction_id', 'direction.name as direction_name')
            ->get()->groupBy('purchase_id');
        $documents = $purchases->map(function ($purchase) use ($items, $shares, $portions, $bankAmounts, $money, $direction, $group) {
            $contributingProducts = $shares->where('purchase_id', $purchase->id)->pluck('purchase_product_id')->all();
            $lines = $items->get($purchase->id, collect())->map(function ($item) use ($contributingProducts, $money, $direction, $group) {
                $item->contributes = ($item->direction_id === null ? null : (int) $item->direction_id) === $direction
                    && ($item->purchase_product_group_id === null ? null : (int) $item->purchase_product_group_id) === $group
                    && in_array($item->resolved_product_id, $contributingProducts, true) && $money->cents($item->line_total) > 0;

                return $item;
            });

            return ['id' => $purchase->id, 'number' => $purchase->document_number, 'date' => $purchase->purchase_date,
                'supplier' => $purchase->supplier?->name, 'items' => $lines,
                'total_tetri' => $lines->sum(fn ($line) => $money->cents($line->line_total)),
                'bank_tetri' => $money->cents($bankAmounts[$purchase->id]), 'group_tetri' => $portions[$purchase->id]];
        });

        return ['bank' => $bank, 'expense' => $expense, 'documents' => $documents,
            'allocatedTetri' => $documents->sum('group_tetri'), 'invoiceTetri' => $documents->sum('total_tetri'),
            'bankAllocatedTetri' => $documents->sum('bank_tetri')];
    }
}
