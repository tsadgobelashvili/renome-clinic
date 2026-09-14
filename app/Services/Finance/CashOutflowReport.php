<?php

namespace App\Services\Finance;

use App\Support\CashboxManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Physical cash legs, not P&L postings. Finance mirrors are read through Cashier. */
class CashOutflowReport
{
    public function entries(?string $from, ?string $until, string $source = 'all'): Builder
    {
        validator(compact('from', 'until', 'source'), ['from' => 'nullable|date_format:Y-m-d', 'until' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'source' => 'in:all,clinic,israeli'])->validate();
        $before = $until ? CarbonImmutable::parse($until)->addDay() : today()->addDay();
        $cashier = app(CashboxManager::class)->physicalCashQuery($before, $from)->toBase()
            ->whereIn('type', ['expense', 'cash_withdrawal', 'cash_transfer_out'])
            ->selectRaw("'cashbox:' || CAST(id AS VARCHAR) AS entry_key, transaction_date AS entry_date, 'clinic' AS business_source,
                type AS origin, description, amount, currency, CASE WHEN type = 'expense' THEN 'expenses' ELSE 'other' END AS group_key,
                CASE WHEN type = 'expense' THEN 0 ELSE 1 END AS is_transfer");
        $partner = $this->partnerLeg('outflow', $from, $until);
        // Legacy/held-cash Finance expenses can have no Cashier mirror. Do not
        // repeat allocated salaries or a linked Israeli cash posting.
        $finance = DB::table('finance_transactions as f')->where('f.type', 'expense')->where('f.payment_method', 'cash')
            ->whereNull('f.clinic_cash_gel')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cashbox_transactions as c')->whereColumn('c.finance_transaction_id', 'f.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('partner_finance_transactions as p')->whereColumn('p.finance_transaction_id', 'f.id')->where('p.from_account', 'cash'))
            ->when($from, fn ($q) => $q->where('f.transaction_date', '>=', $from))->where('f.transaction_date', '<', $before)
            ->selectRaw("'finance-cash:' || CAST(f.id AS VARCHAR) AS entry_key, f.transaction_date AS entry_date,
                CASE WHEN f.cash_source IN ('current_cashier','withdrawn_cash') THEN 'clinic' WHEN f.cash_source = 'israeli' THEN 'israeli' ELSE NULL END AS business_source,
                'expense' AS origin, COALESCE(f.description,f.note) AS description, f.amount, f.currency, 'expenses' AS group_key, 0 AS is_transfer");

        return DB::query()->fromSub($cashier->unionAll($partner)->unionAll($finance), 'cash_outflow')
            ->when($source !== 'all', fn ($q) => $q->where('business_source', $source));
    }

    /** Mirrors the extra cash legs consumed by FinanceUsdUsageService::cashBalances. */
    private function partnerLeg(string $direction, ?string $from, ?string $until): Builder
    {
        $side = $direction === 'outflow' ? 'from' : 'to';
        $q = DB::table('partner_finance_transactions')->where($side.'_account', 'cash')
            ->where(function ($q) use ($direction) {
                $q->whereIn('type', ['currency_exchange', 'transfer']);
                if ($direction === 'outflow') {
                    $q->orWhere('type', 'owner_withdrawal')
                        ->orWhere(fn ($q) => $q->where('source', 'israeli')->where('type', 'expense'));
                }
                $q->orWhere(fn ($q) => $q->where('source', 'israeli')->where('type', 'salary_cash'));
            });

        return $q->when($from, fn ($q) => $q->where('transacted_at', '>=', $from))
            ->when($until, fn ($q) => $q->where('transacted_at', '<', CarbonImmutable::parse($until)->addDay()->toDateString()))
            ->selectRaw("'partner-{$direction}:' || CAST(id AS VARCHAR) AS entry_key, transacted_at AS entry_date,
                CASE WHEN source IN ('clinic','israeli') THEN source ELSE NULL END AS business_source, type AS origin, notes AS description,
                CASE WHEN type = 'currency_exchange' THEN {$side}_amount ELSE amount END AS amount,
                CASE WHEN type = 'currency_exchange' THEN {$side}_currency WHEN type = 'salary_cash' THEN 'GEL' ELSE currency END AS currency,
                CASE WHEN type IN ('expense','salary_cash') THEN 'expenses' WHEN type = 'owner_withdrawal' THEN 'owner_withdrawal'
                    WHEN type = 'currency_exchange' THEN 'currency_exchange' WHEN type = 'transfer' AND from_account = 'cash' AND to_account = 'bank' THEN 'bank_deposit' ELSE 'other' END AS group_key,
                CASE WHEN type IN ('expense','salary_cash') THEN 0 ELSE 1 END AS is_transfer");
    }

    public function groups(?string $from, ?string $until, string $source = 'all', string $currency = ''): Collection
    {
        return $this->entries($from, $until, $source)->when($currency !== '', fn ($q) => $q->where('currency', $currency))
            ->selectRaw('group_key, currency, SUM(amount) AS amount')->groupBy('group_key', 'currency')->orderBy('group_key')->get();
    }

    public function totals(?string $from, ?string $until, string $source = 'all'): Collection
    {
        return $this->entries($from, $until, $source)->selectRaw('currency, SUM(amount) AS amount')->groupBy('currency')->get()->keyBy('currency');
    }

    /** Full balance history, independent of performance dates; opening is never inferred from revenue. */
    public function equation(array $cash, string $source): array
    {
        $cutover = app(CashboxManager::class)->cashCutoverDate();
        $legs = [];
        foreach (['inflow', 'outflow'] as $direction) {
            $legs[$direction] = DB::query()->fromSub($this->partnerLeg($direction, null, null), 'legs')
                ->whereIn('business_source', $source === 'all' ? ['clinic', 'israeli'] : [$source])
                ->when($cutover, fn ($q) => $q->where(fn ($q) => $q->where('business_source', '!=', 'clinic')->orWhere('entry_date', '>=', $cutover)))
                ->selectRaw('currency, SUM(amount) AS amount')->groupBy('currency')->get()->keyBy('currency');
        }
        $receipts = $source === 'clinic' ? collect() : DB::table('partner_patient_payments')->where('payment_method', 'cash')
            ->selectRaw('currency, SUM(amount) AS amount')->groupBy('currency')->get()->keyBy('currency');
        foreach ($cash as $currency => &$row) {
            $row['opening'] = $source === 'israeli' ? 0.0 : $row['opening'];
            $row['received'] = round(($source === 'israeli' ? 0 : $row['received']) + (float) ($receipts->get($currency)?->amount ?? 0) + (float) ($legs['inflow']->get($currency)?->amount ?? 0), 2);
            $row['spent'] = round(($source === 'israeli' ? 0 : $row['spent']) + (float) ($legs['outflow']->get($currency)?->amount ?? 0), 2);
        }
        unset($row);

        return $cash;
    }
}
