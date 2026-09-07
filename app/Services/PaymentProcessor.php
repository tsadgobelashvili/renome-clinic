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
    public const FX_SETTLEMENT_TOLERANCE_GEL = 10.00;

    public function __construct(private readonly CashboxManager $cashboxManager) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int|string, array<string, mixed>>  $rows
     */
    public function process(array $attributes, array $rows): Payment
    {
        $debtCurrency = $attributes['currency'] ?? Currency::DEFAULT;
        $prepared = $this->prepare($attributes['amount'] ?? 0, $rows, $debtCurrency);
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

                $payment->splits()->createMany($rows);

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
    public function distributedMinorUnits(array $rows, string $debtCurrency = Currency::DEFAULT): int
    {
        return collect($rows)->sum(fn (array $row): int => Money::minorUnits(
            (float) ($row['amount'] ?? 0) * (($row['currency'] ?? $debtCurrency) === $debtCurrency ? 1 : (float) ($row['exchange_rate'] ?? 0)),
        ));
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function remaining(mixed $amountDue, array $rows, string $debtCurrency = Currency::DEFAULT): float
    {
        $dueMinor = Money::minorUnits($amountDue);
        $distributedMinor = $this->distributedMinorUnits($rows, $debtCurrency);
        $shortfall = $dueMinor - $distributedMinor;

        if ($shortfall > 0 && $shortfall <= $this->settlementShortfallToleranceMinorUnits($rows, $debtCurrency)) {
            return 0.0;
        }

        return max(0, $shortfall) / 100;
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function distributedAmount(array $rows, string $debtCurrency = Currency::DEFAULT): float
    {
        return $this->distributedMinorUnits($rows, $debtCurrency) / 100;
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function reconciledDistributedAmount(mixed $amountDue, array $rows, string $debtCurrency = Currency::DEFAULT): float
    {
        $dueMinor = Money::minorUnits($amountDue);
        $distributedMinor = $this->distributedMinorUnits($rows, $debtCurrency);

        if ($this->isDistributionAcceptable($amountDue, $rows, $debtCurrency)) {
            return $dueMinor / 100;
        }

        return $distributedMinor / 100;
    }

    public function amountDue(mixed $total, mixed $paid = 0): float
    {
        return max(0, Money::minorUnits($total) - Money::minorUnits($paid)) / 100;
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function distributionToleranceMinorUnits(array $rows, string $debtCurrency = Currency::DEFAULT): int
    {
        return collect($rows)->contains(fn (array $row): bool => ($row['currency'] ?? $debtCurrency) !== $debtCurrency) ? 1 : 0;
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function settlementShortfallToleranceMinorUnits(array $rows, string $debtCurrency = Currency::DEFAULT): int
    {
        if ($debtCurrency !== 'GEL'
            || ! collect($rows)->contains(fn (array $row): bool => ($row['currency'] ?? $debtCurrency) !== $debtCurrency)) {
            return 0;
        }

        return Money::minorUnits(self::FX_SETTLEMENT_TOLERANCE_GEL);
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function isDistributionAcceptable(mixed $amountDue, array $rows, string $debtCurrency = Currency::DEFAULT): bool
    {
        $difference = $this->distributedMinorUnits($rows, $debtCurrency) - Money::minorUnits($amountDue);

        if ($difference > 0) {
            return $difference <= $this->distributionToleranceMinorUnits($rows, $debtCurrency);
        }

        return abs($difference) <= $this->settlementShortfallToleranceMinorUnits($rows, $debtCurrency);
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function exceedsAmountDue(mixed $amountDue, array $rows, string $debtCurrency = Currency::DEFAULT): bool
    {
        return $this->distributedMinorUnits($rows, $debtCurrency) - Money::minorUnits($amountDue)
            > $this->distributionToleranceMinorUnits($rows, $debtCurrency);
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array{amount: float, rows: array<int, array<string, mixed>>}
     */
    public function prepare(mixed $amount, array $rows, string $debtCurrency = Currency::DEFAULT): array
    {
        $amount = Money::decimal($amount);
        $rows = $this->normalizeRows($rows, $debtCurrency);
        $this->validate($amount, $rows, $debtCurrency);

        return ['amount' => $amount, 'rows' => $rows];
    }

    /** @param array<int|string, array<string, mixed>> $rows */
    public function replaceSplits(Payment $payment, array $rows): void
    {
        $prepared = $this->prepare($payment->amount, $rows, $payment->currency);

        try {
            DB::transaction(function () use ($payment, $prepared): void {
                $oldValues = $payment->splits()->oldest('id')->get()
                    ->map->only(['payment_method', 'amount', 'currency'])->values()->all();
                $payment->splits()->delete();
                $payment->splits()->createMany($prepared['rows']);
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

    /** @param array<int|string, array<string, mixed>> $rows */
    public function correct(Payment $payment, array $attributes, array $rows): Payment
    {
        return DB::transaction(function () use ($payment, $attributes, $rows): Payment {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->getKey());
            $visit = $payment->visit()->lockForUpdate()->firstOrFail();
            $debtCurrency = $visit->currency ?: Currency::DEFAULT;
            $rows = $this->normalizeRows($rows, $debtCurrency);
            $otherPaid = (float) $visit->payments()->whereKeyNot($payment->getKey())->sum('amount');
            $available = $this->amountDue($visit->net_amount, $otherPaid);

            if ($this->exceedsAmountDue($available, $rows, $debtCurrency)) {
                throw ValidationException::withMessages([
                    'splits' => 'გადახდის თანხა ვიზიტის დარჩენილ გადასახდელს აღემატება.',
                ]);
            }

            $amount = $this->reconciledDistributedAmount($available, $rows, $debtCurrency);
            $prepared = $this->prepare($amount, $rows, $debtCurrency);
            $oldSplits = $payment->splits()->oldest('id')->get()
                ->map->only(['payment_method', 'amount', 'currency', 'exchange_rate'])->values()->all();

            $payment->skipCashboxSync = true;
            $payment->fill([
                'amount' => $prepared['amount'],
                'currency' => $debtCurrency,
                'payment_method' => $prepared['rows'][0]['payment_method'],
                'payment_date' => $attributes['payment_date'] ?? $payment->payment_date,
            ])->save();
            $payment->splits()->delete();
            $payment->splits()->createMany($prepared['rows']);
            $payment->auditSplitReplacement($oldSplits, $prepared['rows']);
            $this->cashboxManager->syncPayment($payment->refresh(), allowClosedDayCorrection: true);

            return $payment->load('splits');
        });
    }

    public function void(Payment $payment): void
    {
        DB::transaction(function () use ($payment): void {
            Payment::query()->lockForUpdate()->findOrFail($payment->getKey())->delete();
        });
    }

    /** @param array<int, array<string, mixed>> $rows */
    public function validate(mixed $amount, array $rows, string $debtCurrency = Currency::DEFAULT): void
    {
        if ($rows === []) {
            throw ValidationException::withMessages(['splits' => 'დაამატეთ გადახდის მეთოდი.']);
        }

        $methods = collect($rows)->pluck('payment_method');

        if ($methods->contains(fn (mixed $method): bool => ! PaymentMethod::isSupported($method))) {
            throw ValidationException::withMessages(['splits' => 'არჩეული გადახდის მეთოდი არასწორია.']);
        }

        $rowKeys = collect($rows)->map(fn (array $row): string => ($row['payment_method'] ?? '').'|'.($row['currency'] ?? $debtCurrency));
        if ($rowKeys->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['splits' => 'ერთი და იგივე მეთოდი და ვალუტა ორჯერ ვერ დაემატება.']);
        }

        if (collect($rows)->contains(fn (array $row): bool => Money::minorUnits($row['amount'] ?? 0) <= 0)) {
            throw ValidationException::withMessages(['splits' => 'გადახდის თითოეული თანხა უნდა იყოს 0-ზე მეტი.']);
        }

        if (collect($rows)->contains(fn (array $row): bool => ! Currency::isSupported($row['currency'] ?? null)
            || (($row['currency'] ?? $debtCurrency) !== $debtCurrency && (float) ($row['exchange_rate'] ?? 0) <= 0))) {
            throw ValidationException::withMessages(['splits' => 'განსხვავებული ვალუტისთვის მიუთითეთ კურსი.']);
        }

        if (! $this->isDistributionAcceptable($amount, $rows, $debtCurrency)) {
            throw ValidationException::withMessages([
                'splits' => 'გადახდის მეთოდების თანხების ჯამი უნდა უდრიდეს გადახდის საერთო თანხას.',
            ]);
        }
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function normalizeRows(array $rows, string $debtCurrency = Currency::DEFAULT): array
    {
        return collect($rows)->map(fn (array $row): array => [
            'payment_method' => PaymentMethod::normalize($row['payment_method'] ?? null),
            'amount' => Money::decimal($row['amount'] ?? 0),
            'currency' => $row['currency'] ?? $debtCurrency,
            'exchange_rate' => ($row['currency'] ?? $debtCurrency) === $debtCurrency
                ? null
                : round((float) ($row['exchange_rate'] ?? 0), 6),
        ])->values()->all();
    }
}
