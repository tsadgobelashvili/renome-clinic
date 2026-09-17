<?php

namespace App\Filament\Resources\EmployeePositions;

use App\Filament\Resources\EmployeePositions\Pages\CreateEmployeePosition;
use App\Filament\Resources\EmployeePositions\Pages\EditEmployeePosition;
use App\Filament\Resources\EmployeePositions\Pages\ListEmployeePositions;
use App\Models\EmployeePosition;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EmployeePositionResource extends Resource
{
    protected static ?string $model = EmployeePosition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|\UnitEnum|null $navigationGroup = 'პერსონალი';

    protected static ?int $navigationSort = 20;

    public static function getNavigationLabel(): string
    {
        return app()->getLocale() === 'ka' ? 'პოზიციები' : 'Positions';
    }

    public static function getModelLabel(): string
    {
        return __('employees.position');
    }

    public static function getPluralModelLabel(): string
    {
        return __('employees.positions');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('employees.position_name'))->required()->maxLength(255)->unique(ignoreRecord: true),
            Toggle::make('is_technician')->label(__('employees.is_technician'))->default(false),
            Toggle::make('is_active')->label(__('employees.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('employees.position_name'))->searchable()->sortable(),
            IconColumn::make('is_technician')->label(__('employees.is_technician'))->boolean(),
            IconColumn::make('is_active')->label(__('employees.active'))->boolean(),
        ])->filters([
            TernaryFilter::make('is_technician')->label(__('employees.is_technician')),
            TernaryFilter::make('is_active')->label(__('employees.active')),
        ])->recordActions([EditAction::make()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListEmployeePositions::route('/'), 'create' => CreateEmployeePosition::route('/create'), 'edit' => EditEmployeePosition::route('/{record}/edit')];
    }
}
