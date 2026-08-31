<?php

namespace App\Filament\Resources\ProductMaterials;

use App\Filament\Resources\ProductMaterials\Pages\EditProductMaterial;
use App\Filament\Resources\ProductMaterials\Pages\ListProductMaterials;
use App\Models\Product;
use App\Models\ProductCategory;
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
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class ProductMaterialResource extends Resource
{
    public static function shouldRegisterNavigation(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Product::query()->whereHas('category', fn ($query) => $query->where('slug', ProductCategory::NEEDS_REVIEW_SLUG))->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static string|UnitEnum|null $navigationGroup = 'ფინანსები';

    protected static ?string $navigationLabel = 'პროდუქტები / მასალები';

    protected static ?string $modelLabel = 'პროდუქტი / მასალა';

    protected static ?int $navigationSort = 51;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('დასახელება')->required()->maxLength(255),
            Select::make('product_category_id')->label('კატეგორია')->options(fn (): array => ProductCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())->required(),
            TextInput::make('selling_price')->label('გასაყიდი ფასი')->numeric()->minValue(0)->required()->suffix('₾'),
            Toggle::make('is_active')->label('აქტიურია')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('დასახელება')->searchable()->sortable(),
            TextColumn::make('category.name')->label('კატეგორია')->badge()->placeholder('Uncategorized')->sortable(),
            TextColumn::make('selling_price')->label('გასაყიდი ფასი')->money('GEL')->alignEnd(),
            IconColumn::make('is_active')->label('აქტიურია')->boolean(),
        ])->filters([
            Filter::make('needs_review')->label('Needs Review')
                ->query(fn ($query) => $query->whereHas('category', fn ($category) => $category->where('slug', ProductCategory::NEEDS_REVIEW_SLUG))),
            SelectFilter::make('product_category_id')->label('კატეგორია')->relationship('category', 'name')->searchable()->preload(),
        ])->recordActions([EditAction::make()])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListProductMaterials::route('/'), 'edit' => EditProductMaterial::route('/{record}/edit')];
    }
}
