<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
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
        if (mb_strlen(trim((string) $search)) < 1) {
            return [];
        }

        return Doctor::query()->searchByName((string) $search)->limit(30)->get()
            ->map(fn (Doctor $doctor): string => $doctor->full_name)
            ->values()->all();
    }

    public function doctorIdFromLabel(?string $label): ?int
    {
        $label = trim((string) $label);

        if ($label === '') {
            return null;
        }

        return Doctor::query()->searchByName($label)->limit(30)->get()->first(
            fn (Doctor $doctor): bool => $doctor->full_name === $label,
        )?->getKey();
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
