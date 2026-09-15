<?php

namespace App\Services;

use App\Models\LabCase;
use Illuminate\Support\Facades\Validator;

final class ExternalLabCaseData
{
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
        unset($data['patient_entry']);
        // New External orders are entirely independent. Existing links are audit history.
        foreach (['patient_id', 'doctor_id', 'assistant_employee_id'] as $field) {
            $data[$field] = $record?->{$field};
        }

        return $data;
    }
}
