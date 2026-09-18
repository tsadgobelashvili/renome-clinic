<?php

namespace App\Filament\Resources\Doctors\Schemas;

use App\Models\Doctor;
use App\Models\TreatmentCase;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DoctorForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Group::make([
                    Grid::make(['default' => 1, 'xl' => 5])->schema([
                        Section::make('ძირითადი ინფორმაცია')->compact()->columnSpan(['default' => 1, 'xl' => 2])
                            ->columns(['default' => 1, 'sm' => 2])->schema([
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

                                Select::make('specialties')->label('სპეციალობები')
                                    ->options(TreatmentCase::CATEGORIES)->multiple()->searchable()->live()->default([])->columnSpanFull(),
                            ]),

                        Section::make('ანაზღაურება')->compact()->columnSpan(['default' => 1, 'xl' => 3])
                            ->columns(['default' => 1, 'sm' => 2])->schema([
                                ...collect(TreatmentCase::CATEGORIES)->map(fn (string $label, string $key) => TextInput::make('compensation_category_percentages.'.$key)->label($label.' (%)')
                                    ->numeric()->required()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')
                                    ->visible(fn (Get $get): bool => in_array($key, $get('specialties') ?? [], true))
                                )->values()->all(),

                                TextInput::make('israeli_lab_zircon_rate')
                                    ->label('ცირკონი · GEL / ერთეული')
                                    ->numeric()->required()->minValue(0)->maxValue(99999999.99)->step(0.01)->suffix('GEL')
                                    ->visible(fn (Get $get): bool => in_array('orthopedics', $get('specialties') ?? [], true)),
                                TextInput::make('israeli_lab_pmma_rate')
                                    ->label('PMMA · GEL / ერთეული')
                                    ->numeric()->required()->minValue(0)->maxValue(99999999.99)->step(0.01)->suffix('GEL')
                                    ->visible(fn (Get $get): bool => in_array('orthopedics', $get('specialties') ?? [], true)),

                                Select::make('clinic_salary_payment_method')->label(__('clinic-payroll.doctor_method'))
                                    ->options(['bank_transfer' => __('employees.payroll.bank'), 'cash' => __('employees.payroll.cash')])
                                    ->default('bank_transfer')->required()->native(false),
                                Toggle::make('owner_split_enabled')->label('Owner Split')
                                    ->default(false)
                                    ->afterStateHydrated(fn (Toggle $component, ?Doctor $record) => $component->state($record?->isOwnerSplitDoctor() ?? false))
                                    ->visible(fn (Get $get): bool => in_array(TreatmentCase::STATISTICS_GROUP_CATEGORIES['implantation'], $get('specialties') ?? [], true)),
                            ]),
                    ]),

                    Toggle::make('is_active')
                        ->label('აქტიური ექიმი')
                        ->default(true),
                ])->columnSpanFull(),
            ]);
    }
}
