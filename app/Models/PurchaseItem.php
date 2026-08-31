<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $fillable = ['purchase_id', 'product_id', 'quantity', 'unit', 'unit_price', 'line_total', 'vat_amount', 'source_row_hash'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(fn (self $item) => $item->line_total = filled($item->line_total)
            ? round((float) $item->line_total, 2)
            : round((float) $item->quantity * (float) $item->unit_price, 2));
        static::saved(fn (self $item) => $item->purchase?->refreshTotal());
        static::deleted(fn (self $item) => $item->purchase?->refreshTotal());
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
