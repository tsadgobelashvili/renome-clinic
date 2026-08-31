<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = ['name', 'normalized_name', 'product_category_id', 'selling_price', 'is_active'];

    protected static function booted(): void
    {
        static::saving(function (self $product): void {
            $product->name = trim($product->name);
            $product->normalized_name = self::normalizeName($product->name);
            $product->product_category_id ??= ProductCategory::uncategorizedId();
        });
    }

    protected function casts(): array
    {
        return ['selling_price' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(ProductSaleItem::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function purchaseItems(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
