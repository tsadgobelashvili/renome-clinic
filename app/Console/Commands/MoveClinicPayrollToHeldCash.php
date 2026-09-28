<?php
namespace App\Console\Commands;

use App\Models\FinanceTransaction;
use App\Support\CashboxManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MoveClinicPayrollToHeldCash extends Command
{
    protected $signature = 'payroll:move-to-held-cash {financeId : Exact finance transaction ID} {--apply : Apply the correction}';
    protected $description = 'Preview or move one clinic payroll expense from the open drawer to held cash';

    public function handle(): int
    {
        return DB::transaction(function (): int {
            $expense = FinanceTransaction::query()->lockForUpdate()->findOrFail($this->argument('financeId'));
            if ($expense->type !== 'expense' || $expense->payment_method !== 'cash'
                || (! $expense->payroll_entry_id && ! $expense->salary_settlement_id)
                || $expense->clinic_cash_gel !== null || $expense->reversal()->exists()
                || ! in_array($expense->cash_source, ['current_cashier', 'withdrawn_cash'], true)) {
                throw ValidationException::withMessages(['expense' => 'Only an active clinic payroll cash expense can be moved.']);
            }
            $this->table(['Finance ID', 'Description', 'Amount', 'Currency', 'Date', 'Source'], [[
                $expense->id, $expense->description, $expense->amount, $expense->currency,
                $expense->transaction_date->toDateString(), $expense->cash_source,
            ]]);
            if (! $this->option('apply')) {
                $this->info('Preview only. No records changed.');
                return self::SUCCESS;
            }
            if ($expense->cash_source === 'withdrawn_cash') {
                $this->info('Already corrected. No changes.');
                return self::SUCCESS;
            }
            $drawer = $expense->cashboxTransaction()->lockForUpdate()->first();
            if ($drawer && $drawer->day()->lockForUpdate()->firstOrFail()->status === 'closed') {
                throw ValidationException::withMessages(['expense' => 'The original cashier day is closed. Reconciliation must be reviewed first.']);
            }
            // Narrow correction bypasses the general immutability guard; salary and amount stay intact.
            FinanceTransaction::whereKey($expense->id)->update(['cash_source' => 'withdrawn_cash']);
            app(CashboxManager::class)->syncFinanceTransaction($expense->fresh());
            DB::afterCommit(fn () => Log::info('Clinic payroll moved to held cash', [
                'finance_transaction_id' => $expense->id, 'amount' => $expense->amount,
                'old_source' => 'current_cashier', 'new_source' => 'withdrawn_cash',
                'removed_drawer_entry_id' => $drawer?->id,
            ]));
            $this->info('CORRECTION_OK: same salary and expense, no duplicate payment.');
            return self::SUCCESS;
        });
    }
}
