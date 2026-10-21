<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PurchaseQuantity
{
    public static function canonical(int|float|string $quantity): string
    {
        return (string) BigDecimal::of(str_replace([' ', ','], ['', '.'], trim((string) $quantity)))
            ->toScale(6, RoundingMode::Unnecessary);
    }

    /** Preserve v2 hashes already created with three-decimal quantities. */
    public static function hashValue(int|float|string $quantity): string
    {
        $value = self::canonical($quantity);

        return str_ends_with($value, '000') ? substr($value, 0, -3) : $value;
    }

    public static function format(int|float|string $quantity, bool $groupThousands = true): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 6, '.', $groupThousands ? ' ' : ''), '0'), '.');
    }
}
