<?php

namespace App\Filament\Resources\PartnerPatients;

use App\Filament\Resources\PartnerPatients\Pages\CreatePartnerPatient;
use App\Filament\Resources\PartnerPatients\Pages\EditPartnerPatient;
use App\Filament\Resources\PartnerPatients\Pages\ListPartnerPatients;
use App\Filament\Resources\PartnerPatients\Pages\ViewPartnerPatient;
use App\Filament\Resources\PartnerPatients\RelationManagers\PartnerPaymentsRelationManager;
use App\Filament\Resources\PartnerPatients\Schemas\PartnerPatientInfolist;
use App\Filament\Resources\PartnerPatients\Tables\PartnerPatientsTable;
use App\Filament\Resources\Patients\Schemas\PatientForm;
use App\Models\Patient;
use App\Models\PatientGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PartnerPatientResource extends Resource
{
    protected static ?string $model = Patient::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $recordTitleAttribute = 'full_name';

    protected static ?string $modelLabel = 'პარტნიორი პაციენტი';

    protected static ?string $pluralModelLabel = 'პარტნიორი პაციენტები';

    protected static ?string $navigationLabel = 'ისრაელი - პაციენტები';

    public static function form(Schema $schema): Schema
    {
        return PatientForm::configure($schema, showPatientGroup: false);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PartnerPatientInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PartnerPatientsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereHas(
            'patientGroup',
            fn (Builder $query): Builder => $query->where('slug', PatientGroup::ISRAEL_PARTNER_SLUG),
        );
    }

    public static function getRelations(): array
    {
        return [PartnerPaymentsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPartnerPatients::route('/'),
            'create' => CreatePartnerPatient::route('/create'),
            'view' => ViewPartnerPatient::route('/{record}'),
            'edit' => EditPartnerPatient::route('/{record}/edit'),
        ];
    }
}
