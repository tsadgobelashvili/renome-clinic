<?php

namespace App\Support;

final class PurchaseQuantity
{
    public static function format(int|float|string $quantity, bool $groupThousands = true): string
    {
        return rtrim(rtrim(number_format((float) $quantity, 3, '.', $groupThousands ? ' ' : ''), '0'), '.');
    }
}
