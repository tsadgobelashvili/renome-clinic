<?php

namespace App\Filament\Pages;

use App\Services\Bank\ProfitLossReport;
use Filament\Pages\Page;

class ProfitLoss extends Page
{
    protected string $view = 'filament.pages.profit-loss';

    protected static ?int $navigationSort = 34;

    protected static bool $shouldRegisterNavigation = false;

    public string $dateFrom = '';

    public string $dateUntil = '';

    public string $period = '1m';

    public string $moneySource = 'all';

    public string $currency = '';

    public static function canAccess(): bool
    {
        return Bank::canAccess();
    }

    public static function getNavigationGroup(): ?string
    {
        return Bank::getNavigationGroup();
    }

    public static function getNavigationLabel(): string
    {
        return __('bank-accounting.pnl');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function mount(): void
    {
        $this->updatedPeriod();
    }

    public function updatedPeriod(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->validateOnly('period', ['period' => 'in:14d,1m,3m,6m,1y,all,custom']);
        if ($this->period === 'custom') {
            return;
        }
        $this->dateUntil = $this->period === 'all' ? '' : today()->toDateString();
        $this->dateFrom = match ($this->period) {
            '14d' => today()->subDays(13)->toDateString(),
            '1m' => today()->subMonthNoOverflow()->toDateString(),
            '3m' => today()->subMonthsNoOverflow(3)->toDateString(),
            '6m' => today()->subMonthsNoOverflow(6)->toDateString(),
            '1y' => today()->subYearNoOverflow()->toDateString(),
            default => '',
        };
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['dateFrom', 'dateUntil'], true)) {
            $this->period = 'custom';
        }
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $validator = validator($this->only(['dateFrom', 'dateUntil', 'moneySource', 'currency']), [
            'dateFrom' => 'nullable|date_format:Y-m-d', 'dateUntil' => 'nullable|date_format:Y-m-d|after_or_equal:dateFrom',
            'moneySource' => 'in:all,cash,bank', 'currency' => 'nullable|regex:/^[A-Z]{3}$/',
        ]);
        // A single SQL aggregate supplies all cards and currency options. No row hydration or per-card queries.
        $totals = $validator->fails() ? collect() : app(ProfitLossReport::class)->totals($this->dateFrom, $this->dateUntil, $this->moneySource);

        return ['totals' => $this->currency ? $totals->where('currency', $this->currency) : $totals,
            'currencies' => $totals->pluck('currency')->merge(['GEL', 'USD', $this->currency])->filter()->unique()->sort(),
            'dateError' => $validator->fails() ? $validator->errors()->first() : null];
    }
}
