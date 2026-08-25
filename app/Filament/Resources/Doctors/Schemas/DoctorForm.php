<?php

namespace App\Filament\Resources\Doctors\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class DoctorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->label('სახელი')
                    ->required()
                    ->maxLength(100),

                TextInput::make('last_name')
                    ->label('გვარი')
                    ->required()
                    ->maxLength(100),

                TextInput::make('phone')
                    ->label('ტელეფონი')
                    ->tel()
                    ->maxLength(30),

                TextInput::make('specialty')
                    ->label('სპეციალობა')
                    ->maxLength(150),

                TextInput::make('compensation_percentage')
                    ->label('ანაზღაურების პროცენტი')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->suffix('%')
                    ->helperText('გამოიყენება შესრულებული სამუშაოს მინუს პირდაპირი ხარჯების ბაზაზე.'),

                Toggle::make('is_active')
                    ->label('აქტიური ექიმი')
                    ->default(true),
            ]);
    }
}
