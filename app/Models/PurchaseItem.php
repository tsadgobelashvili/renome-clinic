<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PurchaseItem extends Model
{
    protected $fillable = ['purchase_id', 'product_id', 'quantity', 'unit', 'unit_price', 'line_total', 'vat_amount', 'source_row_hash', 'purchase_product_id', 'item_name'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            if ($item->purchase_product_id) {
                $product = $item->purchaseProduct;
                if (! $product || $product->supplier_id !== $item->purchase->supplier_id) {
                    throw ValidationException::withMessages(['purchase_product_id' => 'პროდუქტი სხვა მომწოდებელს ეკუთვნის.']);
                }
                $item->item_name ??= $product->name;
            }
        });
        static::saving(function (self $item): void {
            $item->line_total = filled($item->line_total)
                ? round((float) $item->line_total, 2)
                : round((float) $item->quantity * (float) $item->unit_price, 2);
            if (! $item->exists || $item->isDirty(['purchase_id', 'purchase_product_id', 'quantity', 'unit_price', 'line_total'])) {
                $item->guardCashPayment();
            }
        });
        static::deleting(fn (self $item) => $item->guardCashPayment());
        static::saved(fn (self $item) => $item->purchase?->refreshTotal());
        static::deleted(fn (self $item) => $item->purchase?->refreshTotal());
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    private function guardCashPayment(): void
    {
        // The edit page saves inside a transaction; serialize its monetary edits with payment/matching.
        foreach (array_unique(array_filter([$this->purchase_id, $this->getOriginal('purchase_id')])) as $id) {
            $purchase = Purchase::lockForUpdate()->find($id);
            if ($purchase?->cashExpense()->exists()) {
                throw ValidationException::withMessages(['items' => 'ქეშით გადახდილი დოკუმენტის თანხის შეცვლამდე გააუქმეთ გადახდა. მიმართულების შეცვლა შესაძლებელია.']);
            }
        }
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function purchaseProduct(): BelongsTo
    {
        return $this->belongsTo(PurchaseProduct::class);
    }
}
