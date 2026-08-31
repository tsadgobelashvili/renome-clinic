<?php

namespace App\Models;

use App\Support\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class CashboxTransaction extends Model
{
    public const TYPE_LABELS = [
        'cash_transfer_out' => 'ქეშის გადატანა — გასვლა',
        'cash_transfer_in' => 'ქეშის გადატანა — შემოსვლა',
        'patient_payment' => 'პაციენტის გადახდა',
        'other_income' => 'სხვა ნაღდი შემოსავალი',
        'product_sale' => 'პროდუქტის გაყიდვა',
        'expense' => 'სხვა ხარჯი',
        'cash_withdrawal' => 'თანხის ამოღება',
        'manual_adjustment' => 'კორექტირება',
    ];

    protected $fillable = [
        'cashbox_day_id', 'type', 'amount', 'currency', 'payment_method', 'transaction_date',
        'payment_id', 'payment_split_id', 'finance_transaction_id', 'product_sale_id', 'cash_transfer_id', 'patient_id', 'visit_id', 'expense_category', 'description', 'created_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'transaction_date' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (CashboxTransaction $transaction): void {
            $transaction->created_by ??= auth()->id();
            $transaction->currency = $transaction->currency ?: Currency::DEFAULT;
        });

        static::saving(function (CashboxTransaction $transaction): void {
            if ($transaction->exists && filled($transaction->cash_transfer_id)) {
                throw ValidationException::withMessages(['amount' => 'Cash transfer movement is immutable.']);
            }
            if ($transaction->type !== 'patient_payment' && $transaction->exists && $transaction->day()->where('status', 'closed')->exists()) {
                throw ValidationException::withMessages(['amount' => 'დახურული დღის მოძრაობის შეცვლა შეუძლებელია.']);
            }

            if ((float) $transaction->amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'თანხა უნდა იყოს 0-ზე მეტი.']);
            }
        });

        static::deleting(function (CashboxTransaction $transaction): void {
            if (filled($transaction->cash_transfer_id)) {
                throw ValidationException::withMessages(['amount' => 'Cash transfer movement cannot be deleted.']);
            }
        });
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(CashboxDay::class, 'cashbox_day_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class)->withTrashed();
    }

    public function paymentSplit(): BelongsTo
    {
        return $this->belongsTo(PaymentSplit::class);
    }

    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class);
    }

    public function productSale(): BelongsTo
    {
        return $this->belongsTo(ProductSale::class);
    }

    public function cashTransfer(): BelongsTo
    {
        return $this->belongsTo(CashTransfer::class);
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
}
