<?php

namespace App\Support;

final class Currency
{
    public const DEFAULT = 'GEL';

    public const OPTIONS = [
        'GEL' => '₾',
        'USD' => '$',
    ];

    public static function isSupported(?string $currency): bool
    {
        return array_key_exists((string) $currency, self::OPTIONS);
    }

    public static function symbol(?string $currency): string
    {
        return self::OPTIONS[$currency ?? self::DEFAULT] ?? self::OPTIONS[self::DEFAULT];
    }

    public static function format(mixed $amount, ?string $currency = self::DEFAULT): string
    {
        $formatted = number_format((float) ($amount ?? 0), 2);

        return $currency === 'USD'
            ? '$'.$formatted
            : $formatted.' ₾';
    }

    /** @param array<string, array<string, float|int>> $summaries */
    /** @return array<int, string> */
    public static function formatBreakdown(array $summaries, string $key): array
    {
        $lines = collect(self::OPTIONS)
            ->map(fn (string $symbol, string $currency): ?string => array_key_exists($currency, $summaries)
                ? self::format($summaries[$currency][$key] ?? 0, $currency)
                : null)
            ->filter()
            ->values();

        return $lines->isEmpty() ? [self::format(0)] : $lines->all();
    }
}
