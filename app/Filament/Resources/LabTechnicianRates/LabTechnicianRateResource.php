<?php

namespace App\Filament\Resources\LabTechnicianRates;

use App\Filament\Resources\LabTechnicianRates\Pages\CreateLabTechnicianRate;
use App\Filament\Resources\LabTechnicianRates\Pages\EditLabTechnicianRate;
use App\Filament\Resources\LabTechnicianRates\Pages\ListLabTechnicianRates;
use App\Models\LabTechnicianRate;
use App\Models\LabWorkItem;
use App\Models\User;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LabTechnicianRateResource extends Resource
{
    protected static ?string $model = LabTechnicianRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('lab.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('lab.navigation.rates');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('technician_id')->label(__('lab.technician'))->options(fn () => User::query()->where('role', User::ROLE_LAB_TECHNICIAN)->orderBy('name')->pluck('name', 'id'))->searchable()->required(),
            Select::make('work_type')->label(__('lab.work_type'))->options(LabWorkItem::WORK_TYPES)->required(),
            Select::make('component_type')->label(__('lab.component'))->options(LabWorkItem::COMPONENT_TYPES)->required(),
            TextInput::make('rate_per_unit')->label(__('lab.rate'))->numeric()->minValue(0)->required(),
            DatePicker::make('effective_from')->label(__('employees.salary.effective_from'))->default(today())->required()
                ->helperText(__('employees.salary.rate_history_help')),
            Toggle::make('is_active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('technician.name')->label(__('lab.technician'))->sortable(),
            TextColumn::make('work_type')->formatStateUsing(fn ($state) => LabWorkItem::WORK_TYPES[$state] ?? $state),
            TextColumn::make('component_type')->formatStateUsing(fn ($state) => LabWorkItem::COMPONENT_TYPES[$state] ?? $state),
            TextColumn::make('rate_per_unit')->money('GEL'), IconColumn::make('is_active')->boolean(),
            TextColumn::make('effective_from')->label(__('employees.salary.effective_from'))->date('d.m.Y'),
        ])->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ListLabTechnicianRates::route('/'), 'create' => CreateLabTechnicianRate::route('/create'), 'edit' => EditLabTechnicianRate::route('/{record}/edit')];
    }
}
