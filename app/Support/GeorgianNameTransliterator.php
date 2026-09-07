<?php

namespace App\Support;

class GeorgianNameTransliterator
{
    private const MAP = [
        'ა' => 'a', 'ბ' => 'b', 'გ' => 'g', 'დ' => 'd', 'ე' => 'e', 'ვ' => 'v', 'ზ' => 'z',
        'თ' => 't', 'ი' => 'i', 'კ' => 'k', 'ლ' => 'l', 'მ' => 'm', 'ნ' => 'n', 'ო' => 'o',
        'პ' => 'p', 'ჟ' => 'zh', 'რ' => 'r', 'ს' => 's', 'ტ' => 't', 'უ' => 'u', 'ფ' => 'p',
        'ქ' => 'k', 'ღ' => 'gh', 'ყ' => 'q', 'შ' => 'sh', 'ჩ' => 'ch', 'ც' => 'ts', 'ძ' => 'dz',
        'წ' => 'ts', 'ჭ' => 'ch', 'ხ' => 'kh', 'ჯ' => 'j', 'ჰ' => 'h',
    ];

    public static function transliterate(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '' || ! preg_match('/\p{Georgian}/u', $name)) {
            return null;
        }

        $latin = strtr($name, self::MAP);
        $latin = (string) preg_replace('/\s+/u', ' ', $latin);

        return mb_convert_case($latin, MB_CASE_TITLE, 'UTF-8');
    }
}
