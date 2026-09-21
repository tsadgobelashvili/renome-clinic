<?php

namespace App\Models;

use App\Services\DoctorCompensationCalculator;
use App\Support\GeorgianNameTransliterator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class Doctor extends Model
{
    /** @var array{visits_count: int, gross_amount: float, discount_amount: float, net_amount: float, paid_amount: float, remaining_amount: float}|null */
    protected ?array $financialSummaryCache = null;

    protected ?array $compensationSummaryCache = null;

    protected $fillable = [
        'first_name',
        'last_name',
        'first_name_en',
        'last_name_en',
        'phone',
        'specialty',
        'specialties',
        'israeli_lab_pmma_rate',
        'compensation_percentage',
        'israeli_lab_zircon_rate',
        'compensation_category_percentages',
        'owner_split_key',
        'owner_split_enabled',
        'clinic_salary_payment_method',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'specialties' => 'array',
            'israeli_lab_pmma_rate' => 'decimal:2',
            'compensation_percentage' => 'decimal:2',
            'israeli_lab_zircon_rate' => 'decimal:2',
            'compensation_category_percentages' => 'array',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Doctor $doctor) {
            if ($doctor->isDirty('clinic_salary_payment_method')) {
                validator(['payment_method' => $doctor->clinic_salary_payment_method], ['payment_method' => 'required|in:cash,bank_transfer'])->validate();
            }
        });
        static::saving(function (Doctor $doctor): void {
            validator($doctor->getAttributes(), [
                'compensation_percentage' => 'nullable|numeric|min:0|max:100',
                'israeli_lab_zircon_rate' => 'nullable|numeric|min:0|max:99999999.99',
                'israeli_lab_pmma_rate' => 'nullable|numeric|min:0|max:99999999.99',
            ])->validate();
            validator(['specialties' => $doctor->specialties, 'rates' => $doctor->compensation_category_percentages], [
                'specialties' => 'nullable|array',
                'specialties.*' => 'string|distinct|in:'.implode(',', array_keys(TreatmentCase::CATEGORIES)),
                'rates' => 'nullable|array', 'rates.*' => 'nullable|numeric|min:0|max:100',
            ])->validate();
            if ($doctor->exists && $doctor->isDirty('compensation_category_percentages')) {
                // Hidden specialty fields retain their historical configuration.
                $doctor->compensation_category_percentages = array_replace(
                    json_decode($doctor->getRawOriginal('compensation_category_percentages') ?? '[]', true) ?? [],
                    $doctor->compensation_category_percentages ?? [],
                );
            }
            if ($doctor->isDirty('specialties')) {
                $doctor->specialty = TreatmentCase::CATEGORIES[$doctor->specialties[0] ?? ''] ?? null;
            }
            // Existing payout forms still carry one default/override percentage.
            // Initialize it from an explicitly configured rate, never the doctor's name.
            if ($doctor->compensation_percentage === null) {
                $doctor->compensation_percentage = collect($doctor->compensation_category_percentages ?? [])
                    ->only($doctor->specialties ?? [])->first(fn ($rate) => is_numeric($rate) && $rate > 0);
            }
        });
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function labDisplayName(string $locale): string
    {
        if ($locale !== 'en') {
            return $this->full_name;
        }

        // Manually maintained spellings win. Legacy names are transliterated for display only.
        return trim(implode(' ', array_map(fn (string $field): string => filled($this->{$field.'_en'})
            ? trim($this->{$field.'_en'})
            : (GeorgianNameTransliterator::transliterate($this->{$field}) ?? $this->{$field} ?? ''), ['first_name', 'last_name'])));
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

    public function scopeLabSelectable(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->where(function (Builder $query): void {
                $query->where('is_active', true)->where(function (Builder $query): void {
                    $query->whereJsonContains('specialties', 'orthopedics')
                        ->orWhereIn(DB::raw('LOWER(TRIM(specialty))'), [
                            TreatmentCase::CATEGORIES['orthopedics'], 'ორთოპედი', 'orthopedics', 'orthopedist',
                            'orthopaedics', 'prosthodontics', 'prosthodontist',
                        ]);
                });
            })->orWhere(function (Builder $query): void {
                // Explicitly requested exception; no other identity-based eligibility.
                foreach ([['first_name', 'last_name'], ['first_name_en', 'last_name_en']] as [$first, $last]) {
                    $query->orWhere(fn (Builder $query) => $query
                        ->whereIn(DB::raw("LOWER(TRIM({$first}))"), ['otar', 'ოთარ'])
                        ->whereIn(DB::raw("LOWER(TRIM({$last}))"), ['ghreuli', 'ღრეული']));
                }
            });
        });
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function labCases(): HasMany
    {
        return $this->hasMany(LabCase::class);
    }

    public function patients(): BelongsToMany
    {
        return $this->belongsToMany(Patient::class, 'patient_doctor')
            ->withPivot(['id', 'is_primary', 'role', 'assignment_source'])
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

    public function incomingOwnerSalaryShares(): HasMany
    {
        return $this->hasMany(OwnerSalaryShare::class, 'recipient_doctor_id');
    }

    public function outgoingOwnerSalaryShares(): HasMany
    {
        return $this->hasMany(OwnerSalaryShare::class, 'source_doctor_id');
    }

    public function isOwnerSplitDoctor(): bool
    {
        return in_array($this->owner_split_key, ['levan', 'nodar'], true);
    }

    public function getOwnerSplitEnabledAttribute(): bool
    {
        return $this->isOwnerSplitDoctor();
    }

    public function setOwnerSplitEnabledAttribute(bool $enabled): void
    {
        if (! $enabled) {
            $this->owner_split_key = null;

            return;
        }

        if ($this->isOwnerSplitDoctor()) {
            return;
        }

        // Preserve the existing two-party arrangement and its unique stored keys.
        $available = array_diff(['levan', 'nodar'], static::query()->whereNotNull('owner_split_key')->pluck('owner_split_key')->all());
        if ($available === []) {
            throw ValidationException::withMessages([
                'data.owner_split_enabled' => 'Owner Split უკვე ჩართულია ორ ექიმზე. ჯერ გამორთეთ ერთ-ერთისთვის.',
            ]);
        }

        $this->owner_split_key = reset($available);
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
        $visits = $this->visits()
            ->whereHas('patient.patientGroup', fn ($query) => $query->where('slug', PatientGroup::CLINIC_SLUG))
            ->whereNotNull('total_price')->get(['id', 'currency', 'total_price', 'discount_amount']);
        $payments = Payment::query()->whereHas('visit', fn ($query) => $query
            ->where('doctor_id', $this->getKey())
            ->whereHas('patient.patientGroup', fn ($group) => $group->where('slug', PatientGroup::CLINIC_SLUG)))
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
