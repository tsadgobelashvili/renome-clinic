<?php

namespace App\Filament\Resources\ProductMaterials;

use App\Filament\Resources\ProductMaterials\Pages\EditProductMaterial;
use App\Filament\Resources\ProductMaterials\Pages\ListProductMaterials;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ProductMaterialResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Product::query()->where('catalog_status', 'review')->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'ფინანსები';

    protected static ?string $navigationLabel = 'პროდუქტები';

    protected static ?string $modelLabel = 'პროდუქტი';

    protected static ?string $pluralModelLabel = 'პროდუქტები';

    protected static ?int $navigationSort = 51;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('დასახელება')->required()->maxLength(255),
            Select::make('catalog_status')->label('გამოყენება')->options(['sellable' => 'გასაყიდი პროდუქტი', 'review' => 'გადასამოწმებელი ძველი ჩანაწერი'])
                ->default('sellable')->required()->visible(fn ($record) => $record !== null),
            TextInput::make('selling_price')->label('გასაყიდი ფასი')->numeric()->minValue(0)->required()->suffix('₾'),
            Toggle::make('is_active')->label('აქტიურია')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->striped()->columns([
            TextColumn::make('name')->label('დასახელება')->searchable()->sortable(),
            TextColumn::make('selling_price')->label('გასაყიდი ფასი')->money('GEL')->alignEnd(),
            IconColumn::make('is_active')->label('აქტიურია')->boolean(),
        ])->recordActions([EditAction::make()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListProductMaterials::route('/'), 'edit' => EditProductMaterial::route('/{record}/edit')];
    }
}
