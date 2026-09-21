<?php

namespace App\Services;

use App\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/** Purchasing analysis only. Payment links filter invoices; they never supply amounts. */
class PurchaseAnalysis
{
    public function lines(array $filters = []): Builder
    {
        return PurchaseItem::query()
            ->join('purchases as document', 'document.id', '=', 'purchase_items.purchase_id')
            ->leftJoin('purchase_products as product', 'product.id', '=', 'purchase_items.purchase_product_id')
            ->leftJoin('purchase_product_groups as product_group', 'product_group.id', '=', 'product.purchase_product_group_id')
            ->leftJoin('suppliers as supplier', 'supplier.id', '=', 'document.supplier_id')
            ->where('document.source', 'rs')
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('document.purchase_date', '>=', Carbon::parse($date)->toDateString()))
            ->when($filters['until'] ?? null, fn ($q, $date) => $q->whereDate('document.purchase_date', '<=', Carbon::parse($date)->toDateString()))
            ->when($filters['direction'] ?? null, fn ($q, $id) => $q->where('product.expense_direction_id', $id))
            ->when($filters['group'] ?? null, fn ($q, $id) => $q->where('product.purchase_product_group_id', $id))
            ->when($filters['product'] ?? null, fn ($q, $id) => $q->where('purchase_items.purchase_product_id', $id))
            ->when($filters['supplier'] ?? null, fn ($q, $id) => $q->where('document.supplier_id', $id))
            ->when(($filters['payment'] ?? 'all') !== 'all', fn ($q) => $q->whereHas('purchase', function ($purchase) use ($filters) {
                match ($filters['payment']) {
                    'bank' => $purchase->whereHas('bankTransactions'),
                    'cash' => $purchase->whereHas('cashExpense'),
                    'unlinked' => $purchase->whereDoesntHave('bankTransactions')->whereDoesntHave('cashExpense'),
                    default => $purchase->whereRaw('1 = 0'),
                };
            }));
    }

    public function groups(array $filters = []): Builder
    {
        return $this->lines($filters)->selectRaw('MIN(purchase_items.id) as id, product.purchase_product_group_id as group_id, product_group.name as name,
                SUM(purchase_items.quantity) as purchased_quantity, SUM(purchase_items.line_total) as purchase_amount,
                COUNT(DISTINCT purchase_items.unit) as unit_count, MIN(purchase_items.unit) as unit')
            ->groupBy('product.purchase_product_group_id', 'product_group.name');
    }

    public function products(array $filters, string $group): Builder
    {
        $query = $this->withinGroup($this->lines($filters), $group);

        // Latest means the latest filtered invoice line, breaking same-day ties by line ID.
        $latest = $this->withinGroup($this->lines($filters), $group)
            ->selectRaw('purchase_items.purchase_product_id as product_id, purchase_items.unit_price,
                ROW_NUMBER() OVER (PARTITION BY purchase_items.purchase_product_id ORDER BY document.purchase_date DESC, purchase_items.id DESC) as position');

        return $query->leftJoinSub($latest, 'latest_price', fn ($join) => $join
            ->on('latest_price.product_id', '=', 'purchase_items.purchase_product_id')->where('latest_price.position', 1))
            ->selectRaw('MIN(purchase_items.id) as id, purchase_items.purchase_product_id as product_id, product.name as name,
                SUM(purchase_items.quantity) as purchased_quantity, SUM(purchase_items.line_total) as purchase_amount,
                SUM(purchase_items.line_total) * 1.0 / NULLIF(SUM(purchase_items.quantity), 0) as average_price,
                MAX(latest_price.unit_price) as latest_price,
                COUNT(DISTINCT purchase_items.unit) as unit_count, MIN(purchase_items.unit) as unit')
            ->groupBy('purchase_items.purchase_product_id', 'product.name');
    }

    public function history(array $filters, string $group, string $product): Builder
    {
        return $this->withinGroup($this->lines($filters), $group)
            ->when($product === 'unmapped', fn ($q) => $q->whereNull('purchase_items.purchase_product_id'),
                fn ($q) => $q->where('purchase_items.purchase_product_id', $product))
            ->select('purchase_items.*', 'document.purchase_date', 'document.document_number', 'supplier.name as supplier_name');
    }

    private function withinGroup(Builder $query, string $group): Builder
    {
        return $group === 'ungrouped' ? $query->whereNull('product.purchase_product_group_id')
            : $query->where('product.purchase_product_group_id', $group);
    }
}
