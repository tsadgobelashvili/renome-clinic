<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\Visit;
use Illuminate\Support\Facades\DB;

class PatientDoctorAssignment
{
    private const CATEGORY_ROLES = [
        'orthopedics' => 'ორთოპედი',
        'therapy' => 'თერაპევტი',
        'orthodontics' => 'ორთოდონტი',
        'periodontology' => 'პაროდონტოლოგი',
        'pediatric_dentistry' => 'ბავშვთა სტომატოლოგი',
    ];

    public function assignFromVisit(Visit $visit): void
    {
        if (blank($visit->patient_id) || blank($visit->doctor_id)) {
            return;
        }

        foreach ($this->rolesFor($visit) as $role) {
            DB::table('patient_doctor')->insertOrIgnore([
                'patient_id' => $visit->patient_id,
                'doctor_id' => $visit->doctor_id,
                'role' => $role,
                'assignment_source' => 'auto',
                'is_primary' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function backfillExistingVisits(): int
    {
        $assigned = 0;

        Visit::query()
            ->whereNotNull('patient_id')
            ->whereNotNull('doctor_id')
            ->with(['doctor', 'treatmentCaseItems.treatmentCase'])
            ->orderBy('id')
            ->chunkById(200, function ($visits) use (&$assigned): void {
                foreach ($visits as $visit) {
                    $this->assignFromVisit($visit);
                    $assigned++;
                }
            });

        return $assigned;
    }

    /** @return array<int, string> */
    private function rolesFor(Visit $visit): array
    {
        /** @var Doctor|null $doctor */
        $doctor = $visit->relationLoaded('doctor') ? $visit->doctor : $visit->doctor()->first();
        $items = $visit->relationLoaded('treatmentCaseItems')
            ? $visit->treatmentCaseItems
            : $visit->treatmentCaseItems()->with('treatmentCase')->get();
        $roles = $items->map(function ($item): ?string {
            $category = $item->treatmentCase?->category;

            if (array_key_exists((string) $category, self::CATEGORY_ROLES)) {
                return self::CATEGORY_ROLES[$category];
            }

            if ($category !== 'surgery') {
                return null;
            }

            $serviceName = mb_strtolower((string) $item->display_name);

            return str_contains($serviceName, 'იმპლანტ') || str_contains($serviceName, 'implant')
                ? 'იმპლანტოლოგი'
                : 'ქირურგი';
        })->filter()->unique()->values();

        if ($roles->isNotEmpty()) {
            return $roles->all();
        }

        return [filled($doctor?->specialty) ? trim($doctor->specialty) : 'ექიმი'];
    }
}
