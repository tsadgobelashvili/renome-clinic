<?php

namespace App\Console\Commands;

use App\Services\DoctorSalaryDiagnostic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class DiagnoseDoctorSalary extends Command
{
    protected $signature = 'salary:diagnose-visit {visit : Payroll visit ID} {--reference= : Expected numeric patient reference; only a match boolean is returned}';

    protected $description = 'Read-only, redacted trace of visit classification, live salary and stored payroll snapshots';

    public function handle(DoctorSalaryDiagnostic $diagnostic): int
    {
        $visit = (string) $this->argument('visit');
        $reference = $this->option('reference');
        foreach (array_filter([$visit, $reference], fn ($value) => $value !== null) as $value) {
            if (! ctype_digit((string) $value) || strlen((string) $value) > 15 || (int) $value < 1) {
                $this->error('Use positive numeric IDs only. Do not supply names.');

                return self::INVALID;
            }
        }

        // Neither query bindings nor application exception messages belong in this report.
        $channel = config('logging.default');
        config(['logging.default' => 'null']);
        $connection = DB::connection();
        $queryLog = $connection->logging();
        $connection->disableQueryLog();
        $started = false;
        try {
            // Fail closed on unsupported engines or an existing transaction. This tool
            // is for the PostgreSQL deployment, not an unprotected read on another DB.
            if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() !== 0) {
                $this->error('Requires PostgreSQL and a new database-enforced read-only transaction.');

                return self::FAILURE;
            }
            $connection->beginTransaction();
            $started = true;
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            $connection->statement("SET LOCAL statement_timeout = '15000ms'");
            $connection->statement("SET LOCAL lock_timeout = '2000ms'");
            $readOnly = $connection->selectOne('SHOW transaction_read_only')->transaction_read_only;
            if ($readOnly !== 'on') {
                $this->error('Database did not confirm read-only protection.');

                return self::FAILURE;
            }
            $report = ['transaction_read_only' => $readOnly, ...$diagnostic->trace((int) $visit, $reference === null ? null : (int) $reference)];
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->line(json_encode(['diagnostic_failed' => true, 'exception_class' => get_class($exception)], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        } finally {
            if ($started && $connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($queryLog) {
                $connection->enableQueryLog();
            }
            config(['logging.default' => $channel]);
        }
    }
}
