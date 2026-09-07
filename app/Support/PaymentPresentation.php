<?php

namespace App\Support;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use Filament\Support\View\ComponentAttributeBag;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class PaymentPresentation
{
    /** @param iterable<int, Payment> $payments */
    public static function amountsByCurrency(iterable $payments): Collection
    {
        return self::components($payments)
            ->groupBy('currency')
            ->map(fn (Collection $rows): float => round((float) $rows->sum('amount'), 2));
    }

    /** @param iterable<int, Payment> $payments */
    public static function methodAmountsHtml(iterable $payments, string $fallbackCurrency = Currency::DEFAULT): HtmlString
    {
        $parts = self::components($payments)->map(function (array $component): string {
            $method = PaymentMethod::normalize($component['method']);
            $label = PaymentMethod::labelFor($method);
            $icon = match ($method) {
                PaymentMethod::Card->value => 'heroicon-o-credit-card',
                PaymentMethod::BankTransfer->value => 'heroicon-o-building-library',
                default => 'heroicon-o-banknotes',
            };
            $iconHtml = \Filament\Support\generate_icon_html(
                $icon,
                attributes: new ComponentAttributeBag([
                    'class' => 'renome-payment-method-icon',
                    'title' => $label,
                    'aria-label' => $label,
                ]),
            )?->toHtml() ?? '';

            return '<span class="renome-payment-component">'.$iconHtml
                .'<span>'.e(Currency::format($component['amount'], $component['currency'])).'</span></span>';
        });

        if ($parts->isEmpty()) {
            return new HtmlString(e(Currency::format(0, $fallbackCurrency)));
        }

        return new HtmlString('<span class="renome-payment-display">'.$parts->join('<span class="renome-payment-separator">+</span>').'</span>');
    }

    /** @param iterable<int, Payment> $payments */
    private static function components(iterable $payments): Collection
    {
        return collect($payments)
            ->flatMap(function (Payment $payment): array {
                $splits = $payment->relationLoaded('splits') ? $payment->splits : $payment->splits()->get();

                if ($splits->isNotEmpty()) {
                    return $splits->map(fn ($split): array => [
                        'method' => PaymentMethod::normalize($split->payment_method),
                        'currency' => $split->currency,
                        'amount' => (float) $split->amount,
                    ])->all();
                }

                return [[
                    'method' => PaymentMethod::normalize($payment->payment_method),
                    'currency' => $payment->currency,
                    'amount' => (float) $payment->amount,
                ]];
            })
            ->groupBy(fn (array $row): string => $row['method'].'|'.$row['currency'])
            ->map(fn (Collection $rows): array => [
                'method' => $rows->first()['method'],
                'currency' => $rows->first()['currency'],
                'amount' => round((float) $rows->sum('amount'), 2),
            ])
            ->values();
    }
}
