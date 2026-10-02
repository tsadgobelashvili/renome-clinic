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
    /** Detail listing only; never used to calculate balances or P&L. */
    public function movements(string $from, string $until, string $source = 'all', string $direction = 'all'): Builder
    {
        validator(compact('from', 'until', 'source', 'direction'), [
            'from' => 'required|date_format:Y-m-d', 'until' => 'required|date_format:Y-m-d|after_or_equal:from',
            'source' => 'in:all,clinic,israeli', 'direction' => 'in:all,inflow,outflow',
        ])->validate();
        $before = CarbonImmutable::parse($until)->addDay()->toDateString();
        $internal = "(c.cash_transfer_id IS NOT NULL OR (c.type = 'cash_withdrawal' AND c.description = ?))";
        $cashier = DB::table('cashbox_transactions as c')
            ->leftJoin('finance_transactions as f', 'f.id', '=', 'c.finance_transaction_id')
            ->where('c.payment_method', 'cash')
            ->whereIn('c.type', ['patient_payment', 'product_sale', 'other_income', 'expense', 'cash_withdrawal', 'cash_transfer_in', 'cash_transfer_out'])
            // A linked transfer has two cashbox legs but moves no money out of combined cash.
            ->where(fn ($q) => $q->whereNull('c.cash_transfer_id')->orWhere('c.type', 'cash_transfer_out'))
            ->where('c.transaction_date', '>=', $from)->where('c.transaction_date', '<', $before)
            ->selectRaw("'cashbox:' || CAST(c.id AS VARCHAR) AS entry_key, c.transaction_date AS entry_date, 'clinic' AS business_source,
                CASE WHEN c.employee_advance_id IS NOT NULL THEN 'employee_advance'
                    WHEN f.category IN ('salary','lab_salary') THEN 'salary_cash' ELSE c.type END AS origin,
                c.description, c.amount, c.currency, 'other' AS group_key, 0 AS is_transfer,
                CASE WHEN {$internal} THEN 'internal_transfer'
                    WHEN c.type IN ('expense','cash_withdrawal','cash_transfer_out') THEN 'outflow' ELSE 'inflow' END AS metric,
                CASE WHEN c.cash_transfer_id IS NOT NULL THEN 'accumulated_to_current'
                    WHEN c.type = 'cash_withdrawal' AND c.description = ? THEN 'current_to_accumulated'
                    ELSE 'current_cashbox' END AS cash_source",
                [CashboxManager::CLOSING_HANDOVER_DESCRIPTION, CashboxManager::CLOSING_HANDOVER_DESCRIPTION]);

        // Read unmirrored Finance cash, including held-cash salary shares and reversals.
        // A mixed salary's Israeli share is represented by its partner cash leg.
        $finance = DB::table('finance_transactions as f')->where('f.payment_method', 'cash')
            ->whereRaw('COALESCE(f.clinic_cash_gel, f.amount) > 0')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cashbox_transactions as c')->whereColumn('c.finance_transaction_id', 'f.id'))
            ->where(fn ($q) => $q->whereNotNull('f.clinic_cash_gel')->orWhereNotExists(fn ($q) => $q->selectRaw('1')
                ->from('partner_finance_transactions as p')->whereColumn('p.finance_transaction_id', 'f.id')
                ->where(fn ($q) => $q->where('p.from_account', 'cash')->orWhere('p.to_account', 'cash'))))
            ->where('f.transaction_date', '>=', $from)->where('f.transaction_date', '<', $before)
            ->selectRaw("'finance-cash:' || CAST(f.id AS VARCHAR) AS entry_key, f.transaction_date AS entry_date,
                CASE WHEN f.cash_source IN ('current_cashier','withdrawn_cash') THEN 'clinic' WHEN f.cash_source = 'israeli' THEN 'israeli' ELSE NULL END AS business_source,
                CASE WHEN f.category IN ('salary','lab_salary') THEN 'salary_cash' WHEN f.type = 'expense' THEN 'expense' ELSE 'other_income' END AS origin,
                COALESCE(f.description,f.note) AS description, COALESCE(f.clinic_cash_gel,f.amount) AS amount, f.currency,
                'other' AS group_key, 0 AS is_transfer, CASE WHEN f.type = 'expense' THEN 'outflow' ELSE 'inflow' END AS metric,
                CASE WHEN f.cash_source = 'current_cashier' THEN 'current_cashbox' ELSE 'accumulated_cash' END AS cash_source");

        // Historical payments deliberately have no drawer posting. Prefer an existing
        // split mirror (or a legacy payment/currency mirror) when one is present.
        $historical = DB::table('payment_splits as ps')->join('payments as p', 'p.id', '=', 'ps.payment_id')
            ->where('p.is_historical', true)->whereNull('p.deleted_at')->where('ps.payment_method', 'cash')
            ->where('p.payment_date', '>=', $from)->where('p.payment_date', '<', $before)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cashbox_transactions as c')
                ->where('c.payment_method', 'cash')->where(fn ($q) => $q->whereColumn('c.payment_split_id', 'ps.id')
                    ->orWhere(fn ($q) => $q->whereNull('c.payment_split_id')->whereColumn('c.payment_id', 'p.id')->whereColumn('c.currency', 'ps.currency'))))
            ->selectRaw("'historical-split:' || CAST(ps.id AS VARCHAR) AS entry_key, p.payment_date AS entry_date, 'clinic' AS business_source,
                'patient_payment' AS origin, p.comment AS description, ps.amount, ps.currency, 'other' AS group_key, 0 AS is_transfer,
                'inflow' AS metric, 'accumulated_cash' AS cash_source");

        $cashier->unionAll($finance)->unionAll($historical);
        foreach (['inflow', 'outflow'] as $leg) {
            $partner = $this->partnerLeg($leg, $from, $until)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cashbox_transactions as c')
                    ->whereColumn('c.employee_advance_key', 'partner_finance_transactions.employee_advance_key'))
                ->selectRaw("CASE WHEN type = 'transfer' AND from_account = 'cash' AND to_account = 'cash'
                    THEN 'internal_transfer' ELSE '{$leg}' END AS metric, 'accumulated_cash' AS cash_source");
            if ($leg === 'inflow') {
                $partner->whereNot(fn ($q) => $q->where('type', 'transfer')->whereNotNull('from_account')->where('from_account', 'cash')->where('to_account', 'cash'));
            }
            $cashier->unionAll($partner);
        }
        $receipts = DB::table('partner_patient_payments')->whereNull('deleted_at')->where('payment_method', 'cash')
            ->where('paid_at', '>=', $from)->where('paid_at', '<', $before)
            ->selectRaw("'partner-payment:' || CAST(id AS VARCHAR) AS entry_key, paid_at AS entry_date, 'israeli' AS business_source,
                'partner_payment' AS origin, notes AS description, amount, currency, 'other' AS group_key, 0 AS is_transfer,
                'inflow' AS metric, 'accumulated_cash' AS cash_source");

        return DB::query()->fromSub($cashier->unionAll($receipts), 'cash_movements')
            ->when($source !== 'all', fn ($q) => $q->where('business_source', $source))
            ->when($direction !== 'all', fn ($q) => $q->where('metric', $direction));
    }

    public function entries(?string $from, ?string $until, string $source = 'all'): Builder
    {
        validator(compact('from', 'until', 'source'), ['from' => 'nullable|date_format:Y-m-d', 'until' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'source' => 'in:all,clinic,israeli'])->validate();
        $before = $until ? CarbonImmutable::parse($until)->addDay() : today()->addDay();
        $cashier = app(CashboxManager::class)->physicalCashQuery($before, $from)->toBase()
            ->whereNull('cash_transfer_id')
            ->whereIn('type', ['expense', 'cash_withdrawal', 'cash_transfer_out'])
            ->selectRaw("'cashbox:' || CAST(id AS VARCHAR) AS entry_key, transaction_date AS entry_date, 'clinic' AS business_source,
                type AS origin, description, amount, currency, CASE WHEN type = 'expense' THEN 'expenses' ELSE 'other' END AS group_key,
                CASE WHEN type = 'expense' THEN 0 ELSE 1 END AS is_transfer");
        $partner = $this->partnerLeg('outflow', $from, $until)
            ->whereNot(fn ($q) => $q->where('type', 'transfer')->where('from_account', 'cash')->whereNotNull('to_account')->where('to_account', 'cash'));
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

        // Allocated held salaries have no drawer mirror. Include only the clinic
        // portion: the Israeli portion is already represented by partnerLeg.
        $heldSalary = DB::table('finance_transactions as f')
            ->where('f.type', 'expense')->where('f.payment_method', 'cash')
            ->where('f.cash_source', 'withdrawn_cash')->where('f.clinic_cash_gel', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cashbox_transactions as c')->whereColumn('c.finance_transaction_id', 'f.id'))
            ->when($from, fn ($q) => $q->where('f.transaction_date', '>=', $from))->where('f.transaction_date', '<', $before)
            ->selectRaw("'finance-held-clinic:' || CAST(f.id AS VARCHAR) AS entry_key, f.transaction_date AS entry_date,
                'clinic' AS business_source, 'expense' AS origin, COALESCE(f.description,f.note) AS description,
                f.clinic_cash_gel AS amount, f.currency, 'expenses' AS group_key, 0 AS is_transfer");

        return DB::query()->fromSub($cashier->unionAll($partner)->unionAll($finance)->unionAll($heldSalary), 'cash_outflow')
            ->when($source !== 'all', fn ($q) => $q->where('business_source', $source));
    }

    /** Mirrors the extra cash legs consumed by FinanceUsdUsageService::cashBalances. */
    private function partnerLeg(string $direction, ?string $from, ?string $until): Builder
    {
        $side = $direction === 'outflow' ? 'from' : 'to';
        $q = DB::table('partner_finance_transactions')->where($side.'_account', 'cash')
            ->where(function ($q) use ($direction) {
                $q->whereIn('type', ['currency_exchange', 'transfer', 'employee_advance']);
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
        $today = today(config('app.timezone'))->toDateString();
        $legs = [];
        foreach (['inflow', 'outflow'] as $direction) {
            $partner = $this->partnerLeg($direction, null, $today)
                ->whereNot(fn ($q) => $q->where('type', 'transfer')->whereNotNull('from_account')->whereNotNull('to_account')->where('from_account', 'cash')->where('to_account', 'cash'));
            $legs[$direction] = DB::query()->fromSub($partner, 'legs')
                ->whereIn('business_source', $source === 'all' ? ['clinic', 'israeli'] : [$source])
                ->when($cutover, fn ($q) => $q->where(fn ($q) => $q->where('business_source', '!=', 'clinic')->orWhere('entry_date', '>=', $cutover)))
                ->selectRaw('currency, SUM(amount) AS amount')->groupBy('currency')->get()->keyBy('currency');
        }
        $receipts = $source === 'clinic' ? collect() : DB::table('partner_patient_payments')->whereNull('deleted_at')->where('payment_method', 'cash')
            ->where('paid_at', '<', today(config('app.timezone'))->addDay())
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
