<?php

use App\Support\PurchaseQuantity;

test('purchase quantities suppress only insignificant decimal zeroes', function ($value, $display, $input) {
    expect(PurchaseQuantity::format($value))->toBe($display)
        ->and(PurchaseQuantity::format($value, groupThousands: false))->toBe($input);
})->with([
    ['4.000', '4', '4'],
    ['10.000', '10', '10'],
    ['72.000', '72', '72'],
    ['1097.000', '1 097', '1097'],
    ['1.333', '1.333', '1.333'],
    ['2.500', '2.5', '2.5'],
    ['0.000', '0', '0'],
    ['0.010', '0.01', '0.01'],
    ['1097.050', '1 097.05', '1097.05'],
]);
