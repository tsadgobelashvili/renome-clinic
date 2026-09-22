<?php

namespace App\Console\Commands;

use App\Services\Finance\AccountingLedger;
use App\Services\Finance\CashOutflowReport;
use App\Services\NbgExchangeRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillExchangeRates extends Command
{
    protected $signature = 'exchange-rates:backfill';

    protected $description = 'Persist missing NBG rates for dates with USD Statistics activity';

    public function handle(AccountingLedger $ledger, CashOutflowReport $outflows, NbgExchangeRate $nbg): int
    {
        // Use exactly the existing Statistics P&L and cash-out sources, not raw bank credits.
        $dates = DB::query()->fromSub($ledger->pnl(null, today()->toDateString()), 'activity')
            ->where('currency', 'USD')->selectRaw('DATE(entry_date) as rate_date')
            ->union(DB::query()->fromSub($outflows->entries(null, today()->toDateString()), 'activity')
                ->where('currency', 'USD')->selectRaw('DATE(entry_date) as rate_date'));
        $missing = DB::query()->fromSub($dates, 'dates')->whereNotNull('rate_date')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('exchange_rates')->where('currency', 'USD')->whereColumn('date', 'dates.rate_date'))
            ->orderBy('rate_date')->pluck('rate_date');
        $count = 0;
        foreach ($missing->chunk(100) as $chunk) {
            try {
                $nbg->usdGelForDates($chunk->all());
                $count += $chunk->count();
            } catch (\Throwable $exception) {
                report($exception);
                $this->error('NBG backfill failed: '.$exception->getMessage().' Previously stored rates are preserved; rerun safely.');

                return self::FAILURE;
            }
        }
        $this->info("Stored rates for {$count} missing activity dates. Existing rates unchanged.");

        return self::SUCCESS;
    }
}
