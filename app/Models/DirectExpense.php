<?php

namespace App\Models;

use App\Support\Currency;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

class DirectExpense extends Model
{
    protected $fillable = [
        'visit_treatment_case_id',
        'name',
        'amount',
        'currency',
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(function (DirectExpense $expense): void {
            $expense->currency = $expense->currency ?: Currency::DEFAULT;

            if (! Currency::isSupported($expense->currency)) {
                throw ValidationException::withMessages(['currency' => 'არჩეული ვალუტა არასწორია.']);
            }

            $expense->name = trim((string) $expense->name);

            if ($expense->name === '') {
                throw ValidationException::withMessages(['name' => 'ხარჯის დასახელება აუცილებელია.']);
            }

            if ((float) $expense->amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'ხარჯის თანხა უნდა იყოს 0-ზე მეტი.']);
            }

            $item = $expense->visitTreatmentCase()->first();

            if (! $item) {
                throw ValidationException::withMessages(['amount' => 'შესრულებული მანიპულაცია ვერ მოიძებნა.']);
            }

            $otherExpenses = (float) $item->directExpenses()
                ->where('currency', $expense->currency)
                ->when($expense->exists, fn ($query) => $query->whereKeyNot($expense->getKey()))
                ->sum('amount');

            $visitCurrency = $item->visit()->value('currency') ?: Currency::DEFAULT;

            if (($expense->currency === $visitCurrency)
                && ($otherExpenses + (float) $expense->amount) > $item->manipulation_total) {
                throw ValidationException::withMessages([
                    'amount' => 'პირდაპირი ხარჯების ჯამი ვერ იქნება მანიპულაციის თანხაზე მეტი.',
                ]);
            }
        });
    }

    public function visitTreatmentCase(): BelongsTo
    {
        return $this->belongsTo(VisitTreatmentCase::class);
    }
}
