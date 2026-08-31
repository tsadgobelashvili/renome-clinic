<?php

namespace App\Models;

use App\Services\PatientDoctorAssignment;
use App\Support\Currency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Validation\ValidationException;

class VisitTreatmentCase extends Model
{
    private const SALARY_EXCLUDED_CATEGORIES = ['consultation', 'tomography'];

    protected $fillable = [
        'visit_id',
        'treatment_case_id',
        'custom_service_name',
        'quantity',
        'unit_price',
        'currency',
        'exchange_rate',
        'teeth',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2', 'exchange_rate' => 'decimal:6',
        ];
    }

    public function scopeSalaryUnsettled(Builder $query): Builder
    {
        return $query->whereDoesntHave(
            'salarySettlementItem.settlement',
            fn (Builder $query): Builder => $query->where('status', 'confirmed'),
        );
    }

    public function scopeSalaryEligible(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('treatment_case_id')
                ->orWhereHas('treatmentCase', fn (Builder $treatmentCase): Builder => $treatmentCase
                    ->whereNotIn('category', self::SALARY_EXCLUDED_CATEGORIES));
        });
    }

    public function isSalaryEligible(): bool
    {
        return $this->treatment_case_id === null
            || ! in_array($this->treatmentCase?->category, self::SALARY_EXCLUDED_CATEGORIES, true);
    }

    protected static function booted(): void
    {
        static::saving(function (VisitTreatmentCase $item): void {
            $item->custom_service_name = self::normalizeText($item->custom_service_name);

            if (filled($item->treatment_case_id)) {
                $item->custom_service_name = null;
            } elseif (blank($item->custom_service_name)) {
                throw ValidationException::withMessages([
                    'custom_service_name' => 'მიუთითეთ მანიპულაციის დასახელება.',
                ]);
            }

            if ((int) $item->quantity < 1) {
                throw ValidationException::withMessages([
                    'quantity' => 'რაოდენობა უნდა იყოს მინიმუმ 1.',
                ]);
            }

            $rawUnitPrice = $item->getAttributes()['unit_price'] ?? null;

            if ($rawUnitPrice === null || $rawUnitPrice === '') {
                throw ValidationException::withMessages([
                    'unit_price' => 'მიუთითეთ შესრულებული მანიპულაციის ფასი.',
                ]);
            }

            if ((float) $item->unit_price < 0) {
                throw ValidationException::withMessages([
                    'unit_price' => 'ერთეულის ფასი უარყოფითი ვერ იქნება.',
                ]);
            }
            $visitCurrency = $item->visit()->value('currency') ?: Currency::DEFAULT;
            $item->currency = $item->currency ?: $visitCurrency;
            if (! Currency::isSupported($item->currency)) {
                throw ValidationException::withMessages(['currency' => 'არჩეული ვალუტა არასწორია.']);
            }
            if ($item->currency !== $visitCurrency && (float) $item->exchange_rate <= 0) {
                throw ValidationException::withMessages(['exchange_rate' => 'განსხვავებული ვალუტისთვის მიუთითეთ კურსი.']);
            }

            if ($item->exists && $item->directExpenses()
                ->where('currency', $item->visit()->value('currency') ?: 'GEL')
                ->sum('amount') > $item->manipulation_total) {
                throw ValidationException::withMessages([
                    'unit_price' => 'პირდაპირი ხარჯების ჯამი ვერ იქნება მანიპულაციის თანხაზე მეტი.',
                ]);
            }

            $visit = $item->visit()->first();
            $treatmentCase = $item->treatmentCase()->first();

            if ((! $visit) || (filled($item->treatment_case_id) && (! $treatmentCase))) {
                throw ValidationException::withMessages([
                    'treatment_case_id' => 'არჩეული მკურნალობის კატალოგის ჩანაწერი ვერ მოიძებნა.',
                ]);
            }

            $item->teeth = self::normalizeText($item->teeth);
            $item->comment = self::normalizeText($item->comment);
            $item->fingerprint = self::makeFingerprint(
                filled($item->treatment_case_id) ? (int) $item->treatment_case_id : null,
                (int) $item->quantity,
                $item->teeth,
                $item->comment,
                $item->custom_service_name,
            );

            $duplicateExists = self::query()
                ->where('visit_id', $item->visit_id)
                ->where('fingerprint', $item->fingerprint)
                ->when($item->exists, fn ($query) => $query->whereKeyNot($item->getKey()))
                ->exists();

            if ($duplicateExists) {
                throw ValidationException::withMessages([
                    'treatment_case_id' => 'ზუსტად ასეთი მკურნალობის ჩანაწერი Visit-ში უკვე დამატებულია.',
                ]);
            }
        });

        static::saved(function (VisitTreatmentCase $item): void {
            if ($visit = $item->visit()->first()) {
                app(PatientDoctorAssignment::class)->assignFromVisit($visit);
            }
        });

    }

    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    public function treatmentCase(): BelongsTo
    {
        return $this->belongsTo(TreatmentCase::class);
    }

    public function directExpenses(): HasMany
    {
        return $this->hasMany(DirectExpense::class);
    }

    public function salarySettlementItem(): HasOne
    {
        return $this->hasOne(SalarySettlementItem::class);
    }

    public function getManipulationTotalAttribute(): float
    {
        return round((int) $this->quantity * (float) $this->unit_price, 2);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->treatmentCase?->name ?? $this->custom_service_name ?? '—';
    }

    public function getCategoryLabelAttribute(): string
    {
        return $this->treatmentCase?->category_label ?? 'ხელით დამატებული';
    }

    public function getDirectExpensesTotalAttribute(): float
    {
        $currency = $this->relationLoaded('visit')
            ? ($this->visit?->currency ?: 'GEL')
            : ($this->visit()->value('currency') ?: 'GEL');

        return round((float) ($this->relationLoaded('directExpenses')
            ? $this->directExpenses->where('currency', $currency)->sum('amount')
            : $this->directExpenses()->where('currency', $currency)->sum('amount')), 2);
    }

    public function getNetAmountAttribute(): float
    {
        return round($this->manipulation_total - $this->direct_expenses_total, 2);
    }

    public static function makeFingerprint(?int $treatmentCaseId, int $quantity, ?string $teeth, ?string $comment, ?string $customServiceName = null): string
    {
        return hash('sha256', json_encode([
            $treatmentCaseId ?? 'manual:'.mb_strtolower((string) self::normalizeText($customServiceName)),
            $quantity,
            self::normalizeText($teeth),
            self::normalizeText($comment),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function normalizeText(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }
}
