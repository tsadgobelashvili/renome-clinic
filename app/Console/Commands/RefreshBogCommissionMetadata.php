<?php

namespace App\Console\Commands;

use App\Models\BankTransaction;
use App\Services\Bank\BogCommission;
use Illuminate\Console\Command;

class RefreshBogCommissionMetadata extends Command
{
    protected $signature = 'bank:refresh-commission-metadata {--dry-run}';

    protected $description = 'Fill missing BOG gross/commission metadata from explicit original descriptions; never change amounts or classifications';

    public function handle(): int
    {
        $count = 0;
        BankTransaction::where('bank', 'BOG')->where('direction', 'inflow')
            ->where(fn ($q) => $q->whereNull('gross_amount')->orWhere('bank_fee', 0))
            ->select(['id', 'bank', 'direction', 'currency', 'description', 'bank_fee', 'gross_amount'])
            ->chunkById(250, function ($rows) use (&$count): void {
                foreach ($rows as $row) {
                    $metadata = BogCommission::metadata($row->getAttributes());
                    if ($metadata === []) {
                        continue;
                    }
                    $count++;
                    if (! $this->option('dry-run')) {
                        // Re-check original metadata so a concurrent edit cannot be overwritten.
                        BankTransaction::whereKey($row->id)->where('bank_fee', $row->bank_fee)
                            ->where('gross_amount', $row->gross_amount)->update($metadata);
                    }
                }
            });
        $this->info(($this->option('dry-run') ? 'Would enrich' : 'Enriched')." {$count} BOG rows. Raw rows, amounts, direction, categories and payment records are untouched.");

        return self::SUCCESS;
    }
}
