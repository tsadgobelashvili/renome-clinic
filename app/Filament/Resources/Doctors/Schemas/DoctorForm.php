<?php

namespace App\Filament\Resources\Doctors\Schemas;

use Filament\Forms\Components\Select;
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

                Select::make('clinic_salary_payment_method')->label(__('clinic-payroll.doctor_method'))
                    ->options(['bank_transfer' => __('employees.payroll.bank'), 'cash' => __('employees.payroll.cash')])
                    ->default('bank_transfer')->required()->native(false),

                TextInput::make('israeli_lab_zircon_rate')
                    ->label('Israeli Lab Zircon · GEL / unit')
                    ->numeric()->minValue(0)->step(0.01)->suffix('GEL'),

                TextInput::make('compensation_category_percentages.therapy')
                    ->label('Therapy salary %')
                    ->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%'),

                TextInput::make('compensation_category_percentages.periodontology')
                    ->label('Periodontology salary %')
                    ->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%'),

                Toggle::make('is_active')
                    ->label('აქტიური ექიმი')
                    ->default(true),
            ]);
    }
}
