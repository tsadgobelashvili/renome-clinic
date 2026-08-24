<?php

namespace App\Filament\Resources\Visits\Widgets;

use App\Models\Payment;
use App\Models\Visit;
use App\Support\Currency;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

class VisitsOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $monthEnd = now()->endOfMonth()->toDateString();

        return [
            Stat::make(
                'ამ თვეში ვიზიტები',
                Visit::query()->whereBetween('visit_date', [$monthStart, $monthEnd])->count(),
            )
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('primary')
                ->extraAttributes(['class' => 'renome-stat renome-stat--teal']),

            Stat::make(
                'ამ თვეში გადახდილი',
                $this->currencyValue($this->paymentTotals(
                    fn (Builder $query): Builder => $query->whereBetween('payment_date', [$monthStart, $monthEnd]),
                )),
            )
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->extraAttributes(['class' => 'renome-stat renome-stat--green']),

            Stat::make('სულ გადახდილი', $this->currencyValue($this->paymentTotals()))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('success')
                ->extraAttributes(['class' => 'renome-stat renome-stat--green']),

            Stat::make('სულ გადასახდელი', $this->currencyValue($this->remainingTotals()))
                ->icon(Heroicon::OutlinedExclamationCircle)
                ->color('danger')
                ->extraAttributes(['class' => 'renome-stat renome-stat--red']),
        ];
    }

    /** @return Collection<int, object{currency: string, amount: numeric-string|float|int}> */
    private function paymentTotals(?callable $scope = null): Collection
    {
        $query = Payment::query();

        if ($scope) {
            $scope($query);
        }

        return $query
            ->selectRaw('currency, SUM(amount) AS amount')
            ->groupBy('currency')
            ->get();
    }

    /** @return Collection<int, object{currency: string, amount: numeric-string|float|int}> */
    private function remainingTotals(): Collection
    {
        $paidByVisit = Payment::query()
            ->selectRaw('visit_id, currency, SUM(amount) AS paid_total')
            ->groupBy('visit_id', 'currency');

        return Visit::query()
            ->leftJoinSub($paidByVisit, 'visit_payments', function ($join): void {
                $join->on('visit_payments.visit_id', '=', 'visits.id')
                    ->on('visit_payments.currency', '=', 'visits.currency');
            })
            ->selectRaw('visits.currency, SUM(CASE WHEN (COALESCE(visits.total_price, 0) - COALESCE(visits.discount_amount, 0) - COALESCE(visit_payments.paid_total, 0)) > 0 THEN (COALESCE(visits.total_price, 0) - COALESCE(visits.discount_amount, 0) - COALESCE(visit_payments.paid_total, 0)) ELSE 0 END) AS amount')
            ->groupBy('visits.currency')
            ->get();
    }

    private function currencyValue(Collection $rows): HtmlString
    {
        $summaries = $rows->mapWithKeys(fn ($row): array => [
            $row->currency => ['amount' => (float) $row->amount],
        ])->all();

        return new HtmlString(implode('<br>', Currency::formatBreakdown($summaries, 'amount')));
    }
}
