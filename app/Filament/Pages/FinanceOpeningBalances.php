<?php

namespace App\Filament\Pages;

use App\Models\FinanceOpeningBalance;
use App\Services\Finance\OpeningBalanceService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Livewire\WithPagination;

class FinanceOpeningBalances extends Page
{
    use WithPagination;

    protected string $view = 'filament.pages.finance-opening-balances';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return Finance::canAccess();
    }

    public function getTitle(): string
    {
        return __('finance-overview.opening_balances');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label(__('finance-overview.add_opening'))->modalDescription(__('finance-overview.opening_help'))->schema([
            Select::make('source')->label(__('bank.source'))->options(['cash' => __('finance-overview.cash'), 'bank' => __('finance-overview.bank')])->default('cash')->required()->live(),
            TextInput::make('bank')->label(__('finance-overview.bank'))->default('BOG')->maxLength(20)->required(fn (Get $get) => $get('source') === 'bank')->visible(fn (Get $get) => $get('source') === 'bank'),
            TextInput::make('account_identifier')->label(__('bank.account_identifier'))->maxLength(255)->required(fn (Get $get) => $get('source') === 'bank')->visible(fn (Get $get) => $get('source') === 'bank'),
            TextInput::make('currency')->label(__('bank.currency'))->default('GEL')->required()->minLength(3)->maxLength(3),
            TextInput::make('amount')->label(__('bank.amount'))->numeric()->step(0.01)->required(),
            DatePicker::make('effective_date')->label(__('finance-overview.effective_date'))->required()->displayFormat('d.m.Y'),
            Textarea::make('note')->label(__('finance-overview.note'))->maxLength(2000)->rows(2),
        ])->action(function (array $data, OpeningBalanceService $service): void {
            abort_unless(static::canAccess(), 403);
            $service->create($data, auth()->user());
        })];
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return ['openings' => FinanceOpeningBalance::orderByDesc('effective_date')->latest('id')->paginate(25)];
    }
}
