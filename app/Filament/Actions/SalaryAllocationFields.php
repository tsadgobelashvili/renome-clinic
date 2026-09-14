<?php

namespace App\Filament\Actions;

use App\Models\Doctor;
use App\Models\PatientGroup;
use App\Services\IsraeliSalaryPayoutService;
use App\Services\NbgExchangeRate;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Illuminate\Support\Str;

class SalaryAllocationFields
{
    public static function make(\Closure $salary, ?\Closure $paid = null): array
    {
        $autoFill = function (Get $get, Set $set, Component $component, $record) use ($salary, $paid): void {
            $repeater = $component->getContainer()->getParentComponent();
            $root = $repeater->getGetCallback();
            if ($record instanceof Doctor && $root('patient_group') !== PatientGroup::ISRAEL_PARTNER_SLUG) {
                return;
            }
            self::loadRate($get, $set);
            $key = $component->getContainer()->getStatePath(false);
            $other = collect($repeater->getState())->except($key)->sum(fn ($row) => IsraeliSalaryPayoutService::equivalent($row));
            $remaining = max(0, round((float) $salary($root, $record) - ($paid ? (float) $paid($record) : 0) - $other, 2));
            $set('amount', self::remainingAmount($remaining, $get('currency') ?? 'USD', (float) $get('exchange_rate')));
        };

        return [
            Hidden::make('payout_request_key')->default(fn () => (string) Str::uuid())->required(),
            View::make('filament.resources.doctors.salary-allocation-totals')->columnSpanFull()->viewData(function (Get $get, $record) use ($salary, $paid) {
                $total = (float) $salary($get, $record);
                $allocated = round(collect($get('allocations') ?? [])->sum(fn ($row) => IsraeliSalaryPayoutService::equivalent($row)), 2);

                $alreadyPaid = $paid ? (float) $paid($record) : 0.0;

                return ['salary' => $total, 'paid' => $alreadyPaid, 'allocated' => $allocated, 'remaining' => round($total - $alreadyPaid - $allocated, 2)];
            }),
            Repeater::make('allocations')->hiddenLabel()->addActionLabel(__('salary-payout.add'))
                ->extraAttributes(['class' => 'renome-salary-allocations'])->compact()
                ->table([
                    TableColumn::make(__('employees.payroll.currency'))->width('6rem'),
                    TableColumn::make(__('salary-payout.source'))->width('8rem'),
                    TableColumn::make(__('salary-payout.amount'))->width('8rem'),
                    TableColumn::make(__('salary-payout.rate'))->width('7rem'),
                    TableColumn::make(__('salary-payout.equivalent')),
                ])
                ->addAction(fn (Action $action) => $action->link()->size('xs'))
                ->deleteAction(fn (Action $action) => $action->color('gray')->size('xs'))
                ->extraItemActions([
                    Action::make('fillRemaining')->label(__('salary-payout.fill_remaining'))->link()->size('xs')
                        ->action(function (array $arguments, Repeater $component, Get $get, $record) use ($salary, $paid) {
                            $rows = $component->getState();
                            $key = $arguments['item'];
                            if (! isset($rows[$key])) {
                                return;
                            }
                            if (($rows[$key]['currency'] ?? 'USD') === 'USD' && blank($rows[$key]['exchange_rate'] ?? null)) {
                                $rows[$key]['exchange_rate'] = self::officialRate();
                            }
                            $other = collect($rows)->except($key)->sum(fn ($row) => IsraeliSalaryPayoutService::equivalent($row));
                            $remaining = max(0, round((float) $salary($get, $record) - ($paid ? (float) $paid($record) : 0) - $other, 2));
                            $rows[$key]['amount'] = self::remainingAmount($remaining, $rows[$key]['currency'] ?? 'GEL', (float) ($rows[$key]['exchange_rate'] ?? 0));
                            $component->state($rows);
                        }),
                ])
                ->defaultItems(1)->minItems(1)->maxItems(20)->reorderable(false)->columnSpanFull()->live()
                ->schema([
                    Select::make('currency')->label(__('employees.payroll.currency'))->options(['GEL' => 'GEL', 'USD' => 'USD'])->default('USD')->required()->selectablePlaceholder(false)->live()
                        ->afterStateUpdated($autoFill),
                    Select::make('source')->label(__('salary-payout.source'))->options(['israeli' => __('salaries.israeli'), 'clinic' => __('salaries.clinic')])->default('israeli')->required()->selectablePlaceholder(false)->live(),
                    TextInput::make('amount')->label(__('salary-payout.amount'))->numeric()->minValue(0.01)->step(0.01)->required()->live(debounce: 150),
                    TextInput::make('exchange_rate')->label(__('salary-payout.rate'))->numeric()->minValue(0.000001)->step(0.000001)
                        ->visible(fn (Get $get) => $get('currency') === 'USD')->required(fn (Get $get) => $get('currency') === 'USD')->live(debounce: 150)
                        ->afterStateHydrated(function (Get $get, Set $set, $component, $record) use ($autoFill) {
                            if (blank($get('amount'))) {
                                $autoFill($get, $set, $component, $record);
                            }
                        }),
                    TextEntry::make('gel_equivalent')->label(__('salary-payout.equivalent'))
                        ->state(fn (Get $get) => Currency::format(IsraeliSalaryPayoutService::equivalent(['amount' => $get('amount'), 'currency' => $get('currency'), 'exchange_rate' => $get('exchange_rate')]), 'GEL')),
                ]),

        ];
    }

    public static function remainingAmount(float $remaining, string $currency, float $rate): ?float
    {
        if ($remaining <= 0 || ($currency === 'USD' && $rate <= 0)) {
            return null;
        }
        $rate = $currency === 'USD' ? $rate : 1;
        $amount = round($remaining / $rate, 2);
        if (round($amount * $rate, 2) > round($remaining, 2)) {
            $amount = round($amount - 0.01, 2);
        }

        return $amount > 0 ? $amount : null;
    }

    private static function loadRate(Get $get, Set $set): void
    {
        if ($get('currency') !== 'USD' || filled($get('exchange_rate'))) {
            return;
        }
        $set('exchange_rate', self::officialRate());
    }

    public static function defaultRow(float $remaining): array
    {
        $rate = self::officialRate();

        return ['currency' => 'USD', 'source' => 'israeli', 'exchange_rate' => $rate,
            'amount' => self::remainingAmount($remaining, 'USD', (float) $rate)];
    }

    private static function officialRate(): ?float
    {
        try {
            return app(NbgExchangeRate::class)->usdGel();
        } catch (\Throwable $exception) {
            report($exception);
            Notification::make()->warning()->title(__('salary-payout.rate_unavailable'))->send();

            return null;
        }
    }
}
