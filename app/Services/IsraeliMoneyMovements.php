<?php

namespace App\Services;

use App\Models\PartnerFinanceTransaction;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/** Read-only reporting for Israeli Finance; never used by Main Finance. */
class IsraeliMoneyMovements
{
    public const LABELS = [
        'bank_deposit' => 'ანგარიშზე შეტანა',
        'exchange' => 'გაცვლა',
        'owner_withdrawal' => 'მფლობელის გატანა',
        'other_transfer' => 'სხვა გადატანა',
        'transfer' => 'ტრანსფერი',
    ];

    private const KEY_SQL = "CASE WHEN type = 'owner_withdrawal' THEN 'owner_withdrawal'
        WHEN category = 'bank_deposit' THEN 'bank_deposit'
        WHEN category = 'other_transfer' THEN 'other_transfer' ELSE 'transfer' END";

    public function query(?string $from, ?string $until, ?string $key = null): Builder
    {
        $query = $this->periodQuery($from, $until)->whereIn('type', [
            PartnerFinanceTransaction::TYPE_TRANSFER, PartnerFinanceTransaction::TYPE_EXCHANGE,
            PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL,
        ]);
        if ($key !== null) {
            abort_unless(isset(self::LABELS[$key]), 404);
            if ($key === 'exchange') {
                $query->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE);
            } else {
                $query->where('type', '!=', PartnerFinanceTransaction::TYPE_EXCHANGE)
                    ->whereRaw('('.self::KEY_SQL.') = ?', [$key]);
            }
        }

        return $query;
    }

    public function totals(?string $from, ?string $until): array
    {
        $rows = collect(self::LABELS)->mapWithKeys(fn ($label, $key) => [$key => [
            'key' => $key, 'label' => $label, 'count' => 0, 'totals' => ['GEL' => 0.0, 'USD' => 0.0], 'pairs' => [],
        ]])->all();
        foreach ($this->query($from, $until)->where('type', '!=', PartnerFinanceTransaction::TYPE_EXCHANGE)
            ->selectRaw(self::KEY_SQL.' AS movement_key, currency, SUM(amount) AS total, COUNT(*) AS row_count')
            ->groupByRaw(self::KEY_SQL.', currency')->get() as $sum) {
            $rows[$sum->movement_key]['totals'][$sum->currency] = round((float) $sum->total, 2);
            $rows[$sum->movement_key]['count'] += (int) $sum->row_count;
        }
        foreach ($this->query($from, $until, 'exchange')
            ->selectRaw('from_currency, to_currency, SUM(from_amount) AS source_total, SUM(to_amount) AS destination_total, COUNT(*) AS row_count')
            ->groupBy('from_currency', 'to_currency')->orderBy('from_currency')->orderBy('to_currency')->get() as $sum) {
            $rows['exchange']['pairs'][] = ['from_currency' => $sum->from_currency, 'to_currency' => $sum->to_currency,
                'from_amount' => round((float) $sum->source_total, 2), 'to_amount' => round((float) $sum->destination_total, 2)];
            $rows['exchange']['count'] += (int) $sum->row_count;
        }
        foreach ($rows as &$row) {
            $row['display'] = $row['pairs'] !== []
                ? implode(' · ', array_map(fn ($pair) => Currency::format($pair['from_amount'], $pair['from_currency']).' → '.Currency::format($pair['to_amount'], $pair['to_currency']), $row['pairs']))
                : Currency::format($row['totals']['GEL'], 'GEL').' · '.Currency::format($row['totals']['USD'], 'USD');
        }

        return $rows;
    }

    /** Preserve the existing expense-card funding calculation without loading its history. */
    public function expenseFunding(?string $from, ?string $until, array $expenses): array
    {
        $operations = $this->periodQuery($from, $until)->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)->where('currency', 'GEL')
            ->selectRaw('transacted_at, created_by, SUM(amount) AS funded_gel')->groupBy('transacted_at', 'created_by');
        $linked = $this->query($from, $until, 'exchange')->where('from_currency', 'USD')->where('to_currency', 'GEL')
            ->joinSub($operations, 'operation_expenses', fn ($join) => $join
                ->on('operation_expenses.transacted_at', '=', 'partner_finance_transactions.transacted_at')
                ->where(fn ($on) => $on->whereColumn('operation_expenses.created_by', 'partner_finance_transactions.created_by')
                    ->orWhere(fn ($bothNull) => $bothNull->whereNull('operation_expenses.created_by')->whereNull('partner_finance_transactions.created_by'))))
            ->selectRaw('SUM(from_amount) AS exchanged_usd, SUM(operation_expenses.funded_gel) AS funded_gel')->first();

        return ['direct_gel' => round(max((float) $expenses['GEL'] - (float) $linked->funded_gel, 0), 2),
            'exchanged_usd' => round((float) $linked->exchanged_usd, 2), 'funded_gel' => round((float) $linked->funded_gel, 2)];
    }

    private function periodQuery(?string $from, ?string $until): Builder
    {
        validator(['from' => $from, 'until' => $until], ['from' => 'nullable|date_format:Y-m-d', 'until' => 'nullable|date_format:Y-m-d'])->validate();

        // transacted_at is a local datetime (no timezone column), written in the
        // application timezone. An exclusive next-day bound includes the entire end date.
        return PartnerFinanceTransaction::query()->israeli()
            ->when($from, fn ($query) => $query->where('partner_finance_transactions.transacted_at', '>=', CarbonImmutable::parse($from, config('app.timezone'))->startOfDay()))
            ->when($until, fn ($query) => $query->where('partner_finance_transactions.transacted_at', '<', CarbonImmutable::parse($until, config('app.timezone'))->addDay()->startOfDay()));
    }
}
