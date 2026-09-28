<?php

namespace App\Services;

use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;

class PartnerVisitPaymentRecorder
{
    /** @param array<int, array<string, mixed>> $rows */
    public function record(Patient $patient, array $rows, ?string $historicalDate = null, ?int $visitId = null): void
    {
        if ($historicalDate !== null) {
            HistoricalPayment::attributes(['is_historical' => true, 'payment_date' => $historicalDate]);
        }
        DB::transaction(function () use ($patient, $rows, $historicalDate, $visitId): void {
            if ($visitId !== null) {
                \App\Models\Visit::query()->whereKey($visitId)->where('patient_id', $patient->id)->lockForUpdate()->firstOrFail();
            }
            foreach ($rows as $row) {
                PartnerPatientPayment::query()->create([
                    'visit_id' => $visitId,
                    'patient_id' => $patient->getKey(),
                    'amount' => $row['amount'],
                    'currency' => $row['currency'] ?? Currency::DEFAULT,
                    'payment_method' => $row['payment_method'],
                    'paid_at' => $historicalDate ?? now(),
                    'notes' => $historicalDate ? 'Historical visit payment' : 'Visit payment',
                ]);
            }
        });
    }
}
