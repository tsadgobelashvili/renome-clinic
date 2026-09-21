<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class EmployeeAdvance extends Model
{
    public const SOURCES = ['cashbox' => 'სალარო', 'accumulated_cash' => 'წინა დღეების / დაგროვილი ქეში', 'bank' => 'ბანკი', 'other' => 'სხვა'];

    public const STATUSES = ['open' => 'ღია', 'partial' => 'ნაწილობრივ დახურული', 'settled' => 'დახურული', 'overspent' => 'გადახარჯული'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'date' => 'date'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw ValidationException::withMessages(['advance' => 'გაცემული ავანსი უცვლელია. გამოიყენეთ ხარჯის დადასტურება ან თანხის დაბრუნება.']));
        static::deleting(fn () => throw ValidationException::withMessages(['advance' => 'ავანსის ფინანსური ისტორიის წაშლა შეუძლებელია.']));
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceEntry::class);
    }

    public function scopeWithTotals(Builder $query): Builder
    {
        return $query->withSum(['entries as confirmed_total' => fn ($q) => $q->whereIn('kind', ['rs', 'manual'])], 'amount')
            ->withSum(['entries as returned_total' => fn ($q) => $q->where('kind', 'return')], 'amount');
    }

    public function getConfirmedExpenseAmountAttribute(): string
    {
        return Money::decimal(array_key_exists('confirmed_total', $this->attributes) ? ($this->confirmed_total ?? 0) : $this->entries()->whereIn('kind', ['rs', 'manual'])->sum('amount'));
    }

    public function getReturnedAmountAttribute(): string
    {
        return Money::decimal(array_key_exists('returned_total', $this->attributes) ? ($this->returned_total ?? 0) : $this->entries()->where('kind', 'return')->sum('amount'));
    }

    public function getRemainingAmountAttribute(): string
    {
        return Money::decimal(max(0, Money::minorUnits($this->amount) - Money::minorUnits($this->confirmed_expense_amount) - Money::minorUnits($this->returned_amount)) / 100);
    }

    public function getOverspentAmountAttribute(): string
    {
        return Money::decimal(max(0, Money::minorUnits($this->confirmed_expense_amount) + Money::minorUnits($this->returned_amount) - Money::minorUnits($this->amount)) / 100);
    }

    public function getStatusAttribute(): string
    {
        return match (true) {
            Money::minorUnits($this->overspent_amount) > 0 => 'overspent',
            Money::minorUnits($this->remaining_amount) === 0 => 'settled',
            Money::minorUnits($this->confirmed_expense_amount) > 0 => 'partial',
            default => 'open',
        };
    }
}
