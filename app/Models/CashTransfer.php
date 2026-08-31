<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class CashTransfer extends Model
{
    protected $fillable = ['source_cashbox_day_id', 'destination_cashbox_day_id', 'amount', 'currency', 'transferred_at', 'created_by', 'note', 'idempotency_key'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transferred_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['transfer' => 'Cash transfer is immutable. Create a reversal instead.']));
        static::deleting(fn () => throw ValidationException::withMessages(['transfer' => 'Cash transfer cannot be deleted.']));
    }

    public function sourceDay(): BelongsTo
    {
        return $this->belongsTo(CashboxDay::class, 'source_cashbox_day_id');
    }

    public function destinationDay(): BelongsTo
    {
        return $this->belongsTo(CashboxDay::class, 'destination_cashbox_day_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CashboxTransaction::class);
    }
}
