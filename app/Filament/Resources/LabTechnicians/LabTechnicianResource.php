<?php

namespace App\Filament\Resources\LabTechnicians;

use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LabTechnicians\Pages\EditLabTechnician;
use App\Filament\Resources\LabTechnicians\Pages\ListLabTechnicians;
use App\Filament\Resources\LabTechnicians\Pages\ViewLabTechnician;
use App\Models\Employee;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LabTechnicianResource extends Resource
{
    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('lab.navigation.group');
    }

    public static function getNavigationLabel(): string
    {
        return __('lab.navigation.technicians');
    }

    public static function getModelLabel(): string
    {
        return __('lab.technician');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lab.navigation.technicians');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canView(Model $record): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('position', fn (Builder $position): Builder => $position->where('is_technician', true))
            ->with(['position', 'salaryRates']);
    }

    public static function form(Schema $schema): Schema
    {
        return EmployeeResource::form($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('full_name')->label(__('employees.name'))
                    ->searchable(['first_name', 'last_name'])->sortable(['first_name', 'last_name']),
                TextColumn::make('position.name')->label(__('employees.position'))->badge()->sortable(),
                TextColumn::make('salary_type')->label(__('employees.salary.type'))
                    ->formatStateUsing(fn (?string $state): string => $state ? __('employees.salary.'.$state) : '—'),
                IconColumn::make('salary_active')->label(__('employees.salary.active'))->boolean(),
                IconColumn::make('is_active')->label(__('employees.active'))->boolean(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->recordUrl(fn (Employee $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('first_name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLabTechnicians::route('/'),
            'view' => ViewLabTechnician::route('/{record}'),
            'edit' => EditLabTechnician::route('/{record}/edit'),
        ];
    }
}
