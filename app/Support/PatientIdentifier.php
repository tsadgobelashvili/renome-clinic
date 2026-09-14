<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class PatientIdentifier
{
    public const PREFIX = 'encrypted:v1:';

    public static function normalize(?string $value): ?string
    {
        $value = preg_replace('/[\s\p{Z}]+/u', '', $value ?? '');
        // Hyphens are formatting for numeric IDs, but may be meaningful in foreign IDs.
        if (preg_match('/^[0-9-]+$/', $value)) {
            $value = str_replace('-', '', $value);
        }

        return $value === '' ? null : $value;
    }

    public static function key(): string
    {
        $configured = config('patient_identifiers.hash_key');
        $key = is_string($configured) && str_starts_with($configured, 'base64:')
            ? base64_decode(substr($configured, 7), true)
            : $configured;

        if (! is_string($key) || strlen($key) < 32) {
            throw new RuntimeException('PERSONAL_ID_HASH_KEY must contain at least 32 random bytes (raw or base64: encoded).');
        }

        return $key;
    }

    public static function hash(?string $value): ?string
    {
        $value = self::normalize($value);

        return $value === null ? null : hash_hmac('sha256', $value, self::key());
    }

    public static function encrypt(?string $value): ?string
    {
        $value = self::normalize($value);

        return $value === null ? null : self::PREFIX.Crypt::encryptString($value);
    }

    public static function decrypt(?string $value, ?string $hash = null): ?string
    {
        if ($value === null) {
            return null;
        }

        if (str_starts_with($value, self::PREFIX)) {
            return Crypt::decryptString(substr($value, strlen(self::PREFIX)));
        }

        // Transitional reads only: existing rows are plaintext until the backfill runs.
        // A row marked as indexed must never silently fall back after damaged encryption.
        if ($hash !== null) {
            throw new RuntimeException('Invalid encrypted patient identifier.');
        }

        $payload = json_decode(base64_decode($value, true) ?: '', true);
        if (is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac'])) {
            return Crypt::decryptString($value);
        }

        return self::normalize($value);
    }
}
