<?php

namespace App\Console\Commands;

use App\Services\PurchaseAnalysisDiagnostics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnosePurchaseAnalysis extends Command
{
    protected $signature = 'purchases:analysis-diagnose {--from=} {--until=}';

    protected $description = 'Read-only Purchase Analysis date, eligibility and mapping counts (PostgreSQL/SQLite)';

    public function handle(PurchaseAnalysisDiagnostics $diagnostics): int
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();
        if (! in_array($driver, ['pgsql', 'sqlite'], true) || $connection->transactionLevel() !== 0) {
            $this->error('Run on PostgreSQL or SQLite outside an existing transaction. No queries were executed.');

            return self::FAILURE;
        }
        $queryOnly = $driver === 'sqlite' ? (int) $connection->selectOne('PRAGMA query_only')->query_only : null;
        try {
            if ($driver === 'sqlite') {
                $connection->statement('PRAGMA query_only = ON');
            }
            $connection->beginTransaction();
            if ($driver === 'pgsql') {
                $connection->statement('SET TRANSACTION READ ONLY');
                $connection->statement("SET LOCAL statement_timeout = '15s'");
            }
            $snapshot = $diagnostics->snapshot($this->option('from') ?: today()->subMonthNoOverflow()->addDay()->toDateString(), $this->option('until') ?: today()->toDateString());
            $this->line(json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            if ($driver === 'sqlite' && ! $queryOnly) {
                $connection->statement('PRAGMA query_only = OFF');
            }
        }

        return self::SUCCESS;
    }
}
