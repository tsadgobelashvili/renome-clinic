<?php

namespace App\Filament\Resources\Concerns;

use App\Support\GeorgianNameTransliterator;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Resource-only URL keys: model keys, relationships and other resources stay numeric. */
trait HasReadablePersonUrls
{
    public static function personRouteKey(Model $record): string
    {
        $attributes = $record->getAttributes();
        $parts = [];
        foreach (['first_name', 'last_name'] as $field) {
            $name = collect([$attributes[$field.'_en'] ?? null, $attributes[$field.'_latin'] ?? null, $attributes[$field] ?? null])
                ->first(fn ($value) => filled($value));
            $parts[] = GeorgianNameTransliterator::transliterate($name) ?? $name;
        }

        return (Str::slug(implode(' ', $parts)) ?: 'person').'-'.$record->getKey();
    }

    public static function resolveRecordRouteBinding(int|string $key, ?Closure $modifyQuery = null): ?Model
    {
        if (! preg_match('/^(?:[^\/]+-)?([1-9][0-9]*)$/D', (string) $key, $matches)) {
            return null;
        }
        $id = $matches[1];
        if (strlen($id) > strlen((string) PHP_INT_MAX) || (strlen($id) === strlen((string) PHP_INT_MAX) && strcmp($id, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        // Retain Filament's resource query/scopes, optional query modifier and binding.
        return parent::resolveRecordRouteBinding($id, $modifyQuery);
    }

    public static function getUrl(?string $name = null, array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false, ?string $configuration = null): string
    {
        if (isset($parameters['record'])) {
            $record = $parameters['record'];
            if (! $record instanceof Model && (is_string($record) || is_int($record))) {
                $record = static::resolveRecordRouteBinding($record);
            }
            if ($record instanceof Model) {
                $parameters['record'] = static::personRouteKey($record);
            }
        }

        return parent::getUrl($name, $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters, $configuration);
    }
}
