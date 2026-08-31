<?php

namespace App\Enums;

enum PartnerAccount: string
{
    case Cash = 'cash';
    case Bank = 'bank';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'ნაღდი',
            self::Bank => 'ბანკი',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(
            fn (self $account): array => [$account->value => $account->label()],
        )->all();
    }

    public static function isSupported(mixed $account): bool
    {
        return self::tryFrom((string) $account) !== null;
    }
}
