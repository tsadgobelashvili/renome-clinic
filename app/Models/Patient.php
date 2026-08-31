<?php

namespace App\Models;

use App\Support\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Facades\DB;
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
        'patient_group_id',
    ];

    protected function casts(): array
    {
        return ['birth_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::creating(function (Patient $patient): void {
            if (blank($patient->patient_group_id)) {
                $patient->patient_group_id = PatientGroup::clinicId();
            }

            if (filled($patient->patient_number)) {
                throw ValidationException::withMessages([
                    'patient_number' => 'პაციენტის ნომერი ავტომატურად ენიჭება.',
                ]);
            }

            $counter = DB::selectOne(
                'UPDATE patient_number_counters SET next_number = next_number + 1 WHERE id = 1 RETURNING next_number - 1 AS patient_number'
            );
            $patient->patient_number = (int) $counter->patient_number;
        });

        static::updating(function (Patient $patient): void {
            if ($patient->isDirty('patient_number')) {
                throw ValidationException::withMessages([
                    'patient_number' => 'პაციენტის ნომრის შეცვლა შეუძლებელია.',
                ]);
            }
        });

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

    public function getFormattedPatientNumberAttribute(): string
    {
        return '№ '.str_pad((string) $this->patient_number, 6, '0', STR_PAD_LEFT);
    }

    public function visits(): HasMany
    {
        return $this->hasMany(Visit::class);
    }

    public function labCases(): HasMany
    {
        return $this->hasMany(LabCase::class);
    }

    public function patientGroup(): BelongsTo
    {
        return $this->belongsTo(PatientGroup::class);
    }

    public function isIsraelPartner(): bool
    {
        $slug = $this->relationLoaded('patientGroup')
            ? $this->patientGroup?->slug
            : $this->patientGroup()->value('slug');

        return $slug === PatientGroup::ISRAEL_PARTNER_SLUG;
    }

    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'patient_doctor')
            ->withPivot(['id', 'is_primary', 'role', 'assignment_source'])
            ->withTimestamps();
    }

    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Visit::class);
    }

    public function scopeSearchForClinic(Builder $query, string $search): Builder
    {
        $terms = preg_split('/\s+/u', trim(str_replace('№', '', $search)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $query->where(function (Builder $query) use ($terms): void {
            foreach ($terms as $term) {
                $pattern = '%'.mb_strtolower($term).'%';

                $query->where(function (Builder $query) use ($pattern, $term): void {
                    $query->whereRaw('LOWER(first_name) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(phone) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(personal_id) LIKE ?', [$pattern]);

                    if (ctype_digit($term)) {
                        $query->orWhere('patient_number', (int) $term);
                    }
                });
            }
        });
    }

    public function scopeWhereHasClinicDebt(Builder $query, bool $hasDebt = true): Builder
    {
        $debtExists = fn ($debtQuery) => $debtQuery
            ->selectRaw('1')
            ->from('visits as debt_visits')
            ->whereColumn('debt_visits.patient_id', 'patients.id')
            ->groupBy('debt_visits.currency')
            ->havingRaw('SUM('.self::visitOutstandingSql('debt_visits').') > 0.005');

        if ($hasDebt) {
            return $query
                ->where('patient_group_id', PatientGroup::clinicId())
                ->whereExists($debtExists);
        }

        return $query->where(fn (Builder $query): Builder => $query
            ->where('patient_group_id', '!=', PatientGroup::clinicId())
            ->orWhereNotExists($debtExists));
    }

    public function scopeWithClinicDebtBalances(Builder $query): Builder
    {
        foreach (array_keys(Currency::OPTIONS) as $currency) {
            $query->addSelect([
                'remaining_amount_'.strtolower($currency) => Visit::query()
                    ->selectRaw('COALESCE(SUM('.self::visitOutstandingSql('visits').'), 0)')
                    ->whereColumn('patient_id', 'patients.id')
                    ->whereRaw('patients.patient_group_id = ?', [PatientGroup::clinicId()])
                    ->where('currency', $currency),
            ]);
        }

        return $query;
    }

    public function scopeWhereLatestVisitBetween(Builder $query, string $from, string $until): Builder
    {
        return $query
            ->whereHas('visits', fn (Builder $query): Builder => $query
                ->whereDate('visit_date', '>=', $from)
                ->whereDate('visit_date', '<=', $until))
            ->whereDoesntHave('visits', fn (Builder $query): Builder => $query
                ->whereDate('visit_date', '>', $until));
    }

    public function scopeOrderByClinicDebt(Builder $query, string $direction): Builder
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        foreach (array_keys(Currency::OPTIONS) as $currency) {
            $query->orderBy(
                Visit::query()
                    ->selectRaw('COALESCE(SUM('.self::visitOutstandingSql('visits').'), 0)')
                    ->whereColumn('patient_id', 'patients.id')
                    ->whereRaw('patients.patient_group_id = ?', [PatientGroup::clinicId()])
                    ->where('currency', $currency),
                $direction,
            );
        }

        return $query;
    }

    public function scopeOrderByLatestVisit(Builder $query, string $direction): Builder
    {
        return $query->orderBy(
            Visit::query()
                ->selectRaw('MAX(visit_date)')
                ->whereColumn('patient_id', 'patients.id'),
            strtolower($direction) === 'asc' ? 'asc' : 'desc',
        );
    }

    public function treatmentEstimates(): HasMany
    {
        return $this->hasMany(TreatmentEstimate::class);
    }

    public function productSales(): HasMany
    {
        return $this->hasMany(ProductSale::class);
    }

    public function partnerPayments(): HasMany
    {
        return $this->hasMany(PartnerPatientPayment::class);
    }

    /** @return array<string, float> */
    public function getPartnerPaymentTotals(): array
    {
        return $this->partnerPayments()
            ->selectRaw('currency, SUM(amount) as total')
            ->groupBy('currency')
            ->pluck('total', 'currency')
            ->map(fn (mixed $amount): float => round((float) $amount, 2))
            ->all();
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

        if ($this->isIsraelPartner()) {
            return $this->financialSummariesByCurrencyCache = [];
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

    private static function visitOutstandingSql(string $visitAlias): string
    {
        return "COALESCE({$visitAlias}.total_price, 0)"
            ." - COALESCE({$visitAlias}.discount_amount, 0)"
            .' - COALESCE((SELECT SUM(debt_payments.amount) FROM payments AS debt_payments'
            ." WHERE debt_payments.visit_id = {$visitAlias}.id"
            ." AND debt_payments.currency = {$visitAlias}.currency), 0)";
    }
}
