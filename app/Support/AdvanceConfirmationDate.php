<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Calendar-date boundary for the two advance RS confirmation forms. */
class AdvanceConfirmationDate
{
    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof CarbonInterface) {
            return $value->copy()->setTimezone(config('app.timezone'))->toDateString();
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?)?$/D', $value)) {
            return $value;
        }

        $parsed = date_parse($value);
        if ($parsed['warning_count'] || $parsed['error_count']) {
            return $value; // Leave malformed dates for the form's date validation.
        }

        return Carbon::parse($value, config('app.timezone'))->setTimezone(config('app.timezone'))->toDateString();
    }
}
