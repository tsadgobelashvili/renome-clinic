<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\PartnerFinanceTransaction;
use App\Models\PatientGroup;
use App\Models\SalaryPayout;
use App\Models\SalarySettlement;
use App\Support\Money;
use Illuminate\Support\Collection;

/** Read saved payout/settlement links, never the visits in a date range. */
class IsraeliDoctorPayoutHistory
{
    public function __construct(private readonly IsraeliPayoutComposition $composition) {}

    public function forDoctor(int $doctorId): Collection
    {
        $settlements = SalarySettlement::query()->where('doctor_id', $doctorId)
            ->where('patient_group_slug', PatientGroup::ISRAEL_PARTNER_SLUG)->where('status', 'confirmed')
            ->with(['payouts.allocations.financeTransaction', 'payouts.allocations.partnerFinanceTransaction',
                'items.visit.patient', 'items.visitTreatmentCase.treatmentCase', 'items.labMainWork.labCase.patient',
                'partnerFinanceTransaction', 'clinicFinanceTransaction'])->get();
        $entries = collect();
        foreach ($settlements as $settlement) {
            if ($settlement->uses_allocations) {
                $covered = 0;
                foreach ($settlement->payouts->sortBy('id') as $payout) {
                    $covered += Money::minorUnits($payout->total_gel);
                    $entries->push($this->payout($settlement, $payout, $covered / 100));
                }
            } elseif ($settlement->payment_amount !== null && (float) $settlement->payment_amount > 0) {
                $entries->push($this->legacySettlement($settlement));
            }
        }
        // Only explicit doctor IDs are trusted; recipient-name/date matching is unsafe.
        foreach (PartnerFinanceTransaction::query()->where('doctor_id', $doctorId)->where('source', 'israeli')
            ->where('type', 'expense')->where('category', 'doctor_salary')
            ->whereNull('salary_settlement_id')->whereNull('salary_payout_allocation_id')->get() as $movement) {
            $entries->push([
                'key' => 'movement:'.$movement->id, 'date' => $movement->transacted_at,
                'payments' => [['amount' => (float) $movement->amount, 'currency' => $movement->currency,
                    'method' => $this->method($movement->from_account), 'rate' => $movement->exchange_rate, 'funding' => 'israeli']],
                'status' => 'recorded', 'source' => 'israeli', 'basis_currency' => null,
                'accrued' => null, 'covered' => null, 'remaining' => null, 'paid_basis' => null,
                'deducted' => 0, 'items' => [], 'composition' => 'unavailable', 'carry' => [],
            ]);
        }

        return $entries->sortByDesc(fn ($entry) => $entry['date']->format('Y-m-d H:i:s').'|'.$entry['key'])->values();
    }

    public function find(int $doctorId, string $key): array
    {
        $entry = $this->forDoctor($doctorId)->firstWhere('key', $key);
        abort_unless($entry, 404);

        return $entry;
    }

    private function payout(SalarySettlement $settlement, SalaryPayout $payout, float $covered): array
    {
        $snapshot = $payout->composition_snapshot;
        if ($snapshot !== null) {
            $detail = ['items' => $snapshot['items'] ?? [], 'composition' => 'snapshot'];
        } else {
            $full = $settlement->payouts->count() === 1 && (float) $payout->external_deduction_gel === 0.0
                ? $this->composition->fullSettlement($settlement, (float) $payout->total_gel) : null;
            $detail = $this->settlementComposition($settlement, $full);
        }
        $remaining = $snapshot['remaining_after'] ?? max(0, Money::decimal((float) $settlement->salary_total - $covered));

        return [
            'key' => 'payout:'.$payout->id, 'date' => $payout->created_at,
            'payments' => $payout->allocations->map(fn ($allocation) => [
                'amount' => (float) $allocation->amount, 'currency' => $allocation->currency,
                'rate' => $allocation->exchange_rate, 'equivalent' => (float) $allocation->gel_equivalent,
                'method' => $allocation->financeTransaction?->payment_method
                    ?? $this->method($allocation->partnerFinanceTransaction?->from_account)
                    ?? $snapshot['payment_method'] ?? $settlement->israeli_payment_method,
                'funding' => $allocation->source,
            ])->all(),
            'status' => $remaining > 0 ? 'partial' : 'paid', 'source' => 'israeli', 'basis_currency' => 'GEL',
            'accrued' => (float) ($snapshot['accrued'] ?? $settlement->salary_total),
            'covered' => (float) ($snapshot['covered_after'] ?? $covered), 'remaining' => (float) $remaining,
            'paid_basis' => Money::decimal((float) $payout->total_gel - (float) $payout->external_deduction_gel),
            'deducted' => (float) $payout->external_deduction_gel, 'items' => array_map(function ($row) {
                foreach (['accrued', 'paid', 'deducted', 'remaining'] as $field) {
                    $row[$field] = $row[$field] === null ? null : (float) $row[$field];
                }

                return $row;
            }, $detail['items']),
            'composition' => $detail['composition'], 'carry' => [],
        ];
    }

