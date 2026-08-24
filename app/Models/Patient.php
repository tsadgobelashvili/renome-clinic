<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Validation\ValidationException;

class Patient extends Model
{
    /** @var array{gross_amount: float, discount_amount: float, net_amount: float, paid_amount: float, remaining_amount: float}|null */
    protected ?array $financialSummaryCache = null;

    protected ?array $financialSummariesByCurrencyCache = null;

    protected ?Visit $latestVisitCache = null;

    protected ?Payment $latestPaymentCache = null;

    protected $fillable = [
        'first_name',
        'last_name',
        'phone',
        'personal_id',
        'birth_date',
        'notes',
    ];

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::saving(function (Patient $patient): void {
            $patient->first_name = trim((string) $patient->first_name);
            $patient->last_name = trim((string) $patient->last_name);
            $patient->phone = self::nullableTrim($patient->phone);
            $patient->personal_id = self::nullableTrim($patient->personal_id);

            if ($patient->personal_id === null) {
                return;
            }

            $duplicateExists = self::query()
                ->where('personal_id', $patient->personal_id)
                ->when($patient->exists, fn (Builder $query): Builder => $query->whereKeyNot($patient->getKey()))
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'personal_id' => 'ამ პირადი ნომრით პაციენტი უკვე არსებობს.',
                ]);
            }
        });
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Visit::class);
    }

    public function scopeSearchForClinic(Builder $query, string $search): Builder
    {
        $terms = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $query->where(function (Builder $query) use ($terms): void {
            foreach ($terms as $term) {
                $pattern = '%'.mb_strtolower($term).'%';

                $query->where(function (Builder $query) use ($pattern): void {
                    $query->whereRaw('LOWER(first_name) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(personal_id) LIKE ?', [$pattern]);
                });
            }
        });
    }

    public function treatmentEstimates(): HasMany
    {
        return $this->hasMany(TreatmentEstimate::class);
    }

    /** @return array{gross_amount: float, discount_amount: float, net_amount: float, paid_amount: float, remaining_amount: float} */
    public function getFinancialSummary(): array
    {
        return $this->getFinancialSummariesByCurrency()['GEL'] ?? [
            'gross_amount' => 0.0, 'discount_amount' => 0.0, 'net_amount' => 0.0,
            'paid_amount' => 0.0, 'remaining_amount' => 0.0,
        ];
    }

    /** @return array<string, array{gross_amount: float, discount_amount: float, net_amount: float, paid_amount: float, remaining_amount: float}> */
    public function getFinancialSummariesByCurrency(): array
    {
        if ($this->financialSummariesByCurrencyCache !== null) {
            return $this->financialSummariesByCurrencyCache;
        }

        $visits = $this->relationLoaded('visits')
            ? $this->visits->whereNotNull('total_price')
            : $this->visits()->whereNotNull('total_price')->get(['id', 'currency', 'total_price', 'discount_amount']);
        $payments = $this->relationLoaded('visits')
            && $this->visits->every(fn (Visit $visit): bool => $visit->relationLoaded('payments'))
            ? $this->visits->flatMap->payments
            : $this->payments()->get(['payments.visit_id', 'payments.currency', 'payments.amount']);
        $visitCurrencies = $visits->pluck('currency', 'id');
        $currencies = $visits->pluck('currency')->merge($payments->pluck('currency'))->unique();

        return $this->financialSummariesByCurrencyCache = $currencies->mapWithKeys(function (string $currency) use ($visits, $payments, $visitCurrencies): array {
            $currencyVisits = $visits->where('currency', $currency);
            $gross = round((float) $currencyVisits->sum('total_price'), 2);
            $discount = round((float) $currencyVisits->sum('discount_amount'), 2);
            $paid = round((float) $payments->where('currency', $currency)->sum('amount'), 2);
            $paidAgainstBalance = round((float) $payments->where('currency', $currency)
                ->filter(fn (Payment $payment): bool => $visitCurrencies->get($payment->visit_id) === $currency)
                ->sum('amount'), 2);

            return [$currency => [
                'gross_amount' => $gross,
                'discount_amount' => $discount,
                'net_amount' => round($gross - $discount, 2),
                'paid_amount' => $paid,
                'remaining_amount' => round($gross - $discount - $paidAgainstBalance, 2),
            ]];
        })->all();
    }

    public function getLatestVisitRecord(): ?Visit
    {
        return $this->latestVisitCache ??= $this->visits()
            ->with(['doctor', 'treatmentCaseItems.treatmentCase', 'payments'])
            ->orderByDesc('visit_date')
            ->orderByDesc('id')
            ->first();
    }

    public function getLatestPaymentRecord(): ?Payment
    {
        return $this->latestPaymentCache ??= $this->payments()
            ->with(['visit', 'splits'])
            ->orderByDesc('payment_date')
            ->orderByDesc('payments.created_at')
            ->orderByDesc('payments.id')
            ->first();
    }

    private static function nullableTrim(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
