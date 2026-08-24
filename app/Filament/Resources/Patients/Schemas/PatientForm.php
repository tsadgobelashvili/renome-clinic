<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Models\Patient;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PatientForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('first_name')
                    ->label('სახელი')
                    ->required()
                    ->validationMessages(['required' => 'სახელის მითითება აუცილებელია.'])
                    ->maxLength(100),

                TextInput::make('last_name')
                    ->label('გვარი')
                    ->required()
                    ->validationMessages(['required' => 'გვარის მითითება აუცილებელია.'])
                    ->maxLength(100),

                TextInput::make('phone')
                    ->label('ტელეფონი')
                    ->tel()
                    ->required()
                    ->validationMessages(['required' => 'ტელეფონის მითითება აუცილებელია.'])
                    ->live(onBlur: true)
                    ->helperText(function (?string $state, ?Patient $record): ?string {
                        if (blank($state)) {
                            return null;
                        }

                        $exists = Patient::query()->where('phone', trim($state))
                            ->when($record, fn ($query) => $query->whereKeyNot($record->getKey()))
                            ->exists();

                        return $exists ? 'ამ ტელეფონით სხვა პაციენტიც არსებობს.' : null;
                    })
                    ->maxLength(30),

                TextInput::make('personal_id')
                    ->label('პირადი ნომერი')
                    ->unique(ignoreRecord: true)
                    ->validationMessages(['unique' => 'ამ პირადი ნომრით პაციენტი უკვე არსებობს.'])
                    ->maxLength(20),

                DatePicker::make('birth_date')
                    ->label('დაბადების თარიღი'),

                Textarea::make('notes')
                    ->label('შენიშვნა')
                    ->rows(3)
                    ->columnSpanFull(),
            ])
            ->columns(['default' => 1, 'md' => 2]);
    }
}
