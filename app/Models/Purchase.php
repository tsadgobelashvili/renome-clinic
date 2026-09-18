<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class Purchase extends Model
{
    protected $fillable = ['purchase_date', 'supplier_id', 'document_number', 'total_amount', 'notes', 'created_by', 'source', 'source_document_id', 'import_batch_id'];

    protected function casts(): array
    {
        return ['purchase_date' => 'date', 'total_amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $purchase): void {
            if ($purchase->isDirty(['source', 'supplier_id', 'total_amount']) && $purchase->cashExpense()->exists()) {
                throw ValidationException::withMessages(['items' => 'ქეშით გადახდილი დოკუმენტის თანხის შეცვლამდე გააუქმეთ გადახდა.']);
            }
        });
        static::deleting(function (self $purchase): void {
            if ($purchase->cashPostings()->exists()) {
                throw ValidationException::withMessages(['purchase' => 'გადახდის ისტორიის მქონე დოკუმენტის წაშლა შეუძლებელია.']);
            }
        });
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function bankTransactions(): BelongsToMany
    {
        return $this->belongsToMany(BankTransaction::class, 'bank_purchase_matches')->withPivot(['amount', 'confirmed_by'])->withTimestamps();
    }

    public function cashExpense(): HasOne
    {
        return $this->hasOne(FinanceTransaction::class)->where('type', 'expense')->whereDoesntHave('reversal');
    }

    public function cashPostings(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function refreshTotal(): void
    {
        $this->forceFill(['total_amount' => round((float) $this->items()->sum('line_total'), 2)])->saveQuietly();
    }
}
