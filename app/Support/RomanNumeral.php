<?php

namespace App\Support;

final class RomanNumeral
{
    public static function fromInteger(int $number): string
    {
        $number = max(1, $number);
        $numerals = [
            1000 => 'M', 900 => 'CM', 500 => 'D', 400 => 'CD',
            100 => 'C', 90 => 'XC', 50 => 'L', 40 => 'XL',
            10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I',
        ];
        $roman = '';

        foreach ($numerals as $value => $numeral) {
            while ($number >= $value) {
                $roman .= $numeral;
                $number -= $value;
            }
        }

        return $roman;
    }
}
