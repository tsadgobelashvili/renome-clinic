<?php

namespace App\Models;

use App\Services\ExpenseDimensions;
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
        'expense' => 'ხარჯი',
        'cash_withdrawal' => 'თანხის ამოღება',
        'manual_adjustment' => 'კორექტირება',
    ];

    protected $fillable = [
        'employee_advance_id', 'employee_advance_key',
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
            if ($transaction->exists) {
                $transaction->guardRsCash();
            }
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
            $transaction->guardRsCash();
            if (filled($transaction->cash_transfer_id)) {
                throw ValidationException::withMessages(['amount' => 'Cash transfer movement cannot be deleted.']);
            }
        });
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(CashboxDay::class, 'cashbox_day_id');
    }

    private function guardRsCash(): void
    {
        if ($this->getOriginal('employee_advance_id')) {
            throw ValidationException::withMessages(['advance' => 'ავანსის მოძრაობა უცვლელია. გამოიყენეთ ავანსის დაბრუნება.']);
        }
        if ($this->getOriginal('finance_transaction_id') && FinanceTransaction::whereKey($this->getOriginal('finance_transaction_id'))->whereNotNull('purchase_id')->exists()) {
            throw ValidationException::withMessages(['amount' => 'RS ქეშის მოძრაობა უცვლელია. გამოიყენეთ დოკუმენტიდან გადახდის გაუქმება.']);
        }
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

    public function expenseClassification(): string
    {
        $finance = $this->financeTransaction;
        if ($finance && ($finance->expense_direction_id || $finance->expense_type_id)) {
            $registry = app(ExpenseDimensions::class)->registry();

            return ExpenseDimensions::label($registry->get($finance->expense_direction_id)).' → '.ExpenseDimensions::label($registry->get($finance->expense_type_id));
        }
        $category = $finance?->expenseCategory?->name
            ?? (['materials' => 'მასალები', 'salary_advance' => 'ხელფასი / ავანსი'][$this->expense_category ?? ''] ?? null)
            ?? (FinanceTransaction::CATEGORIES[$finance?->category ?? $this->expense_category ?? ''] ?? __('expense-categories.uncategorized'));

        return implode(' → ', array_filter([$category, $finance?->expenseSubcategory?->name]));
    }

    public function expenseDetails(): string
    {
        return implode(' · ', array_filter([
            $this->expenseClassification(),
            $this->financeTransaction?->description ?? $this->description,
        ]));
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
