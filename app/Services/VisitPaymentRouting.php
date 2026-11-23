<?php

namespace App\Services;

use App\Models\Visit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Route new receipts only. Never infer the allocation of historical Israeli receipts. */
class VisitPaymentRouting
{
    public function postCombined(Visit $visit, array $data, int $submission): void
    {
        $this->assertClaim($visit, $submission);
        $sales = app(ProductSaleService::class);
        $items = filled($data['products'] ?? []) ? $sales->normalizeItems($data['products']) : [];
        $total = Money::decimal(collect($items)->sum('line_total'));
        $parts = $sales->partitionPaymentRows($data['splits'] ?? [], $total, $visit->currency);
        if ($items !== []) {
            $sales->create(['items' => $items, 'payment_method' => $parts['product'][0]['payment_method'],
                'payment_rows' => $parts['product'], 'currency' => $visit->currency,
                'patient_id' => $visit->patient_id, 'visit_id' => $visit->id, 'sold_at' => now()]);
        }
        $amount = Money::decimal($data['amount'] - $total);
        if ($amount > 0) {
            $this->post($visit, [...$data, 'amount' => $amount], $parts['service'], $submission);
        }
    }

    public function gross(Visit $visit): array
    {
        $visit->loadMissing('treatmentCaseItems.treatmentCase');
        $ct = 0;
        foreach ($visit->treatmentCaseItems as $item) {
            if ($item->treatmentCase?->category === 'tomography') {
                $rate = ($item->currency ?: $visit->currency) === $visit->currency ? 1 : (float) $item->exchange_rate;
                $ct += Money::minorUnits($item->manipulation_total * $rate);
            }
        }

        return ['ct' => $ct / 100, 'treatment' => max(0, Money::minorUnits($visit->gross_amount) - $ct) / 100];
    }

    public function isMixed(Visit $visit): bool
    {
        $gross = $this->gross($visit);

        return $visit->patient->isIsraelPartner() && $gross['ct'] > 0 && $gross['treatment'] > 0;
    }

    public function paid(Visit $visit): float
    {
        $clinic = (float) $visit->payments()->where('currency', $visit->currency)->sum('amount');
        $partner = DB::table('visit_payment_allocations as a')->join('partner_patient_payments as p', 'p.id', '=', 'a.partner_patient_payment_id')
            ->where('a.visit_id', $visit->id)->whereNull('p.deleted_at')->where('a.currency', $visit->currency)->sum('a.settled_amount');

        return Money::decimal($clinic + (float) $partner);
    }

