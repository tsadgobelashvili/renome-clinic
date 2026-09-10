<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Support\GeorgianNameTransliterator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LabPartyAutocomplete
{
    /** @return array<int, string> */
    public function patientSuggestions(?string $search): array
    {
        if (mb_strlen(trim((string) $search)) < 1) {
            return [];
        }

        return Patient::query()->searchForLab((string) $search)->limit(30)->get()
            ->map(fn (Patient $patient): string => $patient->lab_selection_label)
            ->values()->all();
    }

    public function patientIdFromLabel(?string $label): ?int
    {
        return $this->patientFromLabel($label)?->getKey();
    }

    public function patientFromLabel(?string $label): ?Patient
    {
        $label = trim((string) $label);

        if ($label === '') {
            return null;
        }

        $name = trim(Str::before($label, ' — '));

        return Patient::query()->searchForLab($name)->limit(30)->get()->first(
            fn (Patient $patient): bool => $patient->lab_selection_label === $label,
        );
    }

    /** @return array<int, string> */
    public function doctorSuggestions(?string $search): array
    {
        $search = trim((string) $search);
        if (mb_strlen($search) < 1) {
            return [];
        }

        return $this->doctorCandidates($search)
            ->map(fn (Doctor $doctor): string => $this->doctorSuggestionLabel($doctor, $search))
            ->values()->all();
    }

    public function doctorIdFromLabel(?string $label): ?int
    {
        $label = trim((string) $label);

        if ($label === '') {
            return null;
        }

        $matches = $this->doctorCandidates($label)->filter(
            fn (Doctor $doctor): bool => $this->doctorLabelMatches($doctor, $label),
        );

        return $matches->count() === 1 ? $matches->first()->getKey() : null;
    }

    public function resolvePatientForLab(?int $patientId, ?string $entry, string $source): Patient
    {
        if ($patientId && ($patient = Patient::query()->find($patientId))) {
            return $patient;
        }

        return DB::transaction(function () use ($entry, $source): Patient {
            $entry = trim((string) $entry);
            if ($entry === '') {
                throw ValidationException::withMessages(['patient_entry' => __('lab.patient_required')]);
            }

            if (! in_array($source, ['clinic', 'israeli'], true)) {
                throw ValidationException::withMessages(['source' => __('lab.external_patient_must_exist')]);
            }

            if ($selected = $this->patientFromLabel($entry)) {
                return $selected;
            }

            [$name, $birthDate] = $this->parsePatientEntry($entry);
            $parts = preg_split('/\s+/u', $name, 2, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($parts) < 2) {
                throw ValidationException::withMessages(['patient_entry' => __('lab.patient_full_name_required')]);
            }
            [$firstName, $lastName] = $parts;

            $candidates = Patient::query()->searchForLab($name)->limit(20)->get();
            $exact = $candidates->filter(function (Patient $patient) use ($name, $birthDate): bool {
                $nameMatches = in_array(mb_strtolower($name), [
                    mb_strtolower($patient->full_name), mb_strtolower($patient->lab_name),
                ], true);
                $birthMatches = $birthDate === null || $patient->birth_date?->toDateString() === $birthDate;

                return $nameMatches && $birthMatches;
            });

            if ($exact->count() === 1) {
                return $exact->first();
            }

            if ($exact->count() > 1 || $candidates->count() > 1) {
                throw ValidationException::withMessages(['patient_entry' => __('lab.patient_ambiguous')]);
            }

            $groupId = PatientGroup::query()
                ->where('slug', $source === 'israeli' ? PatientGroup::ISRAEL_PARTNER_SLUG : PatientGroup::CLINIC_SLUG)
                ->where('is_active', true)
                ->value('id');

            if (! $groupId) {
                throw ValidationException::withMessages(['patient_group_id' => __('lab.quick_patient_group_required')]);
            }

            return Patient::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'birth_date' => $birthDate,
                'patient_group_id' => $groupId,
            ]);
        });
    }

    public function sourceForPatient(int $patientId): string
    {
        $slug = Patient::query()->whereKey($patientId)->with('patientGroup')->first()?->patientGroup?->slug;

        return $slug === PatientGroup::ISRAEL_PARTNER_SLUG ? 'israeli' : 'clinic';
    }

    /** @return Collection<int, Doctor> */
    private function doctorCandidates(string $search): Collection
    {
        $search = trim($search);
        $matches = Doctor::query()->searchByName($search)->limit(30)->get();
        if ($matches->count() >= 30) {
            return $matches;
        }

        if (preg_match('/\p{Georgian}/u', $search)) {
            $latin = GeorgianNameTransliterator::transliterate($search);
            if ($latin !== null) {
                $matches = $matches->concat(Doctor::query()->searchByName($latin)
                    ->whereNotIn('id', $matches->modelKeys())
                    ->limit(30 - $matches->count())
                    ->get());
            }
        } else {
            $terms = preg_split('/\s+/u', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $crossScript = Doctor::query()
                ->whereNotIn('id', $matches->modelKeys())
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->limit(200)
                ->get(['id', 'first_name', 'last_name'])
                ->filter(function (Doctor $doctor) use ($terms): bool {
                    $latinName = mb_strtolower((string) GeorgianNameTransliterator::transliterate($doctor->full_name));

                    return $latinName !== '' && collect($terms)->every(
                        fn (string $term): bool => str_contains($latinName, $term),
                    );
                });
            $matches = $matches->concat($crossScript);
        }

        return $matches->unique('id')->take(30)->values();
    }

    private function doctorLabelMatches(Doctor $doctor, string $label): bool
    {
        $label = mb_strtolower(trim($label));
        $stored = mb_strtolower($doctor->full_name);
        if ($label === $stored) {
            return true;
        }

        $storedLatin = GeorgianNameTransliterator::transliterate($doctor->full_name);
        if ($storedLatin !== null && mb_strtolower($storedLatin) === $label) {
            return true;
        }

        $labelLatin = GeorgianNameTransliterator::transliterate($label);

        if ($labelLatin !== null && mb_strtolower($labelLatin) === $stored) {
            return true;
        }

        return mb_strtolower($this->latinToGeorgian($doctor->full_name)) === $label;
    }

    private function doctorSuggestionLabel(Doctor $doctor, string $search): string
    {
        if (preg_match('/\p{Georgian}/u', $search)) {
            return preg_match('/\p{Georgian}/u', $doctor->full_name)
                ? $doctor->full_name
                : $this->latinToGeorgian($doctor->full_name);
        }

        return GeorgianNameTransliterator::transliterate($doctor->full_name) ?? $doctor->full_name;
    }

    private function latinToGeorgian(string $name): string
    {
        return strtr(mb_strtolower($name), [
            'zh' => 'ჟ', 'sh' => 'შ', 'ch' => 'ჩ', 'ts' => 'ც', 'dz' => 'ძ', 'gh' => 'ღ', 'kh' => 'ხ',
            'a' => 'ა', 'b' => 'ბ', 'g' => 'გ', 'd' => 'დ', 'e' => 'ე', 'v' => 'ვ', 'z' => 'ზ',
            't' => 'ტ', 'i' => 'ი', 'k' => 'კ', 'l' => 'ლ', 'm' => 'მ', 'n' => 'ნ', 'o' => 'ო',
            'p' => 'პ', 'r' => 'რ', 's' => 'ს', 'u' => 'უ', 'f' => 'ფ', 'q' => 'ყ', 'j' => 'ჯ', 'h' => 'ჰ',
        ]);
    }

    /** @return array{string, ?string} */
    private function parsePatientEntry(string $entry): array
    {
        $parts = preg_split('/\s+—\s+/u', $entry, 2) ?: [$entry];
        $name = trim($parts[0]);
        $birthDate = null;

        if (isset($parts[1]) && trim($parts[1]) !== '') {
            $date = \DateTimeImmutable::createFromFormat('!d.m.Y', trim($parts[1]));
            $errors = \DateTimeImmutable::getLastErrors();
            if (! $date || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                throw ValidationException::withMessages(['patient_entry' => __('lab.patient_birth_date_invalid')]);
            }
            $birthDate = $date->format('Y-m-d');
        }

        return [$name, $birthDate];
    }
}
