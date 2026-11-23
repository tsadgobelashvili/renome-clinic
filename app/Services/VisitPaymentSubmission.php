<?php

namespace App\Services;

use App\Models\Patient;
use App\Models\Payment;
use App\Models\Visit;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Serialize the complete create/save/payment operation; one UUID describes one user confirmation. */
class VisitPaymentSubmission
{
    public function run(int $patientId, array $data, Closure $operation): ?Visit
    {
        Gate::authorize('create', Payment::class);
        validator($data, ['payment_request_key' => 'required|uuid'])->validate();
        $key = $data['payment_request_key'];
        $hash = hash('sha256', serialize($this->canonical([$patientId, auth()->id(), $data])));

        return IsraeliFinanceBalanceLock::transaction(function () use ($patientId, $key, $hash, $operation): ?Visit {
            Patient::query()->whereKey($patientId)->lockForUpdate()->firstOrFail();
            $previous = DB::table('visit_payment_submissions')->where('request_key', $key)->first();
            if ($previous) {
                if (! hash_equals($previous->payload_hash, $hash)) {
                    throw ValidationException::withMessages(['payment_request_key' => __('visit-payments.changed_request')]);
                }

                return $previous->visit_id ? Visit::findOrFail($previous->visit_id) : null;
            }
            $submission = DB::table('visit_payment_submissions')->insertGetId([
                'request_key' => $key, 'payload_hash' => $hash, 'patient_id' => $patientId,
                'created_by' => auth()->id(), 'created_at' => now(),
            ]);
            $visit = $operation($submission);
            DB::table('visit_payment_submissions')->where('id', $submission)->update(['visit_id' => $visit?->id]);

            return $visit;
        });
    }

    private function canonical(mixed $value): mixed
    {
        if (is_array($value)) {
            // Filament regenerates repeater UUIDs when the same confirmation is hydrated again.
            if ($value !== [] && collect(array_keys($value))->every(fn ($key) => is_string($key)
                && (Str::isUuid($key) || str_starts_with($key, 'record-')))) {
                $value = array_values($value);
            }
            foreach (['payment_request_key', 'acknowledge_duplicate', 'amount_manually_overridden', 'manipulation_name',
                'patient_search', 'doctor_search', 'dashboard_payment_auto_sync'] as $uiOnly) {
                unset($value[$uiOnly]);
            }
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => $this->canonical($item), $value);
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        return is_numeric($value) ? (string) (float) $value : $value;
    }
}
