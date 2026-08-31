<?php

namespace App\Services;

use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;

class PartnerVisitPaymentRecorder
{
    /** @param array<int, array<string, mixed>> $rows */
    public function record(Patient $patient, array $rows): void
    {
        DB::transaction(function () use ($patient, $rows): void {
            foreach ($rows as $row) {
                PartnerPatientPayment::query()->create([
                    'patient_id' => $patient->getKey(),
                    'amount' => $row['amount'],
                    'currency' => $row['currency'] ?? Currency::DEFAULT,
                    'payment_method' => $row['payment_method'],
                    'paid_at' => now(),
                    'notes' => 'Visit payment',
                ]);
            }
        });
    }
}
