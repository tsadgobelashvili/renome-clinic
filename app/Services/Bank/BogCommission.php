<?php

namespace App\Services\Bank;

/** Extract only explicitly labelled, same-currency amounts from the original BOG text. */
class BogCommission
{
    public static function metadata(array $data): array
    {
        if (($data['bank'] ?? 'BOG') !== 'BOG' || ($data['direction'] ?? '') !== 'inflow') {
            return [];
        }
        $description = $data['description'] ?? '';
        $currency = preg_quote($data['currency'] ?? '', '/');
        if (! preg_match('/(?:თანხა|gross amount)\s*:\s*'.$currency.'\s+([0-9]+(?:[.,][0-9]{1,2})?)(?=\s*;|\s*$)/iu', $description, $gross)
            || ! preg_match('/(?:საკომისიო|commission)\s*:\s*'.$currency.'\s+([0-9]+(?:[.,][0-9]{1,2})?)(?=\s*;|\s*$)/iu', $description, $fee)) {
            return [];
        }
        $result = [];
        if (empty($data['gross_amount'])) {
            $result['gross_amount'] = number_format((float) str_replace(',', '.', $gross[1]), 2, '.', '');
        }
        if ((float) ($data['bank_fee'] ?? 0) === 0.0 && (float) str_replace(',', '.', $fee[1]) > 0) {
            $result['bank_fee'] = number_format((float) str_replace(',', '.', $fee[1]), 2, '.', '');
        }

        return $result;
    }
}
