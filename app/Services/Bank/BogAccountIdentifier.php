<?php

namespace App\Services\Bank;

final class BogAccountIdentifier
{
    public static function normalize(?string $account): ?string
    {
        if ($account === null) {
            return null;
        }
        $account = strtoupper(preg_replace('/\s+/u', '', $account));
        // BOG Excel: IBAN + GEL + (internal account); API entryAccountNumber: IBAN + GEL.
        if (preg_match('/^(GE\d{2}[A-Z0-9]{18})(?:[A-Z]{3}(?:\(\d+\))?)?$/D', $account, $match)) {
            return $match[1];
        }

        return $account;
    }
}
