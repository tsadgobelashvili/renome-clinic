<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use App\Services\PaymentProcessor;
use App\Support\CashboxManager;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Validation\ValidationException;

class Payment extends Model
{
    use SoftDeletes;

    public bool $skipDefaultSplit = false;

    public bool $skipCashboxSync = false;

    protected $fillable = [
        'visit_id',
        'created_by',
        'amount',
        'currency',
        'payment_date',
        'payment_method',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payment_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payment $payment): void {
            $payment->created_by ??= auth()->id();
        });

        static::saving(function (Payment $payment): void {
            $payment->amount = self::toCents($payment->getAttributes()['amount'] ?? 0) / 100;
            $payment->currency = $payment->currency ?: Currency::DEFAULT;
            $payment->payment_method = self::normalizeMethod($payment->payment_method);

            if (! Currency::isSupported($payment->currency)) {
                throw ValidationException::withMessages(['currency' => 'არჩეული ვალუტა არასწორია.']);
            }

            if (self::toCents($payment->amount) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'გადახდის თანხა უნდა იყოს 0-ზე მეტი.',
                ]);
            }

            if (! PaymentMethod::isSupported($payment->payment_method)) {
                throw ValidationException::withMessages([
                    'payment_method' => 'არჩეული გადახდის მეთოდი არასწორია.',
                ]);
            }

            $visit = $payment->visit()->first();

            if ((! $visit) || ($visit->net_amount === null)) {
                return;
            }

            $otherPaymentsTotal = $visit->payments()
                ->where('currency', $payment->currency)
                ->when(
                    $payment->exists,
                    fn ($query) => $query->whereKeyNot($payment->getKey()),
                )
                ->sum('amount');

            if (($payment->currency === $visit->currency)
                && (self::toCents($otherPaymentsTotal) + self::toCents($payment->amount)) > self::toCents($visit->net_amount)) {
                throw ValidationException::withMessages([
                    'amount' => 'გადახდების ჯამი ფასდაკლების შემდეგ გადასახდელ თანხას ვერ გადააჭარბებს.',
                ]);
            }
        });

        static::created(function (Payment $payment): void {
            if (! $payment->skipDefaultSplit) {
                $payment->splits()->create([
                    'payment_method' => $payment->payment_method,
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                ]);
            }

            $payment->audit('created', null, $payment->auditValues());
            if (! $payment->skipCashboxSync) {
                app(CashboxManager::class)->syncPayment($payment);
            }
        });
        static::updated(function (Payment $payment): void {
            $payment->audit('updated', $payment->getRawOriginal(), $payment->auditValues());
            app(CashboxManager::class)->syncPayment($payment);
        });
        static::deleted(function (Payment $payment): void {
            $payment->audit('deleted', $payment->auditValues(), null);
            app(CashboxManager::class)->syncPayment($payment);
        });
        static::restored(function (Payment $payment): void {
            $payment->audit('restored', null, $payment->auditValues());
            app(CashboxManager::class)->syncPayment($payment);
        });
    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function splits(): HasMany
    {
        return $this->hasMany(PaymentSplit::class);
    }

    public function audits(): HasMany
    {
        return $this->hasMany(PaymentAudit::class)->latest('created_at')->latest('id');
    }

    public function cashboxTransaction(): HasOne
    {
        return $this->hasOne(CashboxTransaction::class);
    }

    public function cashboxTransactions(): HasMany
    {
        return $this->hasMany(CashboxTransaction::class);
    }

    public function getMethodDisplayAttribute(): string
    {
        $splits = $this->relationLoaded('splits') ? $this->splits : $this->splits()->oldest('id')->get();

        return $splits->map(fn (PaymentSplit $split): string => PaymentMethod::labelFor($split->payment_method)
            .' '.Currency::format($split->amount, $split->currency))->join(' + ');
    }

    /**
     * @deprecated Use PaymentProcessor::process(). Kept as a legacy compatibility bridge.
     *
     * @param  array<int, array{payment_method: string, amount: mixed}>  $splits
     */
    public static function createWithSplits(array $attributes, array $splits): self
    {
        return app(PaymentProcessor::class)->process($attributes, $splits);
    }

    /**
     * @deprecated Use PaymentProcessor::prepare() or validate().
     *
     * @param  array<int, array{payment_method: string, amount: mixed}>  $splits
     */
    public static function validateSplits(mixed $amount, array $splits): void
    {
        app(PaymentProcessor::class)->validate($amount, app(PaymentProcessor::class)->normalizeRows($splits));
    }

    /** @param array<int, array{payment_method: string, amount: mixed}> $splits */
    public function replaceSplits(array $splits): void
    {
        app(PaymentProcessor::class)->replaceSplits($this, $splits);
    }

    /** @param array<int, array<string, mixed>> $oldValues @param array<int, array<string, mixed>> $newValues */
    public function auditSplitReplacement(array $oldValues, array $newValues): void
    {
        $this->audit('splits_updated', ['splits' => $oldValues], ['splits' => $newValues]);
    }

    private function auditValues(): array
    {
        return collect($this->attributesToArray())->only([
            'amount', 'currency', 'payment_date', 'payment_method', 'comment', 'created_by',
        ])->all();
    }

    private function audit(string $action, ?array $oldValues, ?array $newValues): void
    {
        PaymentAudit::create([
            'payment_id' => $this->getKey(),
            'user_id' => auth()->id(),
            'action' => $action,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }

    public static function toCents(mixed $amount): int
    {
        return Money::minorUnits($amount);
    }

    public static function normalizeMethod(mixed $method): string
    {
        return PaymentMethod::normalize($method);
    }

    /**
     * @deprecated Use PaymentProcessor::normalizeRows().
     *
     * @param  array<int|string, array<string, mixed>>  $splits
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeSplits(array $splits): array
    {
        return app(PaymentProcessor::class)->normalizeRows($splits);
    }
}
