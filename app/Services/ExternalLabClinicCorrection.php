<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\ExternalLabCharge;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\SalarySettlementItem;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Correct an unused external billing entry, never a salary or payment history. */
class ExternalLabClinicCorrection
{
    public function save(LabCase $case, Closure $save): bool
    {
        return DB::transaction(function () use ($case, $save): bool {
            // Match payout/work writes: doctor first, then case and source rows.
            $doctorId = $case->getRawOriginal('external_billing_doctor_id');
            if ($doctorId) {
                Doctor::whereKey($doctorId)->lockForUpdate()->firstOrFail();
            }
            $stored = LabCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            if ($stored->source !== 'external' || $stored->external_billing_doctor_id != $doctorId) {
                $this->blocked(); // A stale editor must not correct different accounting.
            }
            $patient = Patient::with('patientGroup')->whereKey($case->patient_id)->lockForUpdate()->first();
            if (! $patient || ! in_array($patient->patientGroup?->slug, [null, PatientGroup::CLINIC_SLUG], true)) {
                throw ValidationException::withMessages(['patient_id' => __('lab.source_group_mismatch')]);
            }
            $works = $stored->mainWorks()->reorder()->orderBy('id')->lockForUpdate()->get();
            $charges = ExternalLabCharge::whereIn('lab_main_work_id', $works->modelKeys())
                ->orderBy('id')->lockForUpdate()->get();
            // Deductions are the charge's only accounting FK in the existing schema.
            // Also protect work membership in historical doctor settlements.
            if ($charges->contains(fn ($charge) => $charge->doctor_id != $doctorId || $charge->deductions()->exists())
                || SalarySettlementItem::whereIn('lab_main_work_id', $works->modelKeys())
                    ->orWhereHas('visitTreatmentCase', fn ($query) => $query->whereIn('lab_main_work_id', $works->modelKeys()))
                    ->exists()) {
                $this->blocked();
            }
            $audit = [
                'case_id' => $stored->id, 'previous_source' => $stored->source,
                'previous_external_billing_doctor_id' => $stored->external_billing_doctor_id,
                'previous_patient_id' => $stored->patient_id, 'new_source' => 'clinic',
                'new_patient_id' => $patient->id, 'cancelled_external_charge_ids' => $charges->modelKeys(),
                'cancelled_external_charges' => $charges->map->only(['id', 'lab_main_work_id', 'doctor_id', 'unit_rate', 'amount'])->all(),
                'user_id' => auth()->id(), 'corrected_at' => now()->toIso8601String(),
            ];
            foreach ($charges as $charge) {
                $charge->delete(); // Retain the model's independent deduction protection.
            }
            $case->external_billing_doctor_id = null;
            if (! $save()) {
                throw ValidationException::withMessages(['source' => __('lab.external_correction_failed')]);
            }
            // Existing financial reclassification audit pattern; no fake payment audit.
            DB::afterCommit(fn () => Log::info('External laboratory case corrected to Clinic', $audit));

            return true;
        });
    }

    private function blocked(): never
    {
        throw ValidationException::withMessages(['source' => __('lab.external_correction_locked')]);
    }
}
