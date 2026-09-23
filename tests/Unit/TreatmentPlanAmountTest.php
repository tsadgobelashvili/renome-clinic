<?php

use App\Support\TreatmentPlanDocument;

test('export amounts omit only zero decimal places', function (string $amount, string $expected) {
    expect(TreatmentPlanDocument::formatAmount($amount))->toBe($expected);
})->with([
    ['650.00', '650'],
    ['130.00', '130'],
    ['1530.00', '1,530'],
    ['650.50', '650.50'],
    ['650.05', '650.05'],
    ['0.00', '0'],
]);
