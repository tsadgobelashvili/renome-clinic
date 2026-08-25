<?php

namespace App\Services;

use App\Models\DirectExpense;
use App\Models\VisitTreatmentCase;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DirectExpenseService
{
    public function save(VisitTreatmentCase $item, ?int $expenseId, mixed $name, mixed $amount): DirectExpense
    {
        $name = trim((string) $name);
        $amount = Money::decimal($amount);

        if ($name === '') {
            throw ValidationException::withMessages(['expense' => 'მიუთითეთ ხარჯის წყარო ან დასახელება.']);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['expense' => 'ხარჯის თანხა უნდა იყოს 0-ზე მეტი.']);
        }

        return DB::transaction(function () use ($item, $expenseId, $name, $amount): DirectExpense {
            $currency = $item->visit()->value('currency') ?: Currency::DEFAULT;
            $otherExpenses = $item->directExpenses()
                ->where('currency', $currency)
                ->when($expenseId, fn (Builder $query): Builder => $query->whereKeyNot($expenseId))
                ->lockForUpdate()
                ->get(['amount']);
            $newTotal = $otherExpenses->sum(fn (DirectExpense $expense): int => Money::minorUnits($expense->amount))
                + Money::minorUnits($amount);

            if ($newTotal > Money::minorUnits($item->manipulation_total)) {
                throw ValidationException::withMessages([
                    'expense' => 'პირდაპირი ხარჯების ჯამი ვერ იქნება მანიპულაციის თანხაზე მეტი.',
                ]);
            }

            $attributes = ['name' => $name, 'amount' => $amount, 'currency' => $currency];

            if ($expenseId) {
                $expense = $item->directExpenses()->whereKey($expenseId)->firstOrFail();
                $expense->update($attributes);

                return $expense;
            }

            return $item->directExpenses()->create($attributes);
        });
    }

    public function delete(VisitTreatmentCase $item, int $expenseId): void
    {
        $item->directExpenses()->whereKey($expenseId)->firstOrFail()->delete();
    }
}
