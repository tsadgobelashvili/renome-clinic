<?php

namespace App\Services;

use App\Models\PurchaseItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Purchasing analysis only. RS amounts come from invoice lines; manual amounts from the original expense. */
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
        return $this->reportLines($filters)
            ->leftJoin('expense_categories as direction', 'direction.id', '=', 'purchase_items.direction_id')
            ->selectRaw('MIN(purchase_items.id) as id, direction.id as direction_id, direction.name as name,
                purchase_items.currency, COUNT(DISTINCT purchase_items.event_key) as purchase_count, MAX(purchase_items.purchase_date) as last_purchase,
                SUM(purchase_items.quantity) as purchased_quantity, SUM(purchase_items.line_total) as purchase_amount,
                COUNT(DISTINCT purchase_items.unit) as unit_count, MIN(purchase_items.unit) as unit')
            ->groupBy('direction.id', 'direction.name', 'purchase_items.currency');
    }

    public function groups(array $filters = [], ?string $direction = null): Builder
    {
        return $this->withinDirection($this->reportLines($filters), $direction)->selectRaw('MIN(purchase_items.id) as id, purchase_items.group_id, purchase_items.group_name as name,
                purchase_items.currency, COUNT(DISTINCT purchase_items.event_key) as purchase_count, MAX(purchase_items.purchase_date) as last_purchase,
                SUM(purchase_items.quantity) as purchased_quantity, SUM(purchase_items.line_total) as purchase_amount,
                COUNT(DISTINCT purchase_items.unit) as unit_count, MIN(purchase_items.unit) as unit')
            ->groupBy('purchase_items.group_id', 'purchase_items.group_name', 'purchase_items.currency');
    }

    public function products(array $filters, string $group, ?string $direction = null): Builder
    {
        $query = $this->withinGroup($this->withinDirection($this->reportLines($filters), $direction), $group);
        // Frequency uses FULL valid history of each catalog identity, independent
        // of period/supplier/payment filters. Duplicate same-day events add no interval.
        $dates = $this->withinGroup($this->withinDirection($this->reportLines(), $direction), $group)->whereNotNull('purchase_items.product_key')
            ->selectRaw('purchase_items.product_key as product_id, purchase_items.currency, DATE(purchase_items.purchase_date) as event_date')
            ->distinct();
        $span = match (DB::connection()->getDriverName()) {
            'pgsql' => 'MAX(event_date)::date - MIN(event_date)::date',
            'mysql', 'mariadb' => 'DATEDIFF(MAX(event_date), MIN(event_date))',
            default => 'julianday(MAX(event_date)) - julianday(MIN(event_date))',
        };
        $frequency = DB::query()->fromSub($dates->toBase(), 'purchase_dates')->groupBy('product_id', 'currency')
            ->selectRaw("product_id, currency, MAX(event_date) as last_purchase, ({$span}) * 1.0 / NULLIF(COUNT(*) - 1, 0) as average_interval");

        // Latest means the latest filtered invoice line, breaking same-day ties by line ID.
        $latest = $this->withinGroup($this->withinDirection($this->reportLines($filters), $direction), $group)
            ->selectRaw('purchase_items.product_key as product_id, purchase_items.currency, purchase_items.unit_price,
                ROW_NUMBER() OVER (PARTITION BY purchase_items.product_key, purchase_items.currency ORDER BY purchase_items.purchase_date DESC, purchase_items.id DESC) as position');

        // PostgreSQL needs one CASE result type for numeric RS IDs and prefixed manual keys.
        // Keep SQLite's existing numeric RS result type for its resource/table consumers.
        $catalogId = DB::connection()->getDriverName() === 'pgsql'
            ? 'CAST(purchase_items.resolved_product_id AS TEXT)' : 'purchase_items.resolved_product_id';

        return $query->leftJoinSub($frequency, 'frequency', fn ($join) => $join->on('frequency.product_id', '=', 'purchase_items.product_key')->on('frequency.currency', '=', 'purchase_items.currency'))
            ->leftJoinSub($latest, 'latest_price', fn ($join) => $join
                ->on('latest_price.product_id', '=', 'purchase_items.product_key')->on('latest_price.currency', '=', 'purchase_items.currency')->where('latest_price.position', 1))
            ->selectRaw("MIN(purchase_items.id) as id,
                CASE WHEN purchase_items.origin = 'rs' THEN {$catalogId} ELSE purchase_items.product_key END as product_id,
                purchase_items.product_name as name, purchase_items.currency,
                COUNT(DISTINCT purchase_items.event_key) as purchase_count, MAX(frequency.last_purchase) as last_purchase,
                MAX(frequency.average_interval) as average_interval,
                SUM(purchase_items.quantity) as purchased_quantity, SUM(purchase_items.line_total) as purchase_amount,
                SUM(purchase_items.line_total) * 1.0 / NULLIF(SUM(purchase_items.quantity), 0) as average_price,
                MAX(latest_price.unit_price) as latest_price,
                COUNT(DISTINCT purchase_items.unit) as unit_count, MIN(purchase_items.unit) as unit")
            ->groupBy('purchase_items.product_key', 'purchase_items.product_name', 'purchase_items.currency', 'purchase_items.origin', 'purchase_items.resolved_product_id');
    }

    public function history(array $filters, string $group, string $product, ?string $direction = null): Builder
    {
        return $this->withinGroup($this->withinDirection($this->reportLines($filters), $direction), $group)
            ->when($product === 'unmapped', fn ($q) => $q->whereNull('purchase_items.product_key'),
                fn ($q) => $q->where('purchase_items.product_key', $product))
            ->select('purchase_items.*');
    }

    private function withinDirection(Builder $query, ?string $direction): Builder
    {
        return match ($direction) {
            null => $query,
            'uncategorized' => $query->whereNull('purchase_items.direction_id'),
            default => $query->where('purchase_items.direction_id', (int) $direction),
        };
    }

    private function withinGroup(Builder $query, string $group): Builder
    {
        return $group === 'ungrouped' ? $query->whereNull('purchase_items.group_id')
            : $query->where('purchase_items.group_id', $group);
    }

    private function reportLines(array $filters = []): Builder
    {
        $cast = in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'CHAR' : 'TEXT';
        $integer = $cast === 'CHAR' ? 'SIGNED' : 'BIGINT';
        $event = ManualPurchaseAnalysis::concatenate('rs:', 'document.id');
        $rs = $this->lines($filters)->when(filled($filters['currency'] ?? null) && $filters['currency'] !== 'GEL', fn ($q) => $q->whereRaw('1 = 0'))
            ->selectRaw("CAST(purchase_items.id AS {$integer}) as id")
            ->addSelect('purchase_items.purchase_id', 'purchase_items.product_id',
                'purchase_items.purchase_product_id', 'purchase_items.resolved_product_id', 'purchase_items.quantity',
                'purchase_items.unit', 'purchase_items.unit_price', 'purchase_items.line_total', 'purchase_items.item_name',
                'document.purchase_date', 'document.document_number', 'supplier.name as supplier_name')
            ->selectRaw("product.purchase_product_group_id as group_id, product_group.name as group_name,
                {$this->directionExpression()} AS direction_id, CAST(purchase_items.resolved_product_id AS {$cast}) as product_key,
                product.name as product_name, 'GEL' AS currency, {$event} AS event_key, 'rs' AS origin, 'rs' AS ledger_source");

        return PurchaseItem::query()->fromSub($rs->toBase()->unionAll(app(ManualPurchaseAnalysis::class)->lines($filters)), 'purchase_items');
    }
}
