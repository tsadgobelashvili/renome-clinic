<?php

namespace App\Filament\Resources\TreatmentCases;

use App\Filament\Resources\TreatmentCases\Pages\CreateTreatmentCase;
use App\Filament\Resources\TreatmentCases\Pages\EditTreatmentCase;
use App\Filament\Resources\TreatmentCases\Pages\ListTreatmentCases;
use App\Filament\Resources\TreatmentCases\Schemas\TreatmentCaseForm;
use App\Filament\Resources\TreatmentCases\Tables\TreatmentCasesTable;
use App\Models\TreatmentCase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class TreatmentCaseResource extends Resource
{
    protected static ?string $model = TreatmentCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $modelLabel = 'მანიპულაცია';

    protected static ?string $pluralModelLabel = 'მკურნალობის კატალოგი';

    protected static ?string $navigationLabel = 'მკურნალობის კატალოგი';

    public static function form(Schema $schema): Schema
    {
        return TreatmentCaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TreatmentCasesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTreatmentCases::route('/'),
            'create' => CreateTreatmentCase::route('/create'),
            'edit' => EditTreatmentCase::route('/{record}/edit'),
        ];
    }
}
