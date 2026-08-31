<?php

namespace App\Models;

use App\Enums\PartnerAccount;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class PartnerFinanceTransaction extends Model
{
    public const TYPE_EXPENSE = 'expense';

    public const TYPE_TRANSFER = 'transfer';

    public const TYPE_EXCHANGE = 'currency_exchange';

    public const TYPES = [
        self::TYPE_EXPENSE => 'ხარჯი',
        self::TYPE_TRANSFER => 'გადატანა',
        self::TYPE_EXCHANGE => 'ვალუტის გაცვლა',
    ];

    public const EXPENSE_CATEGORIES = [
        'salary' => 'ხელფასი',
        'laboratory' => 'ლაბორატორია',
        'other' => 'სხვა',
    ];

    protected $fillable = [
        'type', 'transacted_at', 'category', 'from_account', 'to_account',
        'amount', 'currency', 'from_amount', 'from_currency', 'to_amount',
        'to_currency', 'exchange_rate', 'notes',
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
        static::saving(function (PartnerFinanceTransaction $transaction): void {
            if (! array_key_exists((string) $transaction->type, self::TYPES)) {
                throw ValidationException::withMessages(['type' => 'ტრანზაქციის ტიპი არასწორია.']);
            }

            $transaction->notes = filled($transaction->notes) ? trim((string) $transaction->notes) : null;

            match ($transaction->type) {
                self::TYPE_EXPENSE => self::validateExpense($transaction),
                self::TYPE_TRANSFER => self::validateTransfer($transaction),
                self::TYPE_EXCHANGE => self::validateExchange($transaction),
            };
        });
    }

    private static function validateExpense(self $transaction): void
    {
        self::validateAccount($transaction->from_account, 'from_account');
        self::validateMoney($transaction->amount, $transaction->currency, 'amount', 'currency');

        if (! array_key_exists((string) $transaction->category, self::EXPENSE_CATEGORIES)) {
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
