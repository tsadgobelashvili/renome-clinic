<?php

namespace App\Services;

use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Support\Currency;
use Illuminate\Support\Facades\DB;

class PartnerVisitPaymentRecorder
{
    /** @param array<int, array<string, mixed>> $rows */
    public function record(Patient $patient, array $rows, ?string $historicalDate = null, ?int $visitId = null, ?string $requestKey = null): void
    {
        $data = ['payment_request_key' => $requestKey, 'visit_id' => $visitId, 'rows' => $rows, 'historical_date' => $historicalDate];
        app(VisitPaymentSubmission::class)->run($patient->id, $data, function (int $submission) use ($patient, $rows, $historicalDate, $visitId) {
            if ($visitId === null) {
                $this->recordAllocated($patient, $rows, $historicalDate, null, $submission);
                return null;
            }
            $visit = \App\Models\Visit::where('patient_id', $patient->id)->findOrFail($visitId);
            app(VisitPaymentRouting::class)->post($visit, [
                'amount' => app(PaymentProcessor::class)->distributedAmount($rows, $visit->currency),
                'is_historical' => $historicalDate !== null, 'payment_date' => $historicalDate ?? today()->toDateString(),
            ], $rows, $submission);
            return $visit;
        });
    }

    /** Internal allocation writer: only inside a claimed, locked submission transaction. */
    public function recordAllocated(Patient $patient, array $rows, ?string $historicalDate, ?int $visitId, int $submission): void
    {
        abort_unless(DB::transactionLevel() > 0 && DB::table('visit_payment_submissions')->where('id', $submission)
            ->where('patient_id', $patient->id)->where('created_by', auth()->id())->exists(), 403);
        if ($historicalDate !== null) {
            HistoricalPayment::attributes(['is_historical' => true, 'payment_date' => $historicalDate]);
        }
        DB::transaction(function () use ($patient, $rows, $historicalDate, $visitId): void {
            IsraeliFinanceBalanceLock::acquire();
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
