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
use Illuminate\Support\Facades\Gate;

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

                                TextInput::make('first_name_en')
                                    ->label(app()->getLocale() === 'en' ? 'First name (Latin)' : 'სახელი (ლათინურად)')
                                    ->maxLength(100),
                                TextInput::make('last_name_en')
                                    ->label(app()->getLocale() === 'en' ? 'Last name (Latin)' : 'გვარი (ლათინურად)')
                                    ->maxLength(100),

                                TextInput::make('phone')
                                    ->label('ტელეფონი')
                                    ->tel()
                                    ->maxLength(30),

                                Select::make('specialties')->label('სპეციალობები')
                                    ->options(TreatmentCase::CATEGORIES)->multiple()->searchable()->live()->default([])->columnSpanFull(),
                            ]),

                        Group::make([
                        Section::make('ანაზღაურება')->compact()
                            ->disabled(fn (): bool => ! Gate::allows('manageCompensation', Doctor::class))
                            ->columns(['default' => 1, 'sm' => 2])->schema([
                                ...collect(TreatmentCase::CATEGORIES)->map(fn (string $label, string $key) => TextInput::make('compensation_category_percentages.'.$key)->label($label.' (%)')
                                    ->numeric()->required(fn (): bool => Gate::allows('manageCompensation', Doctor::class))->minValue(0)->maxValue(100)->step(0.01)->suffix('%')
                                    ->visible(fn (Get $get): bool => in_array($key, $get('specialties') ?? [], true))
                                )->values()->all(),

                                TextInput::make('israeli_lab_zircon_rate')
                                    ->label('ცირკონი · GEL / ერთეული')
                                    ->numeric()->nullable()->minValue(0)->maxValue(99999999.99)->step(0.01)->suffix('GEL')
                                    ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null)
                                    ->visible(fn (Get $get): bool => in_array('orthopedics', $get('specialties') ?? [], true)),
                                TextInput::make('israeli_lab_pmma_rate')
                                    ->label('PMMA · GEL / ერთეული')
                                    ->numeric()->nullable()->minValue(0)->maxValue(99999999.99)->step(0.01)->suffix('GEL')
                                    ->dehydrateStateUsing(fn ($state) => filled($state) ? $state : null)
                                    ->visible(fn (Get $get): bool => in_array('orthopedics', $get('specialties') ?? [], true)),

                                Select::make('clinic_salary_payment_method')->label(__('clinic-payroll.doctor_method'))
                                    ->options(['bank_transfer' => __('employees.payroll.bank'), 'cash' => __('employees.payroll.cash')])
                                    ->default('bank_transfer')->required()->native(false),
                                Toggle::make('owner_split_enabled')->label('Owner Split')
                                    ->default(false)
                                    ->afterStateHydrated(fn (Toggle $component, ?Doctor $record) => $component->state($record?->isOwnerSplitDoctor() ?? false))
                                    ->visible(fn (Get $get): bool => in_array(TreatmentCase::STATISTICS_GROUP_CATEGORIES['implantation'], $get('specialties') ?? [], true)),
                            ]),
                        Section::make(__('israeli-compensation.title'))->description(__('israeli-compensation.help'))->compact()
                            ->disabled(fn (): bool => ! Gate::allows('manageCompensation', Doctor::class))
                            ->columns(['default' => 1, 'sm' => 2])->schema([
                                Select::make('israeli_visit_salary_type')->label(__('israeli-compensation.type'))
                                    ->options(['percent' => __('israeli-compensation.percent'), 'fixed' => __('israeli-compensation.fixed')])
                                    ->placeholder(__('israeli-compensation.unconfigured'))->native(false)->live(),
                                TextInput::make('israeli_visit_salary_rate')->label(fn (Get $get) => __('israeli-compensation.'.($get('israeli_visit_salary_type') === 'fixed' ? 'amount' : 'rate')))
                                    ->numeric()->minValue(0)->maxValue(fn (Get $get) => $get('israeli_visit_salary_type') === 'percent' ? 100 : 99999999.99)->step(0.01)
                                    ->suffix(fn (Get $get) => $get('israeli_visit_salary_type') === 'fixed' ? 'GEL' : '%')
                                    ->required(fn (Get $get) => filled($get('israeli_visit_salary_type')))
                                    ->visible(fn (Get $get) => filled($get('israeli_visit_salary_type'))),
                                Select::make('israeli_salary_payment_method')->label(__('israeli-compensation.method'))
                                    ->options(['cash' => __('employees.payroll.cash'), 'bank_transfer' => __('employees.payroll.bank')])
                                    ->default('cash')->required()->native(false),
                            ]),
                        ])->columnSpan(['default' => 1, 'xl' => 3]),
                    ]),

                    Toggle::make('is_active')
                        ->label('აქტიური ექიმი')
                        ->default(true),
                ])->columnSpanFull(),
            ]);
    }
}
