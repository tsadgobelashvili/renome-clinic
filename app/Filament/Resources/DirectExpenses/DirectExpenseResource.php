<?php

namespace App\Filament\Resources\DirectExpenses;

use App\Filament\Resources\DirectExpenses\Pages\ListDirectExpenses;
use App\Filament\Resources\DirectExpenses\Tables\DirectExpensesTable;
use App\Models\VisitTreatmentCase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class DirectExpenseResource extends Resource
{
    protected static ?string $model = VisitTreatmentCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'ფინანსები';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'პირდაპირი ხარჯები';

    protected static ?string $modelLabel = 'პირდაპირი ხარჯი';

    protected static ?string $pluralModelLabel = 'პირდაპირი ხარჯები';

    public static function table(Table $table): Table
    {
        return DirectExpensesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['visit.patient', 'visit.doctor', 'treatmentCase'])
            ->withCount('directExpenses')
            ->withSum('directExpenses', 'amount');
    }

    public static function getPages(): array
    {
        return ['index' => ListDirectExpenses::route('/')];
    }
}
