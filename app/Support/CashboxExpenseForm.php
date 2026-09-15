<?php

namespace App\Support;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;

final class CashboxExpenseForm
{
    public static function schema(): array
    {
        return [
            Grid::make(['default' => 1, 'sm' => 2])->schema([
                ...ExpenseCategoryForm::schema(requireSubcategory: true),
                TextInput::make('amount')->label('თანხა')->numeric()->minValue(0.01)->required(),
                Select::make('currency')->label('ვალუტა')->options(['GEL' => 'GEL', 'USD' => 'USD'])
                    ->required()->default(Currency::DEFAULT),
                DateTimePicker::make('transaction_date')->label('თარიღი / დრო')
                    ->timezone(config('app.timezone'))->required()->default(now())->columnSpanFull(),
                Textarea::make('description')->label('აღწერა / დანიშნულება')->rows(2)->maxLength(255)->columnSpanFull(),
            ]),
        ];
    }
}
