<?php

namespace App\Console\Commands;

use App\Models\FinanceTransaction;
use App\Services\EmployeeSalaryFunding;
use Illuminate\Console\Command;

class MoveTechnicianSalaryToHeldCash extends Command
{
    protected $signature = 'salary:move-to-held-cash {settlements* : Explicit settlement IDs} {--apply : Apply the reviewed reclassification}';

    protected $description = 'Preview or reclassify technician salaries from the current drawer to accumulated cash';

    public function handle(EmployeeSalaryFunding $funding): int
    {
        $ids = $this->argument('settlements');
        foreach ($ids as $id) {
            if (! ctype_digit((string) $id)) {
                $this->error('Settlement IDs must be positive integers.');
                return self::FAILURE;
            }
        }
        $rows = FinanceTransaction::whereIn('employee_salary_settlement_id', $ids)->get();
        if ($rows->count() !== count(array_unique($ids))) {
            $this->error('A requested settlement has no matching finance expense.');
            return self::FAILURE;
        }
        $this->table(['Settlement', 'Finance ID', 'Date', 'Clinic GEL', 'Current source'], $rows->map(fn ($row) => [
            $row->employee_salary_settlement_id, $row->id, $row->transaction_date->toDateString(), $row->clinic_cash_gel, $row->cash_source,
        ])->all());
        if (! $this->option('apply')) {
            $this->info('Preview only. No records changed.');
            return self::SUCCESS;
        }
        \Illuminate\Support\Facades\DB::transaction(function () use ($rows, $funding): void {
            foreach ($rows->sortBy('employee_salary_settlement_id') as $row) {
                $funding->moveToHeldCash($row->employee_salary_settlement_id);
            }
        });
        $this->info('Reclassified without creating another payment.');
        return self::SUCCESS;
    }
}
