<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case BankTransfer = 'bank_transfer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'ნაღდი',
            self::Card => 'ბარათი',
            self::BankTransfer => 'გადარიცხვა',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $method): array => [$method->value => $method->label()])
            ->all();
    }

    public static function normalize(mixed $method): string
    {
        return (string) $method === 'transfer' ? self::BankTransfer->value : (string) $method;
    }

    public static function isSupported(mixed $method): bool
    {
        return self::tryFrom(self::normalize($method)) !== null;
    }

    public static function labelFor(mixed $method): string
    {
        return self::tryFrom(self::normalize($method))?->label() ?? (string) $method;
    }
}
