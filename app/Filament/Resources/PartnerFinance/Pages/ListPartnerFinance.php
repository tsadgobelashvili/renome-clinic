<?php

namespace App\Filament\Resources\PartnerFinance\Pages;

use App\Enums\PartnerAccount;
use App\Filament\Resources\PartnerFinance\PartnerFinanceResource;
use App\Models\PartnerFinanceTransaction;
use App\Support\Currency;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListPartnerFinance extends ListRecords
{
    protected static string $resource = PartnerFinanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addExpense')
                ->label('ხარჯის დამატება')
                ->color('danger')
                ->modalHeading('პარტნიორის ხარჯი')
                ->modalSubmitActionLabel('დამატება')
                ->schema([
                    DatePicker::make('transacted_at')->label('თარიღი')->default(today())->displayFormat('d.m.Y')->required(),
                    Select::make('category')->label('კატეგორია')->options(PartnerFinanceTransaction::EXPENSE_CATEGORIES)->native(false)->required(),
                    TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->step(0.01)
                        ->suffix(fn (Get $get): string => Currency::symbol($get('currency') ?: Currency::DEFAULT))->required(),
                    Select::make('currency')->label('ვალუტა')->options(Currency::OPTIONS)->default(Currency::DEFAULT)->live()->native(false)->required(),
                    Select::make('from_account')->label('გადახდის წყარო / ანგარიში')->options(PartnerAccount::options())->native(false)->required(),
                    Textarea::make('notes')->label('შენიშვნა')->rows(2)->columnSpanFull(),
                ])->action(fn (array $data) => PartnerFinanceTransaction::create([
                    ...$data,
                    'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
                ])),

            Action::make('transfer')
                ->label('გადატანა')
                ->color('gray')
                ->modalHeading('თანხის გადატანა')
                ->modalSubmitActionLabel('გადატანა')
                ->schema([
                    DatePicker::make('transacted_at')->label('თარიღი')->default(today())->displayFormat('d.m.Y')->required(),
                    Select::make('from_account')->label('ანგარიშიდან')->options(PartnerAccount::options())->native(false)->required(),
                    Select::make('to_account')->label('ანგარიშზე')->options(PartnerAccount::options())->native(false)->required(),
                    TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->step(0.01)
                        ->suffix(fn (Get $get): string => Currency::symbol($get('currency') ?: Currency::DEFAULT))->required(),
                    Select::make('currency')->label('ვალუტა')->options(Currency::OPTIONS)->default(Currency::DEFAULT)->live()->native(false)->required(),
                    Textarea::make('notes')->label('შენიშვნა')->rows(2)->columnSpanFull(),
                ])->action(fn (array $data) => PartnerFinanceTransaction::create([
                    ...$data,
                    'type' => PartnerFinanceTransaction::TYPE_TRANSFER,
                ])),

            Action::make('currencyExchange')
                ->label('ვალუტის გაცვლა')
                ->color('warning')
                ->modalHeading('ვალუტის გაცვლა')
                ->modalSubmitActionLabel('დაფიქსირება')
                ->schema([
                    DatePicker::make('transacted_at')->label('თარიღი')->default(today())->displayFormat('d.m.Y')->required(),
                    Select::make('from_account')->label('ანგარიშიდან')->options(PartnerAccount::options())->native(false)->required(),
                    Select::make('from_currency')->label('გასაცემი ვალუტა')->options(Currency::OPTIONS)->default('USD')->live()->native(false)->required(),
                    TextInput::make('from_amount')->label('გასაცემი თანხა')->numeric()->minValue(0.01)->step(0.01)
                        ->suffix(fn (Get $get): string => Currency::symbol($get('from_currency') ?: 'USD'))->required(),
                    Select::make('to_account')->label('ანგარიშზე')->options(PartnerAccount::options())->native(false)->required(),
                    Select::make('to_currency')->label('მისაღები ვალუტა')->options(Currency::OPTIONS)->default('GEL')->live()->native(false)->required(),
                    TextInput::make('to_amount')->label('მიღებული თანხა')->numeric()->minValue(0.01)->step(0.01)
                        ->suffix(fn (Get $get): string => Currency::symbol($get('to_currency') ?: 'GEL'))->required(),
                    TextInput::make('exchange_rate')->label('გაცვლის კურსი')->numeric()->minValue(0.000001)->step(0.000001)->required(),
                    Textarea::make('notes')->label('შენიშვნა')->rows(2)->columnSpanFull(),
                ])->action(fn (array $data) => PartnerFinanceTransaction::create([
                    ...$data,
                    'type' => PartnerFinanceTransaction::TYPE_EXCHANGE,
                ])),
        ];
    }
}
