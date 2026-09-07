<?php

namespace App\Models;

use App\Enums\PartnerAccount;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class PartnerFinanceTransaction extends Model
{
    public const TYPE_SALARY_CASH = 'salary_cash';

    public const TYPE_EXPENSE = 'expense';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_EXCHANGE = 'currency_exchange';

    public const TYPE_OWNER_WITHDRAWAL = 'owner_withdrawal';

    public const SOURCE_CLINIC = 'clinic';

    public const SOURCE_ISRAELI = 'israeli';

    public const TYPES = [
        self::TYPE_SALARY_CASH => 'Salary cash allocation',
        self::TYPE_EXPENSE => 'ხარჯი',
        self::TYPE_TRANSFER => 'გადატანა',
        self::TYPE_EXCHANGE => 'ვალუტის გაცვლა',
        self::TYPE_OWNER_WITHDRAWAL => 'მფლობელის გატანა',
    ];

    public const EXPENSE_CATEGORIES = [
        'salary' => 'ხელფასი',
        'doctor_salary' => 'ექიმის ხელფასი',
        'laboratory' => 'ლაბორატორია',
        'lab_salary' => 'ლაბორატორიის ხელფასი',
        'supplier' => 'მომწოდებელი',
        'materials' => 'მასალები / მარაგები',
        'equipment' => 'აღჭურვილობა',
        'other_expense' => 'სხვა ხარჯი',
        'other' => 'სხვა',
    ];

    public const USD_USAGE_CATEGORIES = [
        'lab_salary' => 'ლაბორატორიის ხელფასი',
        'doctor_salary' => 'ექიმის ხელფასი',
        'supplier' => 'მომწოდებელი',
        'materials' => 'მასალები / მარაგები',
        'equipment' => 'აღჭურვილობა',
        'other_expense' => 'სხვა ხარჯი',
    ];

    public const TRANSFER_CATEGORIES = [
        'bank_deposit' => 'ანგარიშზე შეტანა',
        'other_transfer' => 'სხვა გადატანა',
    ];

    protected $fillable = [
        'finance_transaction_id', 'type', 'transacted_at', 'category', 'from_account', 'to_account',
        'amount', 'currency', 'from_amount', 'from_currency', 'to_amount',
        'to_currency', 'exchange_rate', 'notes',
        'source', 'recipient', 'doctor_id', 'lab_salary_settlement_id', 'salary_settlement_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'transacted_at' => 'datetime',
            'amount' => 'decimal:2',
            'from_amount' => 'decimal:2',
            'to_amount' => 'decimal:2',
            'exchange_rate' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $transaction): void {
            if ($transaction->getOriginal('finance_transaction_id') !== null) {
                throw ValidationException::withMessages(['amount' => __('employees.salary.reverse_only')]);
            }
        });
        static::deleting(function (self $transaction): void {
            if ($transaction->finance_transaction_id !== null) {
                throw ValidationException::withMessages(['amount' => __('employees.salary.reverse_only')]);
            }
        });
        static::saving(function (PartnerFinanceTransaction $transaction): void {
            $transaction->source ??= self::SOURCE_ISRAELI;
            $transaction->created_by ??= auth()->id();
            if (! in_array($transaction->source, [self::SOURCE_CLINIC, self::SOURCE_ISRAELI], true)) {
                throw ValidationException::withMessages(['source' => 'ფინანსური წყარო არასწორია.']);
            }
            if (! array_key_exists((string) $transaction->type, self::TYPES)) {
                throw ValidationException::withMessages(['type' => 'ტრანზაქციის ტიპი არასწორია.']);
            }

            $transaction->notes = filled($transaction->notes) ? trim((string) $transaction->notes) : null;

            match ($transaction->type) {
                self::TYPE_SALARY_CASH => self::validateSalaryCash($transaction),
                self::TYPE_EXPENSE => self::validateExpense($transaction),
                self::TYPE_TRANSFER => self::validateTransfer($transaction),
                self::TYPE_EXCHANGE => self::validateExchange($transaction),
                self::TYPE_OWNER_WITHDRAWAL => self::validateOwnerWithdrawal($transaction),
            };
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function labSalarySettlement(): BelongsTo
    {
        return $this->belongsTo(LabSalarySettlement::class);
    }

    public function salarySettlement(): BelongsTo
    {
        return $this->belongsTo(SalarySettlement::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function scopeIsraeli(Builder $query): Builder
    {
        return $query->where('source', self::SOURCE_ISRAELI);
    }

    public function financeTransaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class);
    }

    private static function validateSalaryCash(self $transaction): void
    {
        $finance = $transaction->financeTransaction;
        if (! $finance || $finance->clinic_cash_gel === null || $transaction->source !== 'israeli'
            || $transaction->currency !== 'GEL' || Money::minorUnits($transaction->amount) <= 0
            || Money::minorUnits($transaction->amount) !== Money::minorUnits($finance->israeli_cash_gel)
            || $transaction->from_account !== ($finance->type === 'expense' ? 'cash' : null)
            || $transaction->to_account !== ($finance->type === 'income' ? 'cash' : null)) {
            throw ValidationException::withMessages(['amount' => __('employees.salary.allocation_mismatch')]);
        }
    }

    private static function validateExpense(self $transaction): void
    {
        self::validateAccount($transaction->from_account, 'from_account');
        self::validateMoney($transaction->amount, $transaction->currency, 'amount', 'currency');

        if (! array_key_exists((string) $transaction->category, self::EXPENSE_CATEGORIES)
            && ! array_key_exists((string) $transaction->category, FinanceTransaction::CATEGORIES)) {
            throw ValidationException::withMessages(['category' => 'ხარჯის კატეგორია არასწორია.']);
        }
    }

    private static function validateTransfer(self $transaction): void
    {
        self::validateAccount($transaction->from_account, 'from_account');
        self::validateAccount($transaction->to_account, 'to_account');
        self::validateMoney($transaction->amount, $transaction->currency, 'amount', 'currency');

        if ($transaction->from_account === $transaction->to_account) {
            throw ValidationException::withMessages(['to_account' => 'მიმღები ანგარიში განსხვავებული უნდა იყოს.']);
        }
    }

    private static function validateExchange(self $transaction): void
    {
        self::validateAccount($transaction->from_account, 'from_account');
        self::validateAccount($transaction->to_account, 'to_account');
        self::validateMoney($transaction->from_amount, $transaction->from_currency, 'from_amount', 'from_currency');
        self::validateMoney($transaction->to_amount, $transaction->to_currency, 'to_amount', 'to_currency');

        if ($transaction->from_currency === $transaction->to_currency) {
            throw ValidationException::withMessages(['to_currency' => 'გაცვლის ვალუტები განსხვავებული უნდა იყოს.']);
        }

        if ((float) $transaction->exchange_rate <= 0) {
            throw ValidationException::withMessages(['exchange_rate' => 'გაცვლის კურსი უნდა იყოს 0-ზე მეტი.']);
        }
    }

    private static function validateOwnerWithdrawal(self $transaction): void
    {
        self::validateAccount($transaction->from_account, 'from_account');
        self::validateMoney($transaction->amount, $transaction->currency, 'amount', 'currency');
    }

    private static function validateAccount(mixed $account, string $field): void
    {
        if (! PartnerAccount::isSupported($account)) {
            throw ValidationException::withMessages([$field => 'არჩეული ანგარიში არასწორია.']);
        }
    }

    private static function validateMoney(mixed $amount, mixed $currency, string $amountField, string $currencyField): void
    {
        if (Money::minorUnits($amount) <= 0) {
            throw ValidationException::withMessages([$amountField => 'თანხა უნდა იყოს 0-ზე მეტი.']);
        }

        if (! Currency::isSupported((string) $currency)) {
            throw ValidationException::withMessages([$currencyField => 'არჩეული ვალუტა არასწორია.']);
        }
    }
}
