<?php

namespace App\Filament\Resources\TreatmentCases\Schemas;

use App\Models\TreatmentCase;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
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

                Radio::make('statistics_group_mode')
                    ->label(fn (): string => app()->getLocale() === 'en' ? 'Statistics placement' : 'სტატისტიკაში განთავსება')
                    ->options([
                        'group' => app()->getLocale() === 'en' ? 'Use group' : 'ჯგუფში',
                        'direct' => app()->getLocale() === 'en' ? 'Directly in category' : 'პირდაპირ კატეგორიაში',
                    ])
                    ->default('group')
                    ->inline()
                    ->live()
                    ->dehydrated(false)
                    ->afterStateHydrated(fn (Radio $component, ?TreatmentCase $record) => $component->state($record && $record->statistics_group === null ? 'direct' : 'group')),

                Select::make('statistics_group')
                    ->label(fn (): string => app()->getLocale() === 'en' ? 'Statistics Group' : 'სტატისტიკის ჯგუფი')
                    ->options(TreatmentCase::STATISTICS_GROUPS)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Select $component, $livewire) => $livewire->validateOnly($component->getStatePath()))
                    ->visible(fn (Get $get): bool => $get('statistics_group_mode') === 'group')
                    ->required(fn (Get $get): bool => $get('statistics_group_mode') === 'group')
                    ->dehydratedWhenHidden()
                    ->dehydrateStateUsing(fn (?string $state, Get $get): ?string => $get('statistics_group_mode') === 'group' ? $state : null)
                    ->validationMessages([
                        'required' => 'სტატისტიკის ჯგუფი სავალდებულოა',
                        'in' => 'აირჩიეთ სტატისტიკის სწორი ჯგუფი',
                    ])
                    ->helperText(fn (): string => app()->getLocale() === 'en'
                        ? 'Combines similar manipulations in Analytics only.'
                        : 'აერთიანებს მსგავს მანიპულაციებს მხოლოდ სტატისტიკაში.'),

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