    public function post(Visit $visit, array $data, array $rows, int $submission): void
    {
        $this->assertClaim($visit, $submission);
        $visit = Visit::query()->lockForUpdate()->findOrFail($visit->id);
        abort_if($visit->is_cancelled, 422);
        $processor = app(PaymentProcessor::class);
        $prepared = $processor->prepare($data['amount'], $rows, $visit->currency);
        $amount = $prepared['amount'];
        $gross = $this->gross($visit);
        $israel = $visit->patient->isIsraelPartner();
        $mixed = $israel && $gross['ct'] > 0 && $gross['treatment'] > 0;
        $legacyPartner = $visit->patient->partnerPayments()->where('visit_id', $visit->id)
            ->whereNotIn('id', DB::table('visit_payment_allocations')->whereNotNull('partner_patient_payment_id')->select('partner_patient_payment_id'))->exists();
        if ($israel && $gross['ct'] > 0 && ($legacyPartner || ($mixed && $visit->payments()->whereNotIn('id',
            DB::table('visit_payment_allocations')->whereNotNull('payment_id')->select('payment_id'))->exists()))) {
            throw ValidationException::withMessages(['amount' => __('visit-payments.legacy')]);
        }
        $discount = Money::decimal($visit->discount_amount);
        $ctDiscount = $gross['treatment'] <= 0 ? $discount : (! $israel ? min($discount, $gross['ct']) : 0);
        if ($mixed && $discount > 0) {
            validator($data, ['ct_discount_amount' => 'required|numeric|min:0|decimal:0,2'])->validate();
            $ctDiscount = Money::decimal($data['ct_discount_amount']);
        }
        if ($ctDiscount > $discount || $ctDiscount > $gross['ct'] || $discount - $ctDiscount > $gross['treatment']) {
            throw ValidationException::withMessages(['ct_discount_amount' => __('visit-payments.invalid_allocation')]);
        }
        $targets = ['ct' => Money::decimal($gross['ct'] - $ctDiscount),
            'treatment' => Money::decimal($gross['treatment'] - ($discount - $ctDiscount))];
        $ctAmount = $gross['ct'] > 0 && ! $mixed ? $amount : 0;
        if ($mixed) {
            validator($data, ['ct_payment_amount' => 'required|numeric|min:0|decimal:0,2',
                'treatment_payment_amount' => 'required|numeric|min:0|decimal:0,2'])->validate();
            $ctAmount = Money::decimal($data['ct_payment_amount']);
            if (Money::minorUnits($ctAmount) + Money::minorUnits($data['treatment_payment_amount']) !== Money::minorUnits($amount)) {
                throw ValidationException::withMessages(['ct_payment_amount' => __('visit-payments.invalid_allocation')]);
            }
        }
        $otherAmount = Money::decimal($amount - $ctAmount);
        if ($israel && $gross['ct'] > 0) {
            foreach (['ct' => $ctAmount, 'treatment' => $otherAmount] as $bucket => $value) {
                $paid = DB::table('visit_payment_allocations as a')
                    ->leftJoin('payments as p', 'p.id', '=', 'a.payment_id')
                    ->leftJoin('partner_patient_payments as pp', 'pp.id', '=', 'a.partner_patient_payment_id')
                    ->where('a.visit_id', $visit->id)->where('a.bucket', $bucket)
                    ->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('p.id')->whereNull('p.deleted_at'))
                        ->orWhere(fn ($q) => $q->whereNotNull('pp.id')->whereNull('pp.deleted_at')))
                    ->sum(DB::raw('CASE WHEN p.id IS NOT NULL THEN p.amount ELSE a.settled_amount END'));
                if (! $mixed && $bucket === 'ct') {
                    $paid = $this->paid($visit);
                }
                if (Money::minorUnits($paid) > Money::minorUnits($targets[$bucket])
                    || Money::minorUnits($value) > max(0, Money::minorUnits($targets[$bucket]) - Money::minorUnits($paid))) {
                    throw ValidationException::withMessages(['amount' => __('visit-payments.exceeds_bucket')]);
                }
            }
        }
        $history = HistoricalPayment::attributes($data);
        if (! $history['is_historical'] && filled($data['payment_date'] ?? null)) {
            validator($data, ['payment_date' => 'date_format:Y-m-d'])->validate();
            $history['payment_date'] = $data['payment_date'];
        }
        if (! $israel) {
            $ctAmount = $amount;
            $otherAmount = 0;
        }
        $partition = $ctAmount <= 0 ? ['product' => [], 'service' => $prepared['rows']]
            : ($otherAmount <= 0 ? ['product' => $prepared['rows'], 'service' => []]
                : app(ProductSaleService::class)->partitionPaymentRows($prepared['rows'], $ctAmount, $visit->currency));
        $snapshot = json_encode(['gross' => $gross, 'discount' => $discount, 'ct_discount' => $ctDiscount, 'targets' => $targets], JSON_THROW_ON_ERROR);
        $base = ['submission_id' => $submission, 'visit_id' => $visit->id, 'currency' => $visit->currency,
            'obligation_snapshot' => $snapshot, 'created_at' => now()];
        if ($ctAmount > 0) {
            $payment = $processor->process(['visit_id' => $visit->id, 'amount' => $ctAmount, 'currency' => $visit->currency, ...$history], $partition['product']);
            DB::table('visit_payment_allocations')->insert([...$base, 'payment_id' => $payment->id,
                'bucket' => $israel && $gross['ct'] > 0 ? 'ct' : 'clinic', 'settled_amount' => $ctAmount]);
        }
        if ($otherAmount > 0) {
            $before = $visit->patient->partnerPayments()->where('visit_id', $visit->id)->pluck('id');
            app(PartnerVisitPaymentRecorder::class)->recordAllocated($visit->patient, $partition['service'], $history['is_historical'] ? $history['payment_date'] : null, $visit->id, $submission);
            $created = $visit->patient->partnerPayments()->where('visit_id', $visit->id)->whereNotIn('id', $before)->orderBy('id')->get();
            $left = Money::minorUnits($otherAmount);
            foreach ($created as $index => $receipt) {
                $row = $partition['service'][$index];
                $value = $index === $created->count() - 1 ? $left : min($left, Money::minorUnits($processor->distributedAmount([$row], $visit->currency)));
                DB::table('visit_payment_allocations')->insert([...$base, 'partner_patient_payment_id' => $receipt->id,
                    'bucket' => 'treatment', 'settled_amount' => $value / 100]);
                $left -= $value;
            }
        }
    }

    private function assertClaim(Visit $visit, int $submission): void
    {
        abort_unless(DB::transactionLevel() > 0 && DB::table('visit_payment_submissions')->where('id', $submission)
            ->where('patient_id', $visit->patient_id)->where('created_by', auth()->id())->exists(), 403);
    }
}
