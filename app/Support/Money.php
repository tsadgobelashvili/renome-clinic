<?php

namespace App\Support;

final class Money
{
    public static function minorUnits(mixed $amount): int
    {
        $normalized = str_replace([',', ' '], '', trim((string) ($amount ?? 0)));

        return (int) round((float) $normalized * 100);
    }

    public static function decimal(mixed $amount): float
    {
        return self::minorUnits($amount) / 100;
    }
}
