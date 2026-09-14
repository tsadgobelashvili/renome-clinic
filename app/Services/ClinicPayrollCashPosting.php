<?php

namespace App\Services;

use App\Models\FinanceTransaction;
use App\Models\PayrollEntry;
use App\Models\SalarySettlement;
use App\Support\CashboxManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Shared by individual and batch finalizers, inside their database transaction. */
class ClinicPayrollCashPosting
{
    public function record(PayrollEntry|SalarySettlement $record): void
    {
        if (($record instanceof PayrollEntry ? $record->source : $record->patient_group_slug) !== 'clinic') {
            return;
        }
        DB::transaction(function () use ($record) {
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            $employee = $record instanceof PayrollEntry;
            if ($record->status !== ($employee ? 'finalized' : 'confirmed')) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.immutable')]);
            }
            if (($employee ? $record->source : $record->patient_group_slug) !== 'clinic'
                || ($employee ? $record->payment_method : $record->clinic_payment_method) !== 'cash') {
                return;
            }
            $reference = $employee ? 'payroll_entry_id' : 'salary_settlement_id';
            if (FinanceTransaction::query()->where($reference, $record->id)->exists()) {
                return;
            }
            $amount = (float) ($employee ? $record->net_amount : $record->salary_total);
            if ($amount <= 0) {
                return;
            }
            $cashbox = app(CashboxManager::class);
            $day = $cashbox->today();
            $day->newQuery()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            $balances = app(FinanceUsdUsageService::class)->cashBalances('clinic');
            if (Money::minorUnits($amount) > Money::minorUnits($balances[$record->currency] ?? 0)) {
                throw ValidationException::withMessages(['payroll' => __('clinic-payroll.insufficient_cash', ['currency' => $record->currency])]);
            }
            app(FinanceManager::class)->create([
                $reference => $record->id, 'type' => 'expense', 'transaction_date' => now(),
                'category' => 'salary', 'description' => __('salaries.title').' — '.($employee ? $record->employee->full_name : $record->doctor->full_name),
                'amount' => $amount, 'currency' => $record->currency, 'payment_method' => 'cash',
                'cash_source' => 'current_cashier', 'funding_source' => 'clinic', 'created_by' => auth()->id(),
            ]);
            if ($employee) {
                $record->update(['payout_status' => 'paid']);
            }
        });
    }
}
