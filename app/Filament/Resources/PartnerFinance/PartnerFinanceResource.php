<?php

namespace App\Filament\Resources\PartnerFinance;

use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Filament\Resources\PartnerFinance\Tables\PartnerFinanceTable;
use App\Models\PartnerFinanceEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class PartnerFinanceResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    protected static ?string $model = PartnerFinanceEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'ისრაელი - ფინანსები';

    protected static ?string $pluralModelLabel = 'პარტნიორის ფინანსები';

    protected static ?string $modelLabel = 'პარტნიორის ფინანსური ჩანაწერი';

    public static function table(Table $table): Table
    {
        return PartnerFinanceTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListPartnerFinance::route('/')];
    }
}
