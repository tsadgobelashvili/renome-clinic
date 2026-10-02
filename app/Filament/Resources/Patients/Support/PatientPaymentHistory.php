<?php

namespace App\Filament\Resources\Patients\Support;

use App\Models\Patient;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class PatientPaymentHistory
{
    /** Read-only table projection. Negative keys keep Israeli receipts distinct from Clinic receipts. */
    public static function query(Patient $patient): Builder
    {
        $clinic = DB::table('payments as p')->join('visits as v', 'v.id', '=', 'p.visit_id')
            ->where('v.patient_id', $patient->getKey())->whereNull('p.deleted_at')
            ->selectRaw("CAST(p.id AS BIGINT) as id, p.visit_id, p.payment_date, p.amount, p.currency, p.payment_method, p.comment, p.is_historical, p.deleted_at, 'clinic' as source");
        $partner = $patient->partnerPayments()->getQuery()->selectRaw(
            "CAST(-partner_patient_payments.id AS BIGINT) as id, visit_id, paid_at as payment_date, amount, currency, payment_method, notes as comment, FALSE as is_historical, deleted_at, 'israeli' as source"
        )->toBase();

        return Payment::query()->fromSub($clinic->unionAll($partner), 'payments')
            ->with(['splits', 'visit' => fn ($query) => $query->withCancelled()->where('patient_id', $patient->getKey())]);
    }
}
