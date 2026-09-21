<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\Employee;
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
    public function doctorSuggestions(?string $search, ?string $locale = null): array
    {
        return $this->practitionerCandidates((string) $search)
            ->map(fn (Doctor|Employee $person): string => $this->practitionerLabel($person, $locale))->all();
    }

    public function doctorIdFromLabel(?string $label): ?int
    {
        return $this->practitionerFromLabel($label)['doctor_id'];
    }

    /** @return array{doctor_id: ?int, assistant_employee_id: ?int} */
    public function practitionerFromLabel(?string $label): array
    {
        $empty = ['doctor_id' => null, 'assistant_employee_id' => null];
        $label = trim((string) $label);
        if ($label === '') {
            return $empty;
        }
        $assistantLabel = str_ends_with($label, ' — Assistant');
        $name = $assistantLabel ? substr($label, 0, -strlen(' — Assistant')) : $label;
        $matches = $this->practitionerCandidates($name)->filter(function (Doctor|Employee $person) use ($name, $assistantLabel): bool {
            return (! $assistantLabel || $person instanceof Employee)
                && in_array($this->normalizedName($name), $this->practitionerNames($person), true);
        });
        if ($matches->count() !== 1) {
            return $empty;
        }
        $person = $matches->first();

        return $person instanceof Employee
            ? ['doctor_id' => null, 'assistant_employee_id' => $person->id]
            : ['doctor_id' => $person->id, 'assistant_employee_id' => null];
    }

    public function practitionerLabel(Doctor|Employee $person, ?string $locale = null): string
    {
        if ($person instanceof Employee) {
            return GeorgianNameTransliterator::transliterate($person->full_name) ?? $person->full_name;
        }

        return $locale !== null ? $person->labDisplayName($locale) : $person->full_name;
    }

    /** Doctor IDs stay numeric for existing saved filters; employee keys have a distinct namespace. */
    public function practitionerOptions(?string $search = null): array
    {
        return $this->practitionerCandidates((string) $search)->mapWithKeys(
            fn (Doctor|Employee $person): array => [
                ($person instanceof Employee ? 'employee:'.$person->id : $person->id) => $this->practitionerLabel($person),
            ],
        )->all();
    }

    public function practitionerOptionLabel(string|int|null $value, ?string $locale = null): ?string
    {
        if (! $value) {
            return null;
        }
        $person = str_starts_with((string) $value, 'employee:')
            ? Employee::query()->labDoctorAssistants()->find(substr((string) $value, 9))
            : Doctor::query()->where('is_active', true)->find($value);

        return $person ? $this->practitionerLabel($person, $locale) : null;
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

    /** @return Collection<int, Doctor|Employee> */
    private function practitionerCandidates(string $search): Collection
    {
        $terms = preg_split('/\s+/u', $this->normalizedName($search), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $matches = collect();
        // Only names and IDs are hydrated. Chunk through candidates so transliterated matches
        // beyond the first page are not silently lost; never load salary or patient relations.
        foreach ([
            Doctor::query()->where('is_active', true),
            Employee::query()->labDoctorAssistants(),
        ] as $query) {
            $columns = ['id', 'first_name', 'last_name'];
            if ($query->getModel() instanceof Doctor) {
                $columns = [...$columns, 'first_name_en', 'last_name_en'];
            }
            foreach ($query->select($columns)->lazyById(200) as $person) {
                $name = implode(' ', $this->practitionerNames($person));
                if (collect($terms)->every(fn (string $term): bool => str_contains($name, $term))) {
                    $matches->push($person);
                    if ($matches->count() >= 30) {
                        return $matches;
                    }
                }
            }
        }

        return $matches;
    }

    private function practitionerNames(Doctor|Employee $person): array
    {
        $names = [$person->full_name];
        if ($person instanceof Doctor) {
            $names[] = trim($person->first_name_en.' '.$person->last_name_en);
            $names[] = $person->labDisplayName('en');
        }

        return array_map(fn (string $name): string => $this->normalizedName($name), $names);
    }

    private function normalizedName(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return mb_strtolower(GeorgianNameTransliterator::transliterate($name) ?? $name);
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
