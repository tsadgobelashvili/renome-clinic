<?php

namespace App\Console\Commands;

use App\Services\Bank\BankClassificationService;
use Illuminate\Console\Command;

class ClassifyBankTransactions extends Command
{
    protected $signature = 'bank:classify-uncategorized';

    protected $description = 'Apply safe bank defaults and active rules without changing manual categories or raw movements';

    public function handle(BankClassificationService $classifier): int
    {
        $this->info($classifier->applyToUncategorized().' transactions categorized.');

        return self::SUCCESS;
    }
}
