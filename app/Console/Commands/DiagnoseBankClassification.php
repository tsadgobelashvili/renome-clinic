<?php

namespace App\Console\Commands;

use App\Services\Bank\BankClassificationDiagnostic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DiagnoseBankClassification extends Command
{
    protected $signature = 'bank:diagnose-classification {--transaction=*} {--amount=*} {--date=}';

    protected $description = 'Read-only trace of Bank IDs, rules, shared defaults and actual Finance display/analytics';

    public function handle(BankClassificationDiagnostic $diagnostic): int
    {
        validator(['ids' => $this->option('transaction'), 'amounts' => $this->option('amount'), 'date' => $this->option('date')], [
            'ids.*' => 'integer|min:1', 'amounts.*' => 'numeric|min:0', 'date' => 'nullable|date_format:Y-m-d',
        ])->validate();
        $result = DB::transaction(function () use ($diagnostic) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $diagnostic->trace($this->option('transaction'), $this->option('amount'), $this->option('date'));
        });
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
