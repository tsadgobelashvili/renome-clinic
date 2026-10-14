<?php

namespace App\Services;

use App\Models\CashboxDay;
use App\Models\CashboxTransaction;
use App\Models\FinanceTransaction;
use App\Models\PatientGroup;
use App\Support\CashboxManager;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class IrrigatorSourceCorrection
{
    public function find(int $id): CashboxTransaction
    {
        abort_unless(auth()->user()?->canManageOwnerModules(), 403);
        $record = CashboxTransaction::findOrFail($id);
        $this->assertEligible($record);

        return $record;
    }

    public function correct(int $id, string $source, string $expectedSource): CashboxTransaction
    {
        abort_unless(auth()->user()?->canManageOwnerModules(), 403);
        validator(compact('source', 'expectedSource'), [
            'source' => 'required|in:current_cashier,withdrawn_cash',
            'expectedSource' => 'required|in:current_cashier,withdrawn_cash',
        ])->validate();

        return DB::transaction(function () use ($id, $source, $expectedSource): CashboxTransaction {
            PatientGroup::whereKey(PatientGroup::clinicId())->lockForUpdate()->firstOrFail();
            $manager = app(CashboxManager::class);
            $today = $manager->today();
            // Serialize with held-pool consumers; match cash transfers' drawer ID order.
            $days = CashboxDay::whereIn('id', [$today->id, $this->find($id)->cashbox_day_id])
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $record = CashboxTransaction::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertEligible($record);
            $oldSource = $record->cash_usage_source ?? 'current_cashier';
            if ($oldSource === $source) {
                return $record; // A repeated submission never posts another movement or audit.
            }
            if ($oldSource !== $expectedSource) {
                throw ValidationException::withMessages(['source' => __('cash-usage.stale_source')]);
            }
            if (($cutover = $manager->cashCutoverDate()) && $record->transaction_date->toDateString() < $cutover) {
                throw ValidationException::withMessages(['source' => __('cash-usage.before_opening')]);
            }
            $physical = app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'];
            $accumulated = $manager->availableCashForOpening($days[$today->id])['GEL'];
            $available = $source === 'withdrawn_cash'
                ? min($accumulated, $physical)
                : max(round($physical - max($accumulated, 0), 2), 0);
            if (Money::minorUnits($record->amount) > Money::minorUnits($available)) {
                throw ValidationException::withMessages(['source' => __('cash-usage.insufficient_source', [
                    'source' => CashboxTransaction::cashUsageSources()[$source], 'available' => Currency::format($available, 'GEL'),
                ])]);
            }
            $amount = Currency::format($record->amount, 'GEL');
            $suffix = explode(' · Net 0.00 ₾', $record->description ?? '', 2)[1] ?? '';
            $description = CashboxTransaction::TYPE_LABELS['irrigator_withdrawal'].' — '.$amount
                .' · წყარო: '.FinanceTransaction::CASH_SOURCES[$source]
                .' · '.($source === 'withdrawn_cash' ? 'Accumulated cash' : 'Cash').' -'.$amount
                .' · Card +'.$amount.' · Net 0.00 ₾'.$suffix;
            $changes = ['cash_usage_source' => $source, 'description' => $description, 'updated_at' => now()];
            if ($days[$record->cashbox_day_id]->status === 'closed') {
                // Preserve the counted closing allocation. The available held pool changes
                // by precisely +/- amount instead of swallowing the correction at close.
                $changes['cash_usage_closed_source'] = $record->cash_usage_closed_source ?? $oldSource;
            }
            // Deliberately narrow update: closed drawers otherwise remain immutable.
            // No amount, date, identity, payment link or Card movement is written.
            CashboxTransaction::whereKey($record->id)->update($changes);
            $audit = [
                'transaction_id' => $record->id, 'old_source' => $oldSource, 'new_source' => $source,
                'amount' => $record->amount, 'currency' => 'GEL', 'user_id' => auth()->id(),
                'corrected_at' => now()->toIso8601String(), 'previous_description' => $record->description,
            ];
            DB::afterCommit(fn () => Log::info('Irrigator cash source corrected', $audit));

            return $record->refresh();
        }, 3);
    }

    private function assertEligible(CashboxTransaction $record): void
    {
        abort_unless($record->type === 'irrigator_withdrawal' && $record->currency === 'GEL'
            && $record->payment_method === 'cash' && Money::minorUnits($record->amount) > 0
            && in_array($record->cash_usage_source, [null, 'current_cashier', 'withdrawn_cash'], true)
            && ! collect($record->only(['payment_id', 'payment_split_id', 'patient_id', 'visit_id',
                'finance_transaction_id', 'product_sale_id', 'cash_transfer_id', 'employee_advance_id', 'employee_advance_key']))->contains(fn ($value) => $value !== null), 404);
    }
}
