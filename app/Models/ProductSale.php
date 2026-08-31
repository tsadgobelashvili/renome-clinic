<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductSale extends Model
{
    protected $fillable = ['sold_at', 'patient_id', 'visit_id', 'total', 'base_total', 'currency', 'exchange_rate', 'payment_method', 'note', 'created_by'];

    protected function casts(): array
    {
        return ['sold_at' => 'datetime', 'total' => 'decimal:2', 'base_total' => 'decimal:2', 'exchange_rate' => 'decimal:6'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProductSaleItem::class);
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cashboxTransaction(): HasOne
    {
        return $this->hasOne(CashboxTransaction::class);
    }

    public function cashboxTransactions(): HasMany
    {
        return $this->hasMany(CashboxTransaction::class);
    }
}
