<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Support\CashboxManager;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class PaymentProcessor
{
    public function __construct(private readonly CashboxManager $cashboxManager) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int|string, array<string, mixed>>  $rows
     */
    public function process(array $attributes, array $rows): Payment
    {
        $prepared = $this->prepare($attributes['amount'] ?? 0, $rows);
        $rows = $prepared['rows'];
        $amount = $prepared['amount'];

        try {
            return DB::transaction(function () use ($attributes, $rows, $amount): Payment {
                $payment = new Payment([
                    ...$attributes,
                    'amount' => $amount,
                    'currency' => $attributes['currency'] ?? Currency::DEFAULT,
                    'payment_method' => $rows[0]['payment_method'],
                ]);
                $payment->skipDefaultSplit = true;
                $payment->skipCashboxSync = true;
                $payment->save();

                $payment->splits()->createMany(collect($rows)->map(fn (array $row): array => [
                    ...$row,
                    'currency' => $payment->currency,
                ])->all());

                // Model events keep legacy writes synchronized; this explicit final sync
                // guarantees that the Cashier sees the complete split breakdown.
                $this->cashboxManager->syncPayment($payment->refresh());

                return $payment->load('splits', 'cashboxTransaction');
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw $exception;
        }
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function distributedMinorUnits(array $rows): int
    {
        return collect($rows)->sum(fn (array $row): int => Money::minorUnits($row['amount'] ?? 0));
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function remaining(mixed $amountDue, array $rows): float
    {
        return max(0, Money::minorUnits($amountDue) - $this->distributedMinorUnits($rows)) / 100;
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function distributedAmount(array $rows): float
    {
        return $this->distributedMinorUnits($rows) / 100;
    }

    public function amountDue(mixed $total, mixed $paid = 0): float
    {
        return max(0, Money::minorUnits($total) - Money::minorUnits($paid)) / 100;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array{amount: float, rows: array<int, array<string, mixed>>}
     */
    public function prepare(mixed $amount, array $rows): array
    {
        $amount = Money::decimal($amount);
        $rows = $this->normalizeRows($rows);
        $this->validate($amount, $rows);

        return ['amount' => $amount, 'rows' => $rows];
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function replaceSplits(Payment $payment, array $rows): void
    {
        $prepared = $this->prepare($payment->amount, $rows);

        try {
            DB::transaction(function () use ($payment, $prepared): void {
                $oldValues = $payment->splits()->oldest('id')->get()
                    ->map->only(['payment_method', 'amount', 'currency'])->values()->all();
                $payment->splits()->delete();
                $payment->splits()->createMany(collect($prepared['rows'])->map(fn (array $row): array => [
                    ...$row,
                    'currency' => $payment->currency,
                ])->all());
                $payment->auditSplitReplacement($oldValues, $prepared['rows']);
                $this->cashboxManager->syncPayment($payment->refresh());
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw $exception;
        }
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function validate(mixed $amount, array $rows): void
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['splits' => 'დაამატეთ გადახდის მეთოდი.']);
        }

        $methods = collect($rows)->pluck('payment_method');

        if ($methods->contains(fn (mixed $method): bool => ! PaymentMethod::isSupported($method))) {
            throw ValidationException::withMessages(['splits' => 'არჩეული გადახდის მეთოდი არასწორია.']);
        }

        if ($methods->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['splits' => 'ერთი გადახდის მეთოდი ორჯერ ვერ დაემატება.']);
        }

        if (collect($rows)->contains(fn (array $row): bool => Money::minorUnits($row['amount'] ?? 0) <= 0)) {
            throw ValidationException::withMessages(['splits' => 'გადახდის თითოეული თანხა უნდა იყოს 0-ზე მეტი.']);
        }

        if ($this->distributedMinorUnits($rows) !== Money::minorUnits($amount)) {
            throw ValidationException::withMessages([
                'splits' => 'გადახდის მეთოდების თანხების ჯამი უნდა უდრიდეს გადახდის საერთო თანხას.',
            ]);
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRows(array $rows): array
    {
        return collect($rows)->map(fn (array $row): array => [
            ...$row,
            'payment_method' => PaymentMethod::normalize($row['payment_method'] ?? null),
            'amount' => Money::decimal($row['amount'] ?? 0),
        ])->values()->all();
    }
}
