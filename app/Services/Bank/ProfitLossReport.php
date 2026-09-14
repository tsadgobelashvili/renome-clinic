<?php

namespace App\Services\Bank;

use App\Services\Finance\AccountingLedger;
use Illuminate\Support\Collection;

class ProfitLossReport
{
    public function totals(?string $from = null, ?string $until = null, string $source = 'all', string $currency = ''): Collection
    {
        validator(compact('currency'), ['currency' => 'nullable|regex:/^[A-Z]{3}$/'])->validate();

        return app(AccountingLedger::class)->pnlTotals($from, $until, $source, $currency);
    }
}
