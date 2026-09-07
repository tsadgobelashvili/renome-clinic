<?php

namespace App\Services;

use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IsraeliPatientPaymentService
{
    /** @return array<int, string> */
    public function suggestions(?string $search): array
    {
        if (blank($search)) {
            return [];
        }

        $terms = preg_split('/\s+/u', trim((string) $search), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return Patient::query()
            ->where('patient_group_id', PatientGroup::israelPartnerId())
            ->where(function (Builder $query) use ($terms): void {
                foreach ($terms as $term) {
                    $pattern = '%'.mb_strtolower($term).'%';
                    $query->where(fn (Builder $nameQuery): Builder => $nameQuery
                        ->whereRaw('LOWER(first_name) LIKE ?', [$pattern])
                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$pattern]));
                }
            })
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(30)
            ->get()
            ->map(fn (Patient $patient): string => $this->patientLabel($patient))
            ->all();
    }

    public function patientIdFromLabel(?string $label): ?int
    {
        return filled($label) && preg_match('/\[ID:(\d+)\]$/', trim((string) $label), $matches)
            ? (int) $matches[1]
            : null;
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): PartnerPatientPayment
    {
        $birthDate = $this->parseBirthDate($data['birth_date'] ?? null);

        return DB::transaction(function () use ($birthDate, $data): PartnerPatientPayment {
            $patientId = $data['patient_id'] ?? $this->patientIdFromLabel($data['patient_search'] ?? null);
            $patient = $patientId ? $this->selectedPatient((int) $patientId) : null;

            if (! $patient) {
                $firstName = trim((string) ($data['first_name'] ?? ''));
                $lastName = trim((string) ($data['last_name'] ?? ''));
                if ($firstName === '' || $lastName === '') {
                    throw ValidationException::withMessages([
                        'patient_search' => 'აირჩიეთ არსებული პაციენტი ან შეავსეთ ახალი პაციენტის სახელი და გვარი.',
                    ]);
                }

                $matches = $this->exactNameMatches($firstName, $lastName);
                if ($birthDate !== null) {
                    $patient = (clone $matches)->whereDate('birth_date', $birthDate)->first();
                } elseif ((clone $matches)->exists()) {
                    $labels = (clone $matches)->limit(5)->get()->map(fn (Patient $match): string => $this->patientLabel($match))->implode('; ');
                    throw ValidationException::withMessages([
                        'patient_search' => 'ამ სახელით პაციენტი უკვე არსებობს. აირჩიეთ სიიდან: '.$labels,
                    ]);
                }

                $patient ??= Patient::query()->create([
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'birth_date' => $birthDate,
                    'patient_group_id' => PatientGroup::israelPartnerId(),
                ]);
            }

            return $patient->partnerPayments()->create([
                'amount' => $data['amount'],
                'currency' => $data['currency'],
                'payment_method' => $data['payment_method'],
                'paid_at' => $data['paid_at'],
                'notes' => $data['notes'] ?? null,
            ]);
        });
    }

    public function patientLabel(Patient $patient): string
    {
        $birthDate = $patient->birth_date?->format('d.m.Y') ?? 'თარიღი უცნობია';

        return "{$patient->full_name} · {$birthDate} · {$patient->formatted_patient_number} [ID:{$patient->getKey()}]";
    }

    public function parseBirthDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        foreach (['d.m.Y', 'Y-m-d'] as $format) {
            try {
                $date = Carbon::createFromFormat('!'.$format, trim((string) $value));
                if ($date && $date->format($format) === trim((string) $value)) {
                    return $date->toDateString();
                }
            } catch (\Throwable) {
                // Try the next explicitly supported format.
            }
        }

        throw ValidationException::withMessages([
            'birth_date' => 'დაბადების თარიღი შეიყვანეთ ფორმატით დღე.თვე.წელი, მაგალითად 04.09.1985.',
        ]);
    }

    private function selectedPatient(int $patientId): Patient
    {
        $patient = Patient::query()
            ->where('patient_group_id', PatientGroup::israelPartnerId())
            ->find($patientId);

        if (! $patient) {
            throw ValidationException::withMessages([
                'patient_search' => 'არჩეული პაციენტი არ ეკუთვნის ისრაელის პაციენტების ჯგუფს.',
            ]);
        }

        return $patient;
    }

    private function exactNameMatches(string $firstName, string $lastName): Builder
    {
        return Patient::query()
            ->where('patient_group_id', PatientGroup::israelPartnerId())
            ->whereRaw('LOWER(TRIM(first_name)) = ?', [mb_strtolower($firstName)])
            ->whereRaw('LOWER(TRIM(last_name)) = ?', [mb_strtolower($lastName)]);
    }
}
