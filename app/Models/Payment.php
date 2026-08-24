<?php

namespace App\Models;

use App\Support\CashboxManager;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Payment extends Model
{
    use SoftDeletes;

    public bool $skipDefaultSplit = false;

    public const METHOD_LABELS = [
        'cash' => 'ნაღდი',
        'card' => 'ბარათი',
        'transfer' => 'გადარიცხვა',
    ];

    public const METHODS = ['cash', 'card', 'transfer'];

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

            if (! Currency::isSupported($payment->currency)) {
                throw ValidationException::withMessages(['currency' => 'არჩეული ვალუტა არასწორია.']);
            }

            if (self::toCents($payment->amount) <= 0) {
                throw ValidationException::withMessages([
                    'amount' => 'გადახდის თანხა უნდა იყოს 0-ზე მეტი.',
                ]);
            }

            if (! in_array($payment->payment_method, self::METHODS, true)) {
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
            app(CashboxManager::class)->syncPayment($payment);
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

    public function getMethodDisplayAttribute(): string
    {
        $splits = $this->relationLoaded('splits') ? $this->splits : $this->splits()->oldest('id')->get();

        return $splits->map(fn (PaymentSplit $split): string => self::METHOD_LABELS[$split->payment_method]
            .' '.Currency::format($split->amount, $split->currency))->join(' + ');
    }

    /** @param array<int, array{payment_method: string, amount: mixed}> $splits */
    public static function createWithSplits(array $attributes, array $splits): self
    {
        self::validateSplits($attributes['amount'] ?? null, $splits);

        return DB::transaction(function () use ($attributes, $splits): self {
            $payment = new self([
                ...$attributes,
                'payment_method' => $splits[0]['payment_method'],
            ]);
            $payment->skipDefaultSplit = true;
            $payment->save();

            $payment->splits()->createMany(collect($splits)->map(fn (array $split): array => [
                ...$split,
                'currency' => $payment->currency,
            ])->all());

            return $payment->load('splits');
        });
    }

    /** @param array<int, array{payment_method: string, amount: mixed}> $splits */
    public static function validateSplits(mixed $amount, array $splits): void
    {
        if ($splits === []) {
            throw ValidationException::withMessages(['splits' => 'დაამატეთ გადახდის მეთოდი.']);
        }

        $methods = collect($splits)->pluck('payment_method');

        if ($methods->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['splits' => 'ერთი გადახდის მეთოდი ორჯერ ვერ დაემატება.']);
        }

        $splitTotal = collect($splits)->sum(fn (array $split): int => self::toCents($split['amount'] ?? 0));

        if ($splitTotal !== self::toCents($amount)) {
            throw ValidationException::withMessages([
                'splits' => 'გადახდის მეთოდების თანხების ჯამი უნდა უდრიდეს გადახდის საერთო თანხას.',
            ]);
        }
    }

    /** @param array<int, array{payment_method: string, amount: mixed}> $splits */
    public function replaceSplits(array $splits): void
    {
        self::validateSplits($this->amount, $splits);

        DB::transaction(function () use ($splits): void {
            $oldValues = $this->splits()->oldest('id')->get()
                ->map->only(['payment_method', 'amount', 'currency'])->values()->all();

            $this->splits()->delete();
            $this->splits()->createMany(collect($splits)->map(fn (array $split): array => [
                ...$split,
                'currency' => $this->currency,
            ])->all());
            $this->audit('splits_updated', ['splits' => $oldValues], ['splits' => $splits]);
        });
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
        $normalized = str_replace([',', ' '], '', trim((string) ($amount ?? 0)));

        return (int) round((float) $normalized * 100);
    }
}
