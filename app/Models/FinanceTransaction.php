<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class FinanceTransaction extends Model
{
    public const TYPES = ['income' => 'შემოსავალი', 'expense' => 'ხარჯი'];

    public const CATEGORIES = [
        'laboratory' => 'ლაბორატორია', 'technician' => 'ტექნიკოსი',
        'materials' => 'მასალები / მომწოდებელი', 'salary' => 'ხელფასი',
        'rent' => 'ქირა', 'utilities' => 'კომუნალური', 'marketing' => 'მარკეტინგი',
        'transport' => 'ტრანსპორტი', 'office' => 'ოფისი', 'repair' => 'რემონტი / მოვლა',
        'taxes' => 'გადასახადები', 'bank_fees' => 'ბანკის / ტერმინალის საკომისიო',
        'other_income' => 'სხვა შემოსავალი', 'other' => 'სხვა',
    ];

    public const CASH_SOURCES = [
        'current_cashier' => 'მიმდინარე სალარო',
        'withdrawn_cash' => 'ადრე გატანილი თანხა',
    ];

    protected $fillable = [
        'type', 'transaction_date', 'category', 'description', 'amount', 'currency',
        'payment_method', 'cash_source', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['transaction_date' => 'datetime', 'amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (FinanceTransaction $transaction): void {
            $transaction->created_by ??= auth()->id();
            $transaction->currency = $transaction->currency ?: Currency::DEFAULT;
            $transaction->payment_method = PaymentMethod::normalize($transaction->payment_method);
            $transaction->description = filled($transaction->description) ? trim($transaction->description) : null;

            if (! isset(self::TYPES[$transaction->type])) {
                throw ValidationException::withMessages(['type' => 'ფინანსური ოპერაციის ტიპი არასწორია.']);
            }
            if (! isset(self::CATEGORIES[$transaction->category])) {
                throw ValidationException::withMessages(['category' => 'ფინანსური ოპერაციის კატეგორია არასწორია.']);
            }
            if (! PaymentMethod::isSupported($transaction->payment_method)) {
                throw ValidationException::withMessages(['payment_method' => 'გადახდის მეთოდი არასწორია.']);
            }
            if ((float) $transaction->amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'თანხა უნდა იყოს 0-ზე მეტი.']);
            }
            if ($transaction->payment_method === 'cash' && ! isset(self::CASH_SOURCES[$transaction->cash_source])) {
                throw ValidationException::withMessages(['cash_source' => 'აირჩიეთ ნაღდი თანხის წყარო.']);
            }
            if ($transaction->payment_method !== 'cash') {
                $transaction->cash_source = null;
            }
        });
    }

    public function cashboxTransaction(): HasOne
    {
        return $this->hasOne(CashboxTransaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
