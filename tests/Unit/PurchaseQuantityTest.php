<?php

use App\Support\PurchaseQuantity;
use Brick\Math\Exception\RoundingNecessaryException;

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
    ['0.333300', '0.3333', '0.3333'],
    ['1.234567', '1.234567', '1.234567'],
]);

test('purchase quantity canonical precision is exact and old hashes retain three decimal identity', function () {
    expect(PurchaseQuantity::canonical('0.3333'))->toBe('0.333300')
        ->and(PurchaseQuantity::canonical('1.234567'))->toBe('1.234567')
        ->and(PurchaseQuantity::hashValue('2.000000'))->toBe('2.000')
        ->and(PurchaseQuantity::hashValue('0.3333'))->toBe('0.333300');
    expect(fn () => PurchaseQuantity::canonical('0.1234567'))->toThrow(RoundingNecessaryException::class);
});
