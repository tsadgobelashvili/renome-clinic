<?php

namespace App\Support;

use App\Models\Doctor;
use App\Models\Employee;

final class AdvanceRecipients
{
    public static function options(string $search = ''): array
    {
        // No shared person/Doctor-Employee relation exists. Never merge people by name.
        return Employee::activeNameOptions($search) + Doctor::where('is_active', true)->searchByName($search)
            ->orderBy('last_name')->orderBy('first_name')->limit(30)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Doctor $doctor): array => ['doctor:'.$doctor->id => $doctor->full_name.' — ექიმი'])->all();
    }

    public static function label(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^doctor:([1-9][0-9]*)$/', $value, $match)) {
            $doctor = Doctor::find($match[1]);

            return $doctor ? $doctor->full_name.' — ექიმი' : null;
        }

        return Employee::find($value)?->full_name;
    }
}
