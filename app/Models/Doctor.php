<?php

namespace App\Models;

use App\Services\DoctorCompensationCalculator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Doctor extends Model
{
    /** @var array{visits_count: int, gross_amount: float, discount_amount: float, net_amount: float, paid_amount: float, remaining_amount: float}|null */
    protected ?array $financialSummaryCache = null;

    protected ?array $compensationSummaryCache = null;

    protected $fillable = [
        'first_name',
        'last_name',
        'phone',
        'specialty',
        'compensation_percentage',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'compensation_percentage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Doctor $doctor): void {
            if ($doctor->compensation_percentage === null) {
                return;
            }

            if ((float) $doctor->compensation_percentage < 0 || (float) $doctor->compensation_percentage > 100) {
                throw ValidationException::withMessages([
                    'compensation_percentage' => 'ექიმის პროცენტი უნდა იყოს 0-დან 100-მდე.',
                ]);
            }
        });
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function scopeSearchByName(Builder $query, string $search): Builder
    {
        $terms = preg_split('/\s+/u', trim($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $query->where(function (Builder $query) use ($terms): void {
            foreach ($terms as $term) {
                $query->where(function (Builder $query) use ($term): void {
                    $pattern = '%'.mb_strtolower($term).'%';

                    $query
                        ->whereRaw('LOWER(first_name) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$pattern]);
                });
            }
        });
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function patients(): BelongsToMany
    {
        return $this->belongsToMany(Patient::class, 'patient_doctor')
            ->withPivot('is_primary')
            ->withTimestamps();
    }

    public function treatmentEstimates(): HasMany
    {
        return $this->hasMany(TreatmentEstimate::class);
    }

    public function salarySettlements(): HasMany
    {
        return $this->hasMany(SalarySettlement::class);
    }

    /** @return array<string, mixed> */
    public function getCompensationSummary(): array
    {
        return $this->compensationSummaryCache ??= app(DoctorCompensationCalculator::class)->summary($this);
    }

    public function clearCompensationSummaryCache(): void
    {
        $this->compensationSummaryCache = null;
    }

    /** @return array{visits_count: int, gross_amount: float, discount_amount: float, net_amount: float, paid_amount: float, remaining_amount: float} */
    public function getFinancialSummary(): array
    {
        return ['visits_count' => $this->visits()->count(), ...($this->getFinancialSummariesByCurrency()['GEL'] ?? [
            'gross_amount' => 0.0, 'discount_amount' => 0.0, 'net_amount' => 0.0,
            'paid_amount' => 0.0, 'remaining_amount' => 0.0,
        ])];
    }

    /** @return array<string, array{gross_amount: float, discount_amount: float, net_amount: float, paid_amount: float, remaining_amount: float}> */
    public function getFinancialSummariesByCurrency(): array
    {
        $visits = $this->visits()->whereNotNull('total_price')->get(['id', 'currency', 'total_price', 'discount_amount']);
        $payments = Payment::query()->whereHas('visit', fn ($query) => $query->where('doctor_id', $this->getKey()))
            ->get(['visit_id', 'currency', 'amount']);
        $visitCurrencies = $visits->pluck('currency', 'id');
        $currencies = $visits->pluck('currency')->merge($payments->pluck('currency'))->unique();

        return $currencies->mapWithKeys(function (string $currency) use ($visits, $payments, $visitCurrencies): array {
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
}
