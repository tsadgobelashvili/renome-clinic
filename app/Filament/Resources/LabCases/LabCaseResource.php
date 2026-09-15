<?php

namespace App\Filament\Resources\LabCases;

use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Filament\Resources\LabCases\Schemas\LabCaseForm;
use App\Filament\Resources\LabCases\Tables\LabCasesTable;
use App\Models\LabCase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LabCaseResource extends Resource
{
    protected static ?string $model = LabCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?int $navigationSort = 10;

    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    public static function getNavigationLabel(): string
    {
        return __('lab.navigation.group');
    }

    public static function getModelLabel(): string
    {
        return __('lab.case');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lab.cases');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->canAccessLab() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAccessLab() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canAccessLab() ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return auth()->user()?->canAccessLab() ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function form(Schema $schema): Schema
    {
        return LabCaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LabCasesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        // Laboratory is shared operational work, not a per-user case inbox.
        $canViewAllLabCases = auth()->user()?->canAccessLab() ?? false;
        $query = parent::getEloquentQuery()->with(['patient', 'doctor', 'assistantEmployee', 'modeler.employee', 'miller', 'mainWorks.technicianEmployee', 'additionalWorks.technicianEmployee']);

        $query->with(['workItems' => fn ($work) => $canViewAllLabCases
            ? $work->with('technician')
            : $work->where('technician_id', auth()->id())->with('technician')]);

        return $canViewAllLabCases
            ? $query
            : $query->where(fn (Builder $cases): Builder => $cases
                ->where('created_by', auth()->id())
                ->orWhere('modeled_by', auth()->id())
                ->orWhere('milled_by', auth()->id())
                ->orWhereHas('workItems', fn (Builder $work) => $work->where('technician_id', auth()->id())));
    }

    public static function getPages(): array
    {
        return ['index' => ListLabCases::route('/'), 'edit' => EditLabCase::route('/{record}/edit')];
    }
}
