<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Models\FinanceOpeningBalance;
use App\Services\Finance\OpeningBalanceService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use Livewire\WithPagination;

class FinanceOpeningBalances extends Page
{
    use AuthorizesPageAccess;
    use WithPagination;

    protected string $view = 'filament.pages.finance-opening-balances';

    protected static bool $shouldRegisterNavigation = false;

    public function getTitle(): string
    {
        return __('finance-overview.opening_balances');
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('create')->label(__('finance-overview.add_opening'))->visible(fn () => static::canAccess())->modalDescription(__('finance-overview.opening_help'))->schema([
            Select::make('source')->label(__('bank.source'))->options(['cash' => __('finance-overview.cash'), 'bank' => __('finance-overview.bank')])->default('cash')->required()->live(),
            TextInput::make('bank')->label(__('finance-overview.bank'))->default('BOG')->maxLength(20)->required(fn (Get $get) => $get('source') === 'bank')->visible(fn (Get $get) => $get('source') === 'bank'),
            TextInput::make('account_identifier')->label(__('bank.account_identifier'))->maxLength(255)->required(fn (Get $get) => $get('source') === 'bank')->visible(fn (Get $get) => $get('source') === 'bank'),
            TextInput::make('currency')->label(__('bank.currency'))->default('GEL')->required()->minLength(3)->maxLength(3),
            TextInput::make('amount')->label(__('bank.amount'))->numeric()->step(0.01)->required(),
            DatePicker::make('effective_date')->label(__('finance-overview.effective_date'))->required()->displayFormat('d.m.Y')
                ->default(fn () => app(OpeningBalanceService::class)->accountingStartDate())
                ->helperText(__('finance-overview.opening_date_help')),
            Textarea::make('note')->label(__('finance-overview.note'))->maxLength(2000)->rows(2),
        ])->action(function (array $data, OpeningBalanceService $service): void {
            abort_unless(static::canAccess(), 403);
            try {
                $service->create($data, auth()->user());
            } catch (ValidationException $exception) {
                $path = $this->getMountedActionSchema()->getStatePath();
                throw ValidationException::withMessages(collect($exception->errors())
                    ->mapWithKeys(fn (array $messages, string $field): array => [$path.'.'.$field => $messages])->all());
            }
            $this->resetPage();
            Notification::make()->title(__('finance-overview.opening_saved'))->success()->send();
        })];
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return ['openings' => FinanceOpeningBalance::orderByDesc('effective_date')->latest('id')->paginate(25)];
    }
}
