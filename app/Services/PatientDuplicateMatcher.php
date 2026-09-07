<?php

namespace App\Services;

use App\Models\Patient;
use App\Support\GeorgianNameTransliterator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PatientDuplicateMatcher
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return Collection<int, Patient>
     */
    public function find(array $attributes, ?int $exceptPatientId = null, int $limit = 8): Collection
    {
        $firstNames = $this->nameVariants($attributes['first_name'] ?? null, $attributes['first_name_latin'] ?? null);
        $lastNames = $this->nameVariants($attributes['last_name'] ?? null, $attributes['last_name_latin'] ?? null);
        $birthDate = filled($attributes['birth_date'] ?? null) ? (string) $attributes['birth_date'] : null;
        $phone = $this->normalizePhone($attributes['phone'] ?? null);

        if ($firstNames === [] && $lastNames === [] && $birthDate === null && $phone === '') {
            return collect();
        }

        $candidates = Patient::query()
            ->when($exceptPatientId, fn (Builder $query): Builder => $query->whereKeyNot($exceptPatientId))
            ->where(function (Builder $query) use ($firstNames, $lastNames, $birthDate, $phone): void {
                foreach ($firstNames as $name) {
                    $query->orWhereRaw('LOWER(first_name) = ?', [$name])
                        ->orWhereRaw('LOWER(first_name_latin) = ?', [$name]);
                }
                foreach ($lastNames as $name) {
                    $query->orWhereRaw('LOWER(last_name) = ?', [$name])
                        ->orWhereRaw('LOWER(last_name_latin) = ?', [$name]);
                }
                if ($birthDate !== null) {
                    $query->orWhereDate('birth_date', $birthDate);
                }
                if ($phone !== '') {
                    $query->orWhereRaw(
                        "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = ?",
                        [$phone],
                    );
                }
            })
            ->limit(100)
            ->get();

        return $candidates
            ->map(function (Patient $patient) use ($firstNames, $lastNames, $birthDate, $phone): array {
                $firstMatches = $this->intersects($firstNames, $this->nameVariants($patient->first_name, $patient->first_name_latin));
                $lastMatches = $this->intersects($lastNames, $this->nameVariants($patient->last_name, $patient->last_name_latin));
                $birthMatches = $birthDate !== null && $patient->birth_date?->toDateString() === $birthDate;
                $phoneMatches = $phone !== '' && $this->normalizePhone($patient->phone) === $phone;

                $isPossibleMatch = ($firstMatches && $lastMatches)
                    || $phoneMatches
                    || ($birthMatches && ($firstMatches || $lastMatches));

                return ['patient' => $patient, 'matches' => $isPossibleMatch, 'score' => ($firstMatches ? 1 : 0) + ($lastMatches ? 1 : 0) + ($birthMatches ? 2 : 0) + ($phoneMatches ? 3 : 0)];
            })
            ->filter(fn (array $match): bool => $match['matches'])
            ->sortByDesc('score')
            ->take($limit)
            ->pluck('patient')
            ->values();
    }

    /** @return array<int, string> */
    private function nameVariants(mixed $original, mixed $latin): array
    {
        $values = [$original, $latin, GeorgianNameTransliterator::transliterate((string) $original)];

        return collect($values)
            ->map(fn (mixed $value): string => $this->normalizeName($value))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeName(mixed $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $value)), 'UTF-8');
    }

    private function normalizePhone(mixed $value): string
    {
        return (string) preg_replace('/\D+/', '', (string) $value);
    }

    /** @param array<int, string> $left @param array<int, string> $right */
    private function intersects(array $left, array $right): bool
    {
        return array_intersect($left, $right) !== [];
    }
}
