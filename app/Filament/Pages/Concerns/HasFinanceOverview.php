<?php

namespace App\Filament\Pages\Concerns;

use App\Services\Finance\AccountingLedger;
use App\Services\Finance\CashOutflowReport;
use App\Services\Finance\LiquidityReport;
use App\Support\CashboxManager;
use Illuminate\Support\Facades\DB;

trait HasFinanceOverview
{
    public string $overviewCard = '';

    public string $overviewCategory = '';

    public string $overviewSubcategory = '';

    public string $moneySource = 'all';

    public string $businessSource = 'all';

    public string $overviewCurrency = '';

    public string $expenseGrouping = 'direction';

    public ?int $expenseDirectionFilter = null;

    public ?int $expenseTypeFilter = null;

    public function updatedExpenseGrouping(): void
    {
        $this->updatedMoneySource();
    }

    public function updatedExpenseDirectionFilter(): void
    {
        $this->updatedMoneySource();
    }

    public function updatedExpenseTypeFilter(): void
    {
        $this->updatedMoneySource();
    }

    public function selectOverviewCard(string $card): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless(in_array($card, ['cash', 'bank', 'revenue', 'expenses', 'profit', 'cash_outflow', ''], true), 422);
        $this->overviewCard = $this->overviewCard === $card ? '' : $card;
        $this->overviewCategory = '';
        $this->overviewSubcategory = '';
        $this->resetPage('overviewPage');
    }

    public function selectExpenseCategory(string $key): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->overviewCard === 'expenses' && preg_match('/^(\d+|review)$/', $key), 422);
        $this->overviewCategory = $this->overviewCategory === $key ? '' : $key;
        $this->overviewSubcategory = '';
        $this->resetPage('overviewPage');
    }

    public function updatedMoneySource(): void
    {
        $this->overviewCategory = '';
        $this->overviewSubcategory = '';
        $this->resetPage('overviewPage');
    }

    public function selectCashOutflowGroup(string $group): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->overviewCard === 'cash_outflow' && in_array($group, ['expenses', 'bank_deposit', 'owner_withdrawal', 'currency_exchange', 'other'], true), 422);
        $this->overviewCategory = $this->overviewCategory === $group ? '' : $group;
        $this->resetPage('overviewPage');
    }

    public function updatedOverviewCurrency(): void
    {
        $this->resetPage('overviewPage');
    }

    public function updatedBusinessSource(): void
    {
        $this->overviewCategory = '';
        $this->overviewSubcategory = '';
        $this->resetPage('overviewPage');
    }

    public function selectExpenseSubcategory(string $key): void
    {
        abort_unless(static::canAccess(), 403);
        abort_unless($this->overviewCard === 'expenses' && $this->overviewCategory !== '' && preg_match('/^(\d+|review)$/', $key), 422);
        $this->overviewSubcategory = $this->overviewSubcategory === $key ? '' : $key;
        $this->resetPage('overviewPage');
    }

    protected function overviewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $validator = validator($this->only(['dateFrom', 'dateUntil', 'moneySource', 'overviewCurrency', 'businessSource']), [
            'dateFrom' => 'required|date_format:Y-m-d', 'dateUntil' => 'required|date_format:Y-m-d|after_or_equal:dateFrom',
            'moneySource' => 'in:all,cash,bank', 'overviewCurrency' => 'nullable|regex:/^[A-Z]{3}$/',
            'businessSource' => 'in:all,clinic,israeli',
        ]);
        $liquidity = app(LiquidityReport::class)->current(in_array($this->businessSource, ['clinic', 'israeli'], true) ? $this->businessSource : 'all', $this->bogBalance);
        $ledger = app(AccountingLedger::class);
        $pnl = $validator->fails() ? collect() : $ledger->pnlTotals($this->dateFrom, $this->dateUntil, $this->moneySource, '', $this->businessSource)->keyBy('currency');
        $outflows = app(CashOutflowReport::class);
        $outflowTotals = $validator->fails() ? collect() : $outflows->totals($this->dateFrom, $this->dateUntil, $this->businessSource);
        $currencies = collect(array_keys($liquidity['totals']))->merge($pnl->keys())->merge($outflowTotals->keys())->push($this->overviewCurrency)->filter()->unique()->sort();
        $figures = [];
        foreach ($currencies as $currency) {
            $figures[$currency] = [
                ...($liquidity['totals'][$currency] ?? ['cash' => 0, 'bank' => null, 'available' => null]),
                'cash_outflow' => (float) ($outflowTotals->get($currency)?->amount ?? 0),
                'revenue' => (float) ($pnl->get($currency)?->revenue ?? 0), 'expenses' => (float) ($pnl->get($currency)?->expenses ?? 0), 'profit' => (float) ($pnl->get($currency)?->profit ?? 0),
            ];
        }
        $groups = collect();
        $subgroups = collect();
        $details = null;
        $outflowGroups = collect();
        if ($this->overviewCard === 'cash') {
            $liquidity['cash'] = $outflows->equation($liquidity['cash'], in_array($this->businessSource, ['clinic', 'israeli'], true) ? $this->businessSource : 'all');
        }
        if (! $validator->fails()) {
            $query = null;
            if (in_array($this->overviewCard, ['revenue', 'expenses'], true)) {
                if ($this->overviewCard === 'expenses') {
                    $entries = $ledger->dimensionEntries($this->dateFrom, $this->dateUntil, $this->moneySource, $this->overviewCurrency,
                        $this->businessSource, $this->expenseGrouping, $this->expenseDirectionFilter, $this->expenseTypeFilter);
                    $groups = $ledger->dimensionGroups($entries);
                    if ($this->overviewCategory !== '') {
                        $subgroups = $ledger->dimensionGroups($entries, $this->overviewCategory);
                        if ($this->overviewSubcategory !== '') {
                            $query = $entries->where('dimension_group', $this->overviewCategory)->where('dimension_subgroup', $this->overviewSubcategory);
                        }
                    }
                } else {
                    $query = $ledger->pnl($this->dateFrom, $this->dateUntil, $this->moneySource, $this->businessSource)->where('metric', 'revenue');
                }
            } elseif ($this->overviewCard === 'cash_outflow') {
                $outflowGroups = $outflows->groups($this->dateFrom, $this->dateUntil, $this->businessSource, $this->overviewCurrency);
                if ($this->overviewCategory !== '') {
                    $query = $outflows->entries($this->dateFrom, $this->dateUntil, $this->businessSource)->where('group_key', $this->overviewCategory);
                }
            } elseif ($this->overviewCard === 'bank') {
                // Full banking history belongs on the separate Bank page.
                $query = null;
            } elseif ($this->overviewCard === 'cash' && $this->businessSource !== 'israeli') {
                $ids = app(CashboxManager::class)->physicalCashQuery(from: $liquidity['cash']['GEL']['from_date'])->select('id');
                $query = DB::query()->fromSub($ledger->cashMovements(null, null)->whereIn('c.id', $ids), 'ledger');
            }
            if ($query) {
                $details = $query->when($this->overviewCurrency !== '', fn ($q) => $q->where('currency', $this->overviewCurrency))
                    ->orderByDesc('entry_date')->orderBy('entry_key')->simplePaginate(25, pageName: 'overviewPage');
            }
        }

        return ['figures' => $this->overviewCurrency ? array_intersect_key($figures, [$this->overviewCurrency => true]) : $figures,
            'overviewCurrencies' => $currencies, 'liquidity' => $liquidity, 'expenseGroups' => $groups, 'expenseSubgroups' => $subgroups, 'overviewDetails' => $details,
            'outflowGroups' => $outflowGroups,
            'dateError' => $validator->fails() ? $validator->errors()->first() : null];
    }
}
