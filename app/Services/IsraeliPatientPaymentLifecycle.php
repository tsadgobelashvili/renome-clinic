<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\PartnerPatientPayment;
use App\Models\PartnerPaymentAudit;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Receipts are the authoritative ledger. Replacing/soft-cancelling one row changes its contribution once. */
class IsraeliPatientPaymentLifecycle
{
    public const FIELDS = ['patient_id', 'amount', 'currency', 'paid_at', 'payment_method', 'notes'];

    public function protectionReason(PartnerPatientPayment $payment): ?string
    {
        if ($payment->trashed()) {
            return __('israeli-payments.cancelled');
        }
        // Visit receipts are owned by the visit lifecycle and may underpin salary snapshots.
        // Never detach or independently rewrite their financial effect from Finance.
        return $payment->visit_id ? __('israeli-payments.visit_protected') : null;
    }

    public function update(int $id, array $data): PartnerPatientPayment
    {
        return $this->mutate($id, 'updated', $data);
    }

    public function cancel(int $id, array $data): PartnerPatientPayment
    {
        return $this->mutate($id, 'cancelled', $data);
    }

    /** Explicit Owner-only recovery; no new payment, posting or timestamp is created. */
    public function restore(int $id, array $data): PartnerPatientPayment
    {
        return $this->mutate($id, 'restored', $data);
    }

    private function mutate(int $id, string $action, array $data): PartnerPatientPayment
    {
        abort_unless(auth()->user()?->canManageOwnerModules(), 403);
        validator($data, ['expected_version' => 'required|integer|min:0', 'operation_key' => 'required|uuid'])->validate();
        $values = $action === 'updated' ? Arr::only($data, self::FIELDS) : [];
        if ($action !== 'updated') {
            validator($data, ['reason' => 'required|string|max:500'])->validate();
            $values['reason'] = trim($data['reason']);
        }
        if (array_key_exists('account', $data)) {
            $values['account'] = $data['account'];
        }
        $hash = hash('sha256', json_encode([$action, (int) $data['expected_version'], $values], JSON_THROW_ON_ERROR));

        return IsraeliFinanceBalanceLock::transaction(function () use ($id, $action, $data, $values, $hash): PartnerPatientPayment {
            $payment = PartnerPatientPayment::withTrashed()->lockForUpdate()->findOrFail($id);
            Gate::authorize('view', $payment);
            $replay = PartnerPaymentAudit::where('operation_key', $data['operation_key'])->first();
            if ($replay) {
                if ((int) $replay->partner_patient_payment_id !== $id || $replay->payload_hash !== $hash
                    || (int) $replay->user_id !== (int) auth()->id()) {
                    throw ValidationException::withMessages(['payment' => __('israeli-payments.conflict')]);
                }

                return $payment; // A successful retry has no second accounting or audit effect.
            }
            Gate::authorize($action === 'updated' ? 'update' : ($action === 'restored' ? 'restore' : 'delete'), $payment);
            if ($payment->visit_id || ($action === 'restored' ? ! $payment->cancelled_at : $payment->trashed())) {
                throw ValidationException::withMessages(['payment' => $payment->visit_id
                    ? __('israeli-payments.visit_protected') : __('israeli-payments.cancelled')]);
            }
            $before = $this->snapshot($payment);
            $balances = app(PartnerFinanceSummary::class)->accountBalances();
            $cash = app(FinanceUsdUsageService::class)->cashBalances('israeli');
            $claim = PartnerPatientPayment::withTrashed()->whereKey($id)
                ->where('lock_version', (int) $data['expected_version'])
                ->when($action === 'restored', fn ($q) => $q->whereNotNull('deleted_at')->whereNotNull('cancelled_at'),
                    fn ($q) => $q->whereNull('deleted_at')->whereNull('cancelled_at'))
                ->update(['lock_version' => (int) $data['expected_version'] + 1]);
            if ($claim !== 1) {
                throw ValidationException::withMessages(['payment' => __('israeli-payments.conflict')]);
            }
            $payment->forceFill(['lock_version' => (int) $data['expected_version'] + 1]);
            if ($action === 'updated') {
                $payment->fill(Arr::only($values, self::FIELDS));
                $method = PaymentMethod::normalize($payment->payment_method);
                if (isset($values['account']) && $values['account'] !== ($method === 'cash' ? 'cash' : 'bank')) {
                    throw ValidationException::withMessages(['account' => __('israeli-payments.account_method')]);
                }
                validator($payment->attributesToArray(), ['patient_id' => 'required|integer', 'paid_at' => 'required|date',
                    'amount' => 'required|numeric|min:0.01', 'notes' => 'nullable|string|max:500'])->validate();
                $payment->save(); // Reuse all existing receipt currency, method and patient validation.
            } elseif ($action === 'cancelled') {
                $changes = ['cancelled_at' => now(), 'cancelled_by' => auth()->id(),
                    'cancellation_reason' => $values['reason'], 'deleted_at' => now()];
                PartnerPatientPayment::withTrashed()->whereKey($id)->update($changes);
            } else {
                PartnerPatientPayment::withTrashed()->whereKey($id)->update([
                    'cancelled_at' => null, 'cancelled_by' => null, 'cancellation_reason' => null, 'deleted_at' => null]);
            }
            $payment = $payment->fresh();
            $afterBalances = app(PartnerFinanceSummary::class)->accountBalances();
            $afterCash = app(FinanceUsdUsageService::class)->cashBalances('israeli');
            foreach ($afterBalances as $account => $currencies) {
                foreach ($currencies as $currency => $amount) {
                    if (\App\Support\Money::minorUnits($amount) < min(0, \App\Support\Money::minorUnits($balances[$account][$currency]))) {
                        throw ValidationException::withMessages(['payment' => __('israeli-payments.funds_used')]);
                    }
                }
            }
            foreach ($afterCash as $currency => $amount) {
                if (\App\Support\Money::minorUnits($amount) < min(0, \App\Support\Money::minorUnits($cash[$currency]))) {
                    throw ValidationException::withMessages(['payment' => __('israeli-payments.funds_used')]);
                }
            }
            PartnerPaymentAudit::create(['partner_patient_payment_id' => $id, 'user_id' => auth()->id(),
                'actor_name' => auth()->user()->name, 'action' => $action, 'operation_key' => $data['operation_key'],
                'payload_hash' => $hash, 'old_values' => $before, 'new_values' => [...$this->snapshot($payment),
                    ...($action === 'restored' ? ['restoration_reason' => $values['reason']] : [])]]);

            return $payment;
        });
    }

    private function snapshot(PartnerPatientPayment $payment): array
    {
        return [...Arr::only($payment->attributesToArray(), [...self::FIELDS, 'id', 'visit_id', 'created_at', 'updated_at',
            'lock_version', 'deleted_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason']),
            'patient_name' => $payment->patient?->full_name,
            'account' => $payment->payment_method === 'cash' ? 'cash' : 'bank'];
    }
}
