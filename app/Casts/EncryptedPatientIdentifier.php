<?php

namespace App\Casts;

use App\Support\PatientIdentifier;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class EncryptedPatientIdentifier implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return PatientIdentifier::decrypt($value, $attributes['personal_id_hash'] ?? null);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $normalized = PatientIdentifier::normalize($value);
        $hash = PatientIdentifier::hash($normalized);

        return [
            'personal_id' => PatientIdentifier::encrypt($normalized),
            'personal_id_hash' => $hash,
        ];
    }
}
