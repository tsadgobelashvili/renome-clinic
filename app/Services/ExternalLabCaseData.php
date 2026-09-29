<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\LabCase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ExternalLabCaseData
{
    public static function doctorOptions(): array
    {
        return Doctor::where('external_lab_enabled', true)->where('is_active', true)
            ->orderBy('first_name')->get()->map(fn ($doctor) => self::doctorLabel($doctor))->all();
    }

    public static function doctorLabel(Doctor $doctor): string
    {
        return $doctor->labDisplayName(app()->getLocale()).' — #'.$doctor->id;
    }

    public static function defaults(LabCase $case): array
    {
        return [
            'external_doctor_name' => $case->external_doctor_name ?: ($case->doctor?->full_name ?? $case->assistantEmployee?->full_name),
            'external_patient_name' => $case->external_patient_name ?: $case->patient?->full_name,
        ];
    }

    public static function prepare(array $data, ?LabCase $record = null): array
    {
        foreach (['external_doctor_name', 'external_patient_name', 'external_clinic_name'] as $field) {
            $data[$field] = trim((string) ($data[$field] ?? '')) ?: null;
        }
        Validator::make($data, [
            'external_doctor_name' => ['required', 'string', 'max:255'],
            'external_patient_name' => ['required', 'string', 'max:255'],
            'external_clinic_name' => ['nullable', 'string', 'max:255'],
        ])->validate();
        // Resolve the ordinary doctor field server-side; never trust a separate billing ID.
        $data['external_billing_doctor_id'] = null;
        if ($record?->external_billing_doctor_id && $data['external_doctor_name'] === $record->external_doctor_name) {
            $data['external_billing_doctor_id'] = $record->external_billing_doctor_id;
        } else {
            $matches = Doctor::where('external_lab_enabled', true)->where('is_active', true)->get()
                ->filter(fn ($doctor) => in_array($data['external_doctor_name'], [self::doctorLabel($doctor), $doctor->full_name, $doctor->labDisplayName('en')], true));
            if ($matches->count() > 1) {
                throw ValidationException::withMessages(['external_doctor_name' => 'აირჩიეთ ექიმი შეთავაზებული სიიდან.']);
            }
            $data['external_billing_doctor_id'] = $matches->first()?->id;
        }
        unset($data['patient_entry']);
        // New External orders are entirely independent. Existing links are audit history.
        foreach (['patient_id', 'doctor_id', 'assistant_employee_id'] as $field) {
            $data[$field] = $record?->{$field};
        }

        return $data;
    }
}
