<?php

namespace App\Filament\Resources\Doctors;

use App\Filament\Resources\Doctors\Pages\CreateDoctor;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Filament\Resources\Doctors\Pages\ListDoctors;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Filament\Resources\Doctors\RelationManagers\VisitsRelationManager;
use App\Filament\Resources\Doctors\Schemas\DoctorForm;
use App\Filament\Resources\Doctors\Schemas\DoctorInfolist;
use App\Filament\Resources\Doctors\Tables\DoctorsTable;
use App\Models\Doctor;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;

class DoctorResource extends Resource
{
    protected static ?string $model = Doctor::class;

    protected static string|BackedEnum|null $navigationIcon = 'renome-doctor';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?int $navigationSort = 10;

    protected static ?string $navigationLabel = 'ექიმები';

    protected static ?string $recordTitleAttribute = 'full_name';

    public static function form(Schema $schema): Schema
    {
        return DoctorForm::configure($schema);
    }

    public static function authorizedProfileData(array $data): array
    {
        if (Gate::allows('manageCompensation', Doctor::class)) {
            return $data;
        }

        // Enforce this at both persistence boundaries, independently of form visibility.
        return Arr::except($data, [
            'compensation_category_percentages',
            'compensation_percentage',
            'israeli_lab_zircon_rate',
            'israeli_lab_pmma_rate',
            'clinic_salary_payment_method',
            'owner_split_enabled',
            'owner_split_key',
        ]);
    }

    public static function table(Table $table): Table
    {
        return DoctorsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DoctorInfolist::configure($schema);
    }

    public static function getRelations(): array
    {
        return [
            VisitsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDoctors::route('/'),
            'create' => CreateDoctor::route('/create'),
            'view' => ViewDoctor::route('/{record}'),
            'edit' => EditDoctor::route('/{record}/edit'),
        ];
    }
}