    private function legacySettlement(SalarySettlement $settlement): array
    {
        $method = $this->method($settlement->partnerFinanceTransaction?->from_account)
            ?? $settlement->clinicFinanceTransaction?->payment_method;
        $amount = (float) ($settlement->actual_paid_usd ?? $settlement->payment_amount);
        $carry = [];
        foreach (['calculated_usd', 'actual_paid_usd', 'opening_carry_usd', 'closing_carry_usd', 'difference_usd'] as $field) {
            if ($settlement->$field !== null) {
                $carry[$field] = (float) $settlement->$field;
            }
        }
        $hasCarry = collect(['opening_carry_usd', 'closing_carry_usd', 'difference_usd'])
            ->contains(fn ($field) => Money::minorUnits($settlement->$field) !== 0);
        $expected = $settlement->payment_currency === $settlement->currency ? (float) $settlement->salary_total
            : ($settlement->payment_exchange_rate > 0 ? ($settlement->currency === 'GEL'
                ? round((float) $settlement->salary_total / (float) $settlement->payment_exchange_rate, 2)
                : round((float) $settlement->salary_total * (float) $settlement->payment_exchange_rate, 2)) : null);
        $full = ! $hasCarry && $expected !== null && Money::minorUnits($expected) === Money::minorUnits($amount)
            ? $this->composition->fullSettlement($settlement, (float) $settlement->salary_total) : null;
        $detail = $this->settlementComposition($settlement, $full);

        return [
            'key' => 'settlement:'.$settlement->id, 'date' => $settlement->settled_at,
            'payments' => [['amount' => $amount, 'currency' => $settlement->payment_currency, 'method' => $method,
                'rate' => $settlement->payment_exchange_rate, 'funding' => null]],
            'status' => 'recorded', 'source' => 'israeli', 'basis_currency' => $settlement->currency,
            'accrued' => (float) $settlement->salary_total, 'covered' => $full === null ? null : (float) $settlement->salary_total,
            'remaining' => $full === null ? null : 0.0,
            'paid_basis' => $full === null ? null : (float) $settlement->salary_total,
            'deducted' => 0, 'items' => $detail['items'], 'composition' => $detail['composition'],
            'carry' => $carry,
        ];
    }

    /** Membership is saved even when this payment's per-work allocation is unknown. */
    private function settlementComposition(SalarySettlement $settlement, ?array $fullyPaidItems): array
    {
        if ($fullyPaidItems !== null) {
            return ['items' => $fullyPaidItems, 'composition' => 'linked'];
        }
        $items = array_map(fn ($row) => [...$row, 'paid' => null, 'deducted' => null, 'remaining' => null],
            $this->composition->workRows($settlement));

        return ['items' => $items, 'composition' => $items === [] ? 'unavailable' : 'settlement'];
    }

    private function method(?string $account): ?string
    {
        return match ($account) {
            'cash' => PaymentMethod::Cash->value, 'bank' => PaymentMethod::BankTransfer->value, default => null,
        };
    }
}
