<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = ['name', 'normalized_name', 'tax_id', 'phone', 'notes'];

    protected static function booted(): void
    {
        static::saving(function (self $supplier): void {
            $supplier->name = trim($supplier->name);
            $supplier->normalized_name = self::normalizeName($supplier->name);
            $supplier->tax_id = filled($supplier->tax_id) ? trim($supplier->tax_id) : null;
            $supplier->phone = filled($supplier->phone) ? trim($supplier->phone) : null;
        });
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    public static function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $name)));
    }
}
