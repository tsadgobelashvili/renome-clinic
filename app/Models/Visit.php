<?php

namespace App\Models;

use App\Support\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class Visit extends Model
{
    public const CONSULTATION_SOURCES = [
        'our_patient' => 'ჩვენი პაციენტი',
        'other_clinic' => 'სხვა კლინიკიდან',
    ];

    protected $fillable = [
        'patient_id',
        'doctor_id',
        'visit_date',
        'visit_type',
        'consultation_source',
        'consultation_fee',
        'treatment_estimate_id',
        'treatment_estimate_option_id',
        'total_price',
        'currency',
        'discount_amount',
        'discount_type',
        'discount_value',
        'discount_comment',
        'complaint',
        'diagnosis',
        'treatment_notes',
        'doctor_notes',
        'comment',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'visit_date' => 'date',
            'consultation_fee' => 'decimal:2',
            'total_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'discount_value' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Visit $visit): void {
            $visit->visit_type = $visit->visit_type ?: 'treatment';
            $visit->currency = $visit->currency ?: Currency::DEFAULT;

            if (! Currency::isSupported($visit->currency)) {
                throw ValidationException::withMessages(['currency' => 'არჩეული ვალუტა არასწორია.']);
            }

            if (! in_array($visit->visit_type, ['consultation', 'treatment'], true)) {
                throw ValidationException::withMessages([
                    'visit_type' => 'ვიზიტის ტიპი არასწორია.',
                ]);
            }

            if ($visit->visit_type === 'consultation') {
                $visit->treatment_estimate_id = null;
                $visit->treatment_estimate_option_id = null;
                $visit->consultation_source = $visit->consultation_source ?: 'our_patient';
                $visit->consultation_fee ??= 0;
            } else {
                $visit->consultation_source = null;
                $visit->consultation_fee = 0;
            }

            if ($visit->consultation_source !== null && ! array_key_exists($visit->consultation_source, self::CONSULTATION_SOURCES)) {
                throw ValidationException::withMessages([
                    'consultation_source' => 'აირჩიეთ კონსულტაციის სწორი წყარო.',
                ]);
            }

            if (self::toCents($visit->consultation_fee) < 0) {
                throw ValidationException::withMessages([
                    'consultation_fee' => 'კონსულტაციის ფასი უარყოფითი ვერ იქნება.',
                ]);
            }

            if ($visit->treatment_estimate_id !== null) {
                $estimateMatchesPatient = TreatmentEstimate::query()
                    ->whereKey($visit->treatment_estimate_id)
                    ->where('patient_id', $visit->patient_id)
                    ->exists();

                if (! $estimateMatchesPatient) {
                    throw ValidationException::withMessages([
                        'treatment_estimate_id' => 'გეგმა არჩეულ პაციენტს არ ეკუთვნის.',
                    ]);
                }
            }

            if ($visit->treatment_estimate_option_id !== null) {
                $optionMatchesEstimate = TreatmentEstimateOption::query()
                    ->whereKey($visit->treatment_estimate_option_id)
                    ->where('treatment_estimate_id', $visit->treatment_estimate_id)
                    ->exists();

                if (! $optionMatchesEstimate) {
                    throw ValidationException::withMessages([
                        'treatment_estimate_option_id' => 'არჩეული ვარიანტი გეგმას არ ეკუთვნის.',
                    ]);
                }
            }

            $visit->discount_type = $visit->discount_type ?: 'amount';

            if (! in_array($visit->discount_type, ['amount', 'percent'], true)) {
                throw ValidationException::withMessages([
                    'discount_type' => 'ფასდაკლების ტიპი არასწორია.',
                ]);
            }

            if ((! $visit->exists) && (! $visit->isDirty('discount_value')) && $visit->isDirty('discount_amount')) {
                $visit->discount_value = $visit->discount_amount;
            }

            $visit->discount_value ??= 0;

            if (self::toCents($visit->discount_value) < 0) {
                throw ValidationException::withMessages([
                    'discount_value' => 'ფასდაკლება უარყოფითი ვერ იქნება.',
                ]);
            }

            if (($visit->discount_type === 'percent') && ((float) $visit->discount_value > 100)) {
                throw ValidationException::withMessages([
                    'discount_value' => 'ფასდაკლების პროცენტი 100-ზე მეტი ვერ იქნება.',
                ]);
            }

            $visit->discount_amount = $visit->calculateDiscountAmount();

            if (($visit->total_price !== null) && (self::toCents($visit->total_price) < 0)) {
                throw ValidationException::withMessages([
                    'total_price' => 'მომსახურების სრული ღირებულება უარყოფითი ვერ იქნება.',
                ]);
            }

            if (self::toCents($visit->discount_amount) < 0) {
                throw ValidationException::withMessages([
                    'discount_amount' => 'ფასდაკლება უარყოფითი ვერ იქნება.',
                ]);
            }

            if ($visit->total_price === null) {
                return;
            }

            if (self::toCents($visit->discount_amount) > self::toCents($visit->total_price)) {
                throw ValidationException::withMessages([
                    'discount_amount' => 'ფასდაკლება მომსახურების სრულ ღირებულებას ვერ გადააჭარბებს.',
                ]);
            }

            $paidAmount = $visit->exists
                ? $visit->payments()->where('currency', $visit->currency)->sum('amount')
                : 0;

            if (self::toCents($paidAmount) > self::toCents($visit->net_amount)) {
                throw ValidationException::withMessages([
                    'discount_amount' => 'ფასდაკლების შემდეგ გადასახდელი თანხა უკვე გადახდილ თანხაზე ნაკლები ვერ იქნება.',
                ]);
            }
        });
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function treatmentCaseItems(): HasMany
    {
        return $this->hasMany(VisitTreatmentCase::class);
    }

    public function treatmentCases(): BelongsToMany
    {
        return $this->belongsToMany(TreatmentCase::class, 'visit_treatment_cases')
            ->withPivot(['quantity', 'unit_price', 'teeth', 'comment'])
            ->withTimestamps();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @param array<int|string, array<string, mixed>> $items */
    public static function totalFromTreatmentItemState(array $items, mixed $legacyTotal = null, mixed $additionalAmount = 0): float
    {
        $total = round((float) $additionalAmount + collect($items)->sum(function (array $item): float {
            $hasService = filled($item['treatment_case_id'] ?? null) || filled($item['custom_service_name'] ?? null);
            $quantity = $hasService && blank($item['quantity'] ?? null)
                ? 1
                : (int) ($item['quantity'] ?? 0);

            return $quantity * (float) ($item['unit_price'] ?? 0);
        }), 2);

        if ($total === 0.0 && $items !== [] && (float) $legacyTotal > 0) {
            return round((float) $legacyTotal, 2);
        }

        return $total;
    }

    public function syncTreatmentItemsTotal(): void
    {
        $items = $this->treatmentCaseItems()->get(['quantity', 'unit_price'])
            ->map->only(['quantity', 'unit_price'])->all();
        $total = self::totalFromTreatmentItemState(
            $items,
            $this->total_price,
            $this->visit_type === 'consultation' ? $this->consultation_fee : 0,
        );

        if ((float) $this->total_price !== $total) {
            $this->forceFill(['total_price' => $total])->saveQuietly();
        }
    }

    public function treatmentEstimate(): BelongsTo
    {
        return $this->belongsTo(TreatmentEstimate::class);
    }

    public function treatmentEstimateOption(): BelongsTo
    {
        return $this->belongsTo(TreatmentEstimateOption::class);
    }

    public function treatmentEstimates(): HasMany
    {
        return $this->hasMany(TreatmentEstimate::class);
    }

    public function getPaidAmountAttribute(): float
    {
        $sum = $this->relationLoaded('payments')
            ? $this->payments->where('currency', $this->currency)->sum('amount')
            : $this->payments()->where('currency', $this->currency)->sum('amount');

        return round((float) $sum, 2);
    }

    public function getGrossAmountAttribute(): ?float
    {
        return $this->total_price === null ? null : round((float) $this->total_price, 2);
    }

    public function getNetAmountAttribute(): ?float
    {
        if ($this->total_price === null) {
            return null;
        }

        return round((float) $this->total_price - (float) $this->discount_amount, 2);
    }

    public function getRemainingAmountAttribute(): ?float
    {
        if ($this->net_amount === null) {
            return null;
        }

        return round($this->net_amount - $this->paid_amount, 2);
    }

    public function getPaymentStatusAttribute(): string
    {
        if ($this->net_amount === null) {
            return 'unpriced';
        }

        if (($this->gross_amount > 0) && ($this->net_amount === 0.0)) {
            return 'free';
        }

        return $this->remaining_amount <= 0 ? 'paid' : 'due';
    }

    public function getDiscountDisplayAttribute(): string
    {
        if ($this->discount_type === 'percent') {
            return number_format((float) $this->discount_value, 2).'% ('.Currency::format($this->discount_amount, $this->currency).')';
        }

        return Currency::format($this->discount_amount, $this->currency);
    }

    public function calculateDiscountAmount(): float
    {
        if ($this->discount_type === 'percent') {
            if ($this->total_price === null) {
                return 0.0;
            }

            return round((float) $this->total_price * (float) $this->discount_value / 100, 2);
        }

        return round((float) ($this->discount_value ?? $this->discount_amount ?? 0), 2);
    }

    private static function toCents(mixed $amount): int
    {
        return (int) round((float) ($amount ?? 0) * 100);
    }
}
