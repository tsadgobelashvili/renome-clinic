<?php

namespace App\Services;

use App\Enums\PartnerAccount;
use App\Models\PartnerFinanceTransaction;
use App\Models\PartnerPatientPayment;
use App\Support\Currency;

class PartnerFinanceSummary
{
    /** @return array<string, float> */
    public function receivedTotals(?string $from = null, ?string $until = null): array
    {
        return $this->currencyTotals(
            PartnerPatientPayment::query()
                ->when($from, fn ($query) => $query->whereDate('paid_at', '>=', $from))
                ->when($until, fn ($query) => $query->whereDate('paid_at', '<=', $until))
                ->selectRaw('currency, SUM(amount) AS total')->groupBy('currency'),
        );
    }

    /** @return array<string, float> */
    public function expenseTotals(?string $from = null, ?string $until = null): array
    {
        return $this->currencyTotals(
            PartnerFinanceTransaction::query()
                ->israeli()
                ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
                ->when($from, fn ($query) => $query->whereDate('transacted_at', '>=', $from))
                ->when($until, fn ($query) => $query->whereDate('transacted_at', '<=', $until))
                ->selectRaw('currency, SUM(amount) AS total')
                ->groupBy('currency'),
        );
    }

    /** @return array<string, float> */
    public function currentCashTotals(): array
    {
        return $this->accountBalances()[PartnerAccount::Cash->value];
    }

    /** @return array<string, float> */
    public function bankDepositedTotals(): array
    {
        return $this->currencyTotals(
            PartnerFinanceTransaction::query()
                ->israeli()
                ->where('type', PartnerFinanceTransaction::TYPE_TRANSFER)
                ->where('from_account', PartnerAccount::Cash->value)
                ->where('to_account', PartnerAccount::Bank->value)
                ->selectRaw('currency, SUM(amount) AS total')
                ->groupBy('currency'),
        );
    }

    /** @return array<string, array<string, float>> */
    public function accountBalances(): array
    {
        $balances = collect(PartnerAccount::cases())->mapWithKeys(fn (PartnerAccount $account): array => [
            $account->value => collect(array_keys(Currency::OPTIONS))->mapWithKeys(
                fn (string $currency): array => [$currency => 0.0],
            )->all(),
        ])->all();

        $this->apply($balances, PartnerPatientPayment::query()
            ->selectRaw("CASE WHEN payment_method = 'cash' THEN 'cash' ELSE 'bank' END AS account, currency, SUM(amount) AS total")
            ->groupBy('account', 'currency')->get(), 1);

        $this->apply($balances, PartnerFinanceTransaction::query()
            ->israeli()
            ->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)
            ->selectRaw('from_account AS account, currency, SUM(amount) AS total')
            ->groupBy('from_account', 'currency')->get(), -1);

        $transfers = PartnerFinanceTransaction::query()->israeli()->where('type', PartnerFinanceTransaction::TYPE_TRANSFER);
        $this->apply($balances, (clone $transfers)
            ->selectRaw('from_account AS account, currency, SUM(amount) AS total')
            ->groupBy('from_account', 'currency')->get(), -1);
        $this->apply($balances, (clone $transfers)
            ->selectRaw('to_account AS account, currency, SUM(amount) AS total')
            ->groupBy('to_account', 'currency')->get(), 1);

        $this->apply($balances, PartnerFinanceTransaction::query()->israeli()
            ->where('type', PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL)
            ->selectRaw('from_account AS account, currency, SUM(amount) AS total')
            ->groupBy('from_account', 'currency')->get(), -1);

        $exchanges = PartnerFinanceTransaction::query()->israeli()->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE);
        $this->apply($balances, (clone $exchanges)
            ->selectRaw('from_account AS account, from_currency AS currency, SUM(from_amount) AS total')
            ->groupBy('from_account', 'from_currency')->get(), -1);
        $this->apply($balances, (clone $exchanges)
            ->selectRaw('to_account AS account, to_currency AS currency, SUM(to_amount) AS total')
            ->groupBy('to_account', 'to_currency')->get(), 1);

        $balances['cash']['GEL'] += (float) PartnerFinanceTransaction::israeli()->where('type', PartnerFinanceTransaction::TYPE_SALARY_CASH)->where('to_account', 'cash')->sum('amount')
                - (float) PartnerFinanceTransaction::israeli()->where('type', PartnerFinanceTransaction::TYPE_SALARY_CASH)->where('from_account', 'cash')->sum('amount');

        return $balances;
    }

    private function currencyTotals($query): array
    {
        $totals = $query->pluck('total', 'currency');

        return collect(array_keys(Currency::OPTIONS))->mapWithKeys(fn (string $currency): array => [
            $currency => round((float) ($totals[$currency] ?? 0), 2),
        ])->all();
    }

    private function apply(array &$balances, $rows, int $direction): void
    {
        foreach ($rows as $row) {
            if (! isset($balances[$row->account][$row->currency])) {
                continue;
            }

            $balances[$row->account][$row->currency] = round(
                $balances[$row->account][$row->currency] + ($direction * (float) $row->total),
                2,
            );
        }
    }
}
