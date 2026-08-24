<?php

namespace App\Filament\Resources\TreatmentEstimates;

use App\Filament\Resources\TreatmentEstimates\Pages\CreateTreatmentEstimate;
use App\Filament\Resources\TreatmentEstimates\Pages\EditTreatmentEstimate;
use App\Filament\Resources\TreatmentEstimates\Pages\ListTreatmentEstimates;
use App\Filament\Resources\TreatmentEstimates\Pages\ViewTreatmentEstimate;
use App\Filament\Resources\TreatmentEstimates\Schemas\TreatmentEstimateForm;
use App\Filament\Resources\TreatmentEstimates\Schemas\TreatmentEstimateInfolist;
use App\Filament\Resources\TreatmentEstimates\Tables\TreatmentEstimatesTable;
use App\Models\TreatmentEstimate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TreatmentEstimateResource extends Resource
{
    protected static ?string $model = TreatmentEstimate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?string $recordTitleAttribute = 'estimate_date';

    protected static ?string $modelLabel = 'მკურნალობის გეგმა';

    protected static ?string $pluralModelLabel = 'მკურნალობის გეგმები';

    protected static ?string $navigationLabel = 'მკურნალობის გეგმები';

    protected static bool $shouldRegisterNavigation = false;

    public static function form(Schema $schema): Schema
    {
        return TreatmentEstimateForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return TreatmentEstimateInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TreatmentEstimatesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['patient', 'doctor', 'options.items', 'options.stages.items']);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTreatmentEstimates::route('/'),
            'create' => CreateTreatmentEstimate::route('/create'),
            'view' => ViewTreatmentEstimate::route('/{record}'),
            'edit' => EditTreatmentEstimate::route('/{record}/edit'),
        ];
    }
}
