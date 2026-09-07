<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetTestData extends Command
{
    protected $signature = 'renome:reset-test-data';

    protected $description = 'Permanently delete Renome operational/test data while preserving configuration';

    /**
     * Explicit child-first allowlist. Configuration/catalog tables must never be
     * added here merely because they reference an operational table.
     *
     * @var list<string>
     */
    private const OPERATIONAL_TABLES = [
        'lab_salary_settlement_items',
        'lab_salary_settlements',
        'lab_work_items',
        'lab_main_works',
        'lab_additional_works',
        'lab_cases',
        'owner_salary_shares',
        'salary_settlement_items',
        'salary_settlements',
        'cashbox_transactions',
        'cash_transfers',
        'cashbox_days',
        'payment_audits',
        'payment_splits',
        'payments',
        'product_sale_items',
        'product_sales',
        'finance_transactions',
        'partner_patient_payments',
        'partner_finance_transactions',
        'direct_expenses',
        'visit_treatment_cases',
        'treatment_estimate_items',
        'treatment_estimate_stages',
        'treatment_estimate_options',
        'treatment_estimates',
        'patient_doctor',
        'visits',
        'purchase_items',
        'purchases',
        'patients',
    ];

    public function handle(): int
    {
        if (! $this->confirm('This will permanently delete all operational/test data. Continue?')) {
            $this->components->info('Reset cancelled. No records were deleted.');

            return self::SUCCESS;
        }

        $deleted = DB::transaction(function (): array {
            $counts = [];

            foreach (self::OPERATIONAL_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $counts[$table] = DB::table($table)->delete();
            }

            return $counts;
        });

        $this->newLine();
        $this->components->info('Operational/test data reset completed.');
        $this->table(
            ['Table', 'Deleted records'],
            collect($deleted)->map(fn (int $count, string $table): array => [$table, $count])->values()->all(),
        );
        $this->line('Total deleted: '.array_sum($deleted));

        return self::SUCCESS;
    }
}
