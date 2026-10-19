<?php

namespace App\Services;

use App\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Purchasing analysis only. Payment links filter invoices; they never supply amounts. */
class PurchaseAnalysis
{
    public const DIRECTION = 'COALESCE(product.expense_direction_id, product_group.expense_direction_id, group_direction.direction_id, purchase_items.legacy_direction_id)';

    public function directionExpression(): string
    {
        return app(PurchaseAnalysisMapping::class)->capabilities()['group_direction'] ? self::DIRECTION
            : str_replace('product_group.expense_direction_id', 'NULL', self::DIRECTION);
    }

    public function lines(array $filters = []): Builder
    {
        $groupDirections = DB::table('purchase_products')->whereNotNull('purchase_product_group_id')->whereNotNull('expense_direction_id')
            ->groupBy('purchase_product_group_id')->havingRaw('COUNT(DISTINCT expense_direction_id) = 1')
            ->selectRaw('purchase_product_group_id, MIN(expense_direction_id) as direction_id');

        return PurchaseItem::query()->fromSub(app(PurchaseAnalysisMapping::class)->items(), 'purchase_items')
            ->join('purchases as document', 'document.id', '=', 'purchase_items.purchase_id')
            ->leftJoin('purchase_products as product', 'product.id', '=', 'purchase_items.resolved_product_id')
            ->leftJoin('purchase_product_groups as product_group', 'product_group.id', '=', 'product.purchase_product_group_id')
            ->leftJoinSub($groupDirections, 'group_direction', 'group_direction.purchase_product_group_id', '=', 'product.purchase_product_group_id')
            ->leftJoin('suppliers as supplier', 'supplier.id', '=', 'document.supplier_id')
            ->where('document.source', 'rs')
            ->whereIn('document.id', app(PurchaseAnalysisEligibility::class)->documents())
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('document.purchase_date', '>=', Carbon::parse($date)->toDateString()))
            ->when($filters['until'] ?? null, fn ($q, $date) => $q->whereDate('document.purchase_date', '<=', Carbon::parse($date)->toDateString()))
            ->when($filters['direction'] ?? null, fn ($q, $id) => $q->whereRaw($this->directionExpression().' = ?', [(int) $id]))
            ->when($filters['group'] ?? null, fn ($q, $id) => $q->where('product.purchase_product_group_id', $id))
            ->when($filters['product'] ?? null, fn ($q, $id) => $q->where('purchase_items.resolved_product_id', (int) $id))
            ->when($filters['supplier'] ?? null, fn ($q, $id) => $q->where('document.supplier_id', $id))
            ->when(($filters['payment'] ?? 'all') !== 'all', fn ($q) => $q->whereHas('purchase', function ($purchase) use ($filters) {
                match ($filters['payment']) {
                    'bank' => $purchase->whereHas('bankTransactions'),
                    'cash' => $purchase->whereHas('cashExpense'),
                    'advance' => $purchase->whereHas('advanceSettlement'),
                    'unlinked' => $purchase->whereDoesntHave('bankTransactions')->whereDoesntHave('cashExpense')->whereDoesntHave('advanceSettlement'),
                    default => $purchase->whereRaw('1 = 0'),
                };
            }));
    }

    public function directions(array $filters = []): Builder
    {
        return $this->lines($filters)
            ->leftJoin('expense_categories as direction', 'direction.id', '=', DB::raw($this->directionExpression()))
            ->selectRaw('MIN(purchase_items.id) as id, direction.id as direction_id, direction.name as name,
                COUNT(DISTINCT document.id) as purchase_count, MAX(document.purchase_date) as last_purchase,
                SUM(purchase_items.quantity) as purchased_quantity, SUM(purchase_items.line_total) as purchase_amount,
                COUNT(DISTINCT purchase_items.unit) as unit_count, MIN(purchase_items.unit) as unit')
            ->groupBy('direction.id', 'direction.name');
    }

    public function groups(array $filters = [], ?string $direction = null): Builder
    {
        return $this->withinDirection($this->lines($filters), $direction)->selectRaw('MIN(purchase_items.id) as id, product.purchase_product_group_id as group_id, product_group.name as name,
                COUNT(DISTINCT document.id) as purchase_count, MAX(document.purchase_date) as last_purchase,
                SUM(purchase_items.quantity) as purchased_quantity, SUM(purchase_items.line_total) as purchase_amount,
                COUNT(DISTINCT purchase_items.unit) as unit_count, MIN(purchase_items.unit) as unit')
            ->groupBy('product.purchase_product_group_id', 'product_group.name');
    }

    public function products(array $filters, string $group, ?string $direction = null): Builder
    {
        $query = $this->withinGroup($this->withinDirection($this->lines($filters), $direction), $group);
        // Frequency uses FULL valid history of each catalog identity, independent
        // of period/supplier/payment filters. Duplicate same-day events add no interval.
        $dates = $this->withinGroup($this->withinDirection($this->lines(), $direction), $group)->whereNotNull('purchase_items.resolved_product_id')
            ->selectRaw('purchase_items.resolved_product_id as product_id, DATE(document.purchase_date) as event_date')
            ->distinct();
        $span = match (DB::connection()->getDriverName()) {
            'pgsql' => 'MAX(event_date)::date - MIN(event_date)::date',
            'mysql', 'mariadb' => 'DATEDIFF(MAX(event_date), MIN(event_date))',
            default => 'julianday(MAX(event_date)) - julianday(MIN(event_date))',
        };
        $frequency = DB::query()->fromSub($dates->toBase(), 'purchase_dates')->groupBy('product_id')
            ->selectRaw("product_id, MAX(event_date) as last_purchase, ({$span}) * 1.0 / NULLIF(COUNT(*) - 1, 0) as average_interval");

        // Latest means the latest filtered invoice line, breaking same-day ties by line ID.
        $latest = $this->withinGroup($this->withinDirection($this->lines($filters), $direction), $group)
            ->selectRaw('purchase_items.resolved_product_id as product_id, purchase_items.unit_price,
                ROW_NUMBER() OVER (PARTITION BY purchase_items.resolved_product_id ORDER BY document.purchase_date DESC, purchase_items.id DESC) as position');

        return $query->leftJoinSub($frequency, 'frequency', 'frequency.product_id', '=', 'purchase_items.resolved_product_id')
            ->leftJoinSub($latest, 'latest_price', fn ($join) => $join
                ->on('latest_price.product_id', '=', 'purchase_items.resolved_product_id')->where('latest_price.position', 1))
            ->selectRaw('MIN(purchase_items.id) as id, purchase_items.resolved_product_id as product_id, product.name as name,
                COUNT(DISTINCT document.id) as purchase_count, MAX(frequency.last_purchase) as last_purchase,
                MAX(frequency.average_interval) as average_interval,
                SUM(purchase_items.quantity) as purchased_quantity, SUM(purchase_items.line_total) as purchase_amount,
                SUM(purchase_items.line_total) * 1.0 / NULLIF(SUM(purchase_items.quantity), 0) as average_price,
                MAX(latest_price.unit_price) as latest_price,
                COUNT(DISTINCT purchase_items.unit) as unit_count, MIN(purchase_items.unit) as unit')
            ->groupBy('purchase_items.resolved_product_id', 'product.name');
    }

    public function history(array $filters, string $group, string $product, ?string $direction = null): Builder
    {
        return $this->withinGroup($this->withinDirection($this->lines($filters), $direction), $group)
            ->when($product === 'unmapped', fn ($q) => $q->whereNull('purchase_items.resolved_product_id'),
                fn ($q) => $q->where('purchase_items.resolved_product_id', (int) $product))
            ->select('purchase_items.*', 'document.purchase_date', 'document.document_number', 'supplier.name as supplier_name');
    }

    private function withinDirection(Builder $query, ?string $direction): Builder
    {
        return match ($direction) {
            null => $query,
            'uncategorized' => $query->whereRaw($this->directionExpression().' IS NULL'),
            default => $query->whereRaw($this->directionExpression().' = ?', [(int) $direction]),
        };
    }

    private function withinGroup(Builder $query, string $group): Builder
    {
        return $group === 'ungrouped' ? $query->whereNull('product.purchase_product_group_id')
            : $query->where('product.purchase_product_group_id', $group);
    }
}
