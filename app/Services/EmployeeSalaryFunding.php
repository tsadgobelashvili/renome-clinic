<?php

namespace App\Services;

use App\Models\EmployeeSalarySettlement;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\PatientGroup;
use App\Support\CashboxManager;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class EmployeeSalaryFunding
{
    public function availableCash(string $source): float
    {
        $balance = app(FinanceUsdUsageService::class)->cashBalances($source)['GEL'];
        if ($source === 'clinic') {
            $manager = app(CashboxManager::class);
            $balance = min($balance, $manager->availableCashForOpening($manager->dayFor(today()->toDateString()))['GEL']);
        }

        return max(0, $balance);
    }

    // Called inside the salary transaction, after locking employee and work rows.
    public function pay(EmployeeSalarySettlement $settlement, array $allocation): void
    {
        DB::transaction(function () use ($settlement, $allocation): void {
            $locked = EmployeeSalarySettlement::query()->lockForUpdate()->findOrFail($settlement->id);
            if ($locked->status !== 'confirmed') {
                throw ValidationException::withMessages(['actual_paid_gel' => __('employees.salary.reverse_only')]);
            }
            if ($locked->actual_paid_gel !== null) {
                return;
            }
            $this->record($locked, $allocation);
            $settlement->refresh();
        });
    }

    private function record(EmployeeSalarySettlement $settlement, array $allocation): void
    {
        Validator::make($allocation, [
            'clinic_cash_gel' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'israeli_cash_gel' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
        ])->validate();
        $clinic = Money::minorUnits($allocation['clinic_cash_gel']);
        $israeli = Money::minorUnits($allocation['israeli_cash_gel']);
        $paid = $clinic + $israeli;
        if ($paid <= 0) {
            throw ValidationException::withMessages(['clinic_cash_gel' => __('employees.salary.payment_required')]);
        }
        if ($paid > Money::minorUnits($settlement->total_gel)) {
            throw ValidationException::withMessages(['clinic_cash_gel' => __('employees.salary.over_allocation')]);
        }
        PatientGroup::query()->whereKey(PatientGroup::israelPartnerId())->lockForUpdate()->firstOrFail();
        $day = app(CashboxManager::class)->dayFor(now()->toDateString());
        $day->newQuery()->whereKey($day->id)->lockForUpdate()->firstOrFail();
        foreach (['clinic' => $clinic, 'israeli' => $israeli] as $source => $amount) {
            if ($amount > 0 && $amount > Money::minorUnits($this->availableCash($source))) {
                throw ValidationException::withMessages([$source.'_cash_gel' => __('employees.salary.insufficient_cash')]);
            }
        }
        $settlement->update([
            'actual_paid_gel' => $paid / 100,
            'clinic_cash_gel' => $clinic / 100,
            'israeli_cash_gel' => $israeli / 100,
            'closing_carry_gel' => (Money::minorUnits($settlement->total_gel) - $paid) / 100,
        ]);
        $expense = app(FinanceManager::class)->create([
            'employee_salary_settlement_id' => $settlement->id,
            'type' => 'expense', 'transaction_date' => $settlement->settled_at,
            'category' => 'lab_salary', 'description' => 'Lab technician salary — '.$settlement->employee->full_name,
            'amount' => $paid / 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'withdrawn_cash',
            'clinic_cash_gel' => $clinic / 100, 'israeli_cash_gel' => $israeli / 100,
            'note' => $this->description($clinic, $israeli),
        ]);
        $this->israeliMovement($expense, $israeli, false);
    }

    public function moveToHeldCash(int $settlementId): void
    {
        DB::transaction(function () use ($settlementId): void {
            $settlement = EmployeeSalarySettlement::query()->lockForUpdate()->findOrFail($settlementId);
            $expense = FinanceTransaction::query()->where('employee_salary_settlement_id', $settlementId)->lockForUpdate()->firstOrFail();
            if ($expense->cash_source === 'withdrawn_cash') {
                return;
            }
            if ($settlement->status !== 'confirmed' || $expense->reversal()->exists() || $expense->cash_source !== 'current_cashier') {
                throw ValidationException::withMessages(['settlement' => 'Only active current-drawer salary payments can be moved.']);
            }
            $manager = app(CashboxManager::class);
            $day = $manager->dayFor(today()->toDateString());
            $day->newQuery()->whereKey($day->id)->lockForUpdate()->firstOrFail();
            $drawer = $expense->cashboxTransaction;
            if ($drawer) {
                $drawerDay = $drawer->day()->lockForUpdate()->firstOrFail();
                if ($drawerDay->status === 'closed') {
                    throw ValidationException::withMessages(['settlement' => 'The original drawer day is closed; review its reconciliation before correcting.']);
                }
            }
            if (Money::minorUnits($expense->clinic_cash_gel) > Money::minorUnits($manager->availableCashForOpening($day)['GEL'])) {
                throw ValidationException::withMessages(['settlement' => __('employees.salary.insufficient_cash')]);
            }
            // Reclassification only: keep the settlement, expense and Israeli leg intact.
            FinanceTransaction::query()->whereKey($expense->id)->update(['cash_source' => 'withdrawn_cash']);
            $manager->syncFinanceTransaction($expense->fresh());
            DB::afterCommit(fn () => \Illuminate\Support\Facades\Log::info('Technician salary moved to held clinic cash', [
                'settlement_id' => $settlementId, 'finance_transaction_id' => $expense->id,
                'clinic_cash_gel' => $expense->clinic_cash_gel, 'removed_drawer_entry_id' => $drawer?->id,
            ]));
        });
    }

    public function reverse(EmployeeSalarySettlement $settlement): void
    {
        $expense = FinanceTransaction::query()->where('employee_salary_settlement_id', $settlement->id)->lockForUpdate()->first();
        if (! $expense || $expense->reversal()->exists()) {
            return;
        }
        $refund = app(FinanceManager::class)->create([
            'reversal_of_finance_transaction_id' => $expense->id,
            'type' => 'income', 'transaction_date' => now(), 'category' => 'lab_salary',
            'description' => 'Reversal: '.$expense->description,
            'amount' => $expense->amount, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => $expense->cash_source,
            'clinic_cash_gel' => $expense->clinic_cash_gel, 'israeli_cash_gel' => $expense->israeli_cash_gel, 'note' => $expense->note,
        ]);
        $this->israeliMovement($refund, Money::minorUnits($expense->israeli_cash_gel), true);
    }

    private function israeliMovement(FinanceTransaction $finance, int $amount, bool $refund): void
    {
        if ($amount === 0) {
            return;
        }
        PartnerFinanceTransaction::create([
            'finance_transaction_id' => $finance->id, 'type' => PartnerFinanceTransaction::TYPE_SALARY_CASH,
            'source' => 'israeli', 'transacted_at' => $finance->transaction_date, 'category' => 'lab_salary',
            'from_account' => $refund ? null : 'cash', 'to_account' => $refund ? 'cash' : null,
            'amount' => $amount / 100, 'currency' => 'GEL', 'notes' => $finance->description,
            'recipient' => $finance->employeeSalarySettlement?->employee?->full_name,
        ]);
    }

    private function description(int $clinic, int $israeli): string
    {
        return number_format($clinic / 100, 2).' Clinic / '.number_format($israeli / 100, 2).' Israeli GEL';
    }
}
