<?php

namespace App\Filament\Resources\TreatmentCases\Schemas;

use App\Models\TreatmentCase;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TreatmentCaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('დასახელება')
                    ->maxLength(255)
                    ->required(),

                Select::make('category')
                    ->label('კატეგორია')
                    ->options(fn (): array => TreatmentCase::categoryOptions())
                    ->native(false)
                    ->required(),

                TextInput::make('default_price')
                    ->label('ფასი')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->suffix('₾'),

                Toggle::make('is_active')
                    ->label('აქტიურია')
                    ->default(true),
            ]);
    }
}
