<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

class VisitTreatmentCase extends Model
{
    protected $fillable = [
        'visit_id',
        'treatment_case_id',
        'quantity',
        'unit_price',
        'teeth',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (VisitTreatmentCase $item): void {
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

            if ($item->exists && $item->directExpenses()
                ->where('currency', $item->visit()->value('currency') ?: 'GEL')
                ->sum('amount') > $item->manipulation_total) {
                throw ValidationException::withMessages([
                    'unit_price' => 'პირდაპირი ხარჯების ჯამი ვერ იქნება მანიპულაციის თანხაზე მეტი.',
                ]);
            }

            $visit = $item->visit()->first();
            $treatmentCase = $item->treatmentCase()->first();

            if ((! $visit) || (! $treatmentCase)) {
                throw ValidationException::withMessages([
                    'treatment_case_id' => 'არჩეული მკურნალობის კატალოგის ჩანაწერი ვერ მოიძებნა.',
                ]);
            }

            $item->teeth = self::normalizeText($item->teeth);
            $item->comment = self::normalizeText($item->comment);
            $item->fingerprint = self::makeFingerprint(
                (int) $item->treatment_case_id,
                (int) $item->quantity,
                $item->teeth,
                $item->comment,
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

    public function getManipulationTotalAttribute(): float
    {
        return round((int) $this->quantity * (float) $this->unit_price, 2);
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

    public static function makeFingerprint(int $treatmentCaseId, int $quantity, ?string $teeth, ?string $comment): string
    {
        return hash('sha256', json_encode([
            $treatmentCaseId,
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
