<?php

namespace App\Rules;

use App\Models\Patient;
use App\Support\PatientIdentifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class UniquePatientIdentifier implements ValidationRule
{
    public function __construct(private readonly ?int $ignorePatientId = null) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (PatientIdentifier::normalize($value) === null) {
            return;
        }

        if (Patient::query()->wherePersonalId($value)
            ->when($this->ignorePatientId !== null, fn ($query) => $query->whereKeyNot($this->ignorePatientId))
            ->exists()) {
            $fail('ამ პირადი ნომრით პაციენტი უკვე არსებობს.');
        }
    }
}
