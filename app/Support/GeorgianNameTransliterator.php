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

    /** Best-effort spelling; the original Latin name is preserved separately. */
    public static function toGeorgian(?string $name): ?string
    {
        $name = trim((string) $name);
        if (! preg_match("/^[a-zA-Z][a-zA-Z\\s'’.-]*$/u", $name)) {
            return null;
        }

        return strtr(strtolower($name), [
            'sh' => 'შ', 'ch' => 'ჩ', 'ts' => 'ც', 'dz' => 'ძ',
            'zh' => 'ჟ', 'kh' => 'ხ', 'gh' => 'ღ', 'th' => 'თ',
            'a' => 'ა', 'b' => 'ბ', 'c' => 'კ', 'd' => 'დ', 'e' => 'ე',
            'f' => 'ფ', 'g' => 'გ', 'h' => 'ჰ', 'i' => 'ი', 'j' => 'ჯ',
            'k' => 'კ', 'l' => 'ლ', 'm' => 'მ', 'n' => 'ნ', 'o' => 'ო',
            'p' => 'პ', 'q' => 'ყ', 'r' => 'რ', 's' => 'ს', 't' => 'თ',
            'u' => 'უ', 'v' => 'ვ', 'w' => 'ვ', 'x' => 'ქს', 'y' => 'ი', 'z' => 'ზ',
        ]);
    }

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
