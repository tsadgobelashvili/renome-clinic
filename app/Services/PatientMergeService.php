<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\User;
use App\Support\PatientIdentifier;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PatientMergeService
{
    /** @var array<int, string> */
    private const DIRECT_PATIENT_TABLES = [
        'visits',
        'lab_cases',
        'treatment_estimates',
        'partner_patient_payments',
        'product_sales',
        'cashbox_transactions',
    ];

    public function merge(
        Patient $primary,
        Patient $duplicate,
        User $actor,
        bool $copyMissingFields = true,
    ): Patient {
        if (! $actor->isOwner() && ! $actor->isAdministrator()) {
            throw new AuthorizationException('Only an Owner or Administrator may merge patients.');
        }

        if ($primary->is($duplicate)) {
            throw ValidationException::withMessages([
                'duplicate_patient_id' => 'The primary and duplicate patient must be different records.',
            ]);
        }

        return DB::transaction(function () use ($primary, $duplicate, $actor, $copyMissingFields): Patient {
            $locked = Patient::query()
                ->whereKey([$primary->getKey(), $duplicate->getKey()])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $primary = $locked->get($primary->getKey());
            $duplicate = $locked->get($duplicate->getKey());
            if (! $primary || ! $duplicate) {
                throw ValidationException::withMessages([
                    'duplicate_patient_id' => 'One of the selected patients no longer exists.',
                ]);
            }

            $duplicateSnapshot = $duplicate->attributesToArray();
            // The audit snapshot must not recreate a plaintext copy of the identifier.
            $duplicateSnapshot['personal_id'] = PatientIdentifier::encrypt($duplicate->personal_id);
            $movedRecords = [];
            foreach (self::DIRECT_PATIENT_TABLES as $table) {
                $movedRecords[$table] = DB::table($table)
                    ->where('patient_id', $duplicate->getKey())
                    ->update(['patient_id' => $primary->getKey()]);
            }
            $movedRecords['patient_doctor'] = $this->mergeDoctorAssignments($primary, $duplicate);

            $copiedFields = $copyMissingFields ? $this->missingIdentityValues($primary, $duplicate) : [];
            $mergedNotes = $this->mergeNotes($primary, $duplicate);

            $duplicate->delete();

            if ($mergedNotes !== $primary->notes) {
                $copiedFields['notes'] = $mergedNotes;
            }
            if ($copiedFields !== []) {
                $primary->fill($copiedFields)->save();
            }

            DB::table('patient_merges')->insert([
                'primary_patient_id' => $primary->getKey(),
                'duplicate_patient_id' => $duplicate->getKey(),
                'duplicate_patient_snapshot' => json_encode($duplicateSnapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                'moved_records' => json_encode($movedRecords, JSON_THROW_ON_ERROR),
                'copied_fields' => $copiedFields === [] ? null : json_encode(array_keys($copiedFields), JSON_THROW_ON_ERROR),
                'merged_by' => $actor->getKey(),
                'merged_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            return $primary->fresh();
        });
    }

    private function mergeDoctorAssignments(Patient $primary, Patient $duplicate): int
    {
        $moved = 0;
        $assignments = DB::table('patient_doctor')->where('patient_id', $duplicate->getKey())->orderBy('id')->get();

        foreach ($assignments as $assignment) {
            $existing = DB::table('patient_doctor')
                ->where('patient_id', $primary->getKey())
                ->where('doctor_id', $assignment->doctor_id)
                ->where('role', $assignment->role)
                ->first();

            if ($existing) {
                DB::table('patient_doctor')->where('id', $existing->id)->update([
                    'is_primary' => (bool) $existing->is_primary || (bool) $assignment->is_primary,
                    'updated_at' => now(),
                ]);
                DB::table('patient_doctor')->where('id', $assignment->id)->delete();
            } else {
                DB::table('patient_doctor')->where('id', $assignment->id)->update([
                    'patient_id' => $primary->getKey(),
                    'updated_at' => now(),
                ]);
            }
            $moved++;
        }

        return $moved;
    }

    /** @return array<string, mixed> */
    private function missingIdentityValues(Patient $primary, Patient $duplicate): array
    {
        return collect(['first_name_latin', 'last_name_latin', 'lab_display_name', 'phone', 'personal_id', 'birth_date'])
            ->filter(fn (string $field): bool => blank($primary->{$field}) && filled($duplicate->{$field}))
            ->mapWithKeys(fn (string $field): array => [$field => $duplicate->{$field}])
            ->all();
    }

    private function mergeNotes(Patient $primary, Patient $duplicate): ?string
    {
        $primaryNotes = trim((string) $primary->notes);
        $duplicateNotes = trim((string) $duplicate->notes);
        if ($duplicateNotes === '' || $duplicateNotes === $primaryNotes) {
            return $primary->notes;
        }
        if ($primaryNotes === '') {
            return $duplicateNotes;
        }

        return $primaryNotes."\n\nMerged patient notes (#{$duplicate->patient_number}):\n".$duplicateNotes;
    }
}
