<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Supplier;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class PurchaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(3)->schema([
                DatePicker::make('purchase_date')->label('თარიღი')->default(today())->required(),
                Select::make('supplier_id')->label('მომწოდებელი')->relationship('supplier', 'name')
                    ->searchable()->preload()->required()
                    ->createOptionForm([
                        TextInput::make('name')->label('დასახელება')->required()->maxLength(255),
                        TextInput::make('tax_id')->label('საიდენტიფიკაციო კოდი')->maxLength(50),
                        TextInput::make('phone')->label('ტელეფონი')->maxLength(50),
                    ])->createOptionUsing(fn (array $data): int => Supplier::create($data)->getKey()),
                TextInput::make('document_number')->label('ინვოისი / დოკუმენტის №')->maxLength(255),
            ])->columnSpanFull(),
            Section::make('პროდუქტები / მასალები')->schema([
                Repeater::make('items')->relationship()->minItems(1)->defaultItems(1)->columns(6)->live()->schema([
                    Select::make('product_id')->label('პროდუქტი / მასალა')->searchable()->required()
                        ->getSearchResultsUsing(fn (string $search): array => Product::query()
                            ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($search)).'%'])
                            ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Product::find($value)?->name)
                        ->createOptionForm([
                            TextInput::make('name')->label('დასახელება')->required()->maxLength(255),
                            Select::make('product_category_id')->label('კატეგორია')
                                ->options(fn (): array => ProductCategory::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                                ->default(fn (): ?int => ProductCategory::uncategorizedId())->required(),
                        ])->createOptionUsing(fn (array $data): int => Product::create([...$data, 'selling_price' => 0, 'is_active' => true])->getKey()),
                    TextInput::make('quantity')->label('რაოდენობა')->numeric()->minValue(0.001)->step(0.001)->default(1)->required()->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::updateLineTotal($get, $set)),
                    TextInput::make('unit')->label('ერთეული')->maxLength(50),
                    TextInput::make('unit_price')->label('ერთეულის ფასი')->numeric()->minValue(0)->step(0.01)->required()->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::updateLineTotal($get, $set)),
                    TextInput::make('line_total')->label('ჯამი')->numeric()->default(0)->disabled()->dehydrated()->suffix('₾'),
                    Placeholder::make('category_name')->label('კატეგორია')->content(fn (Get $get): string => Product::query()->with('category')->find($get('product_id'))?->category?->name ?? 'Uncategorized'),
                ]),
                Placeholder::make('purchase_total')->label('სრული თანხა')->content(fn (Get $get): string => number_format((float) collect($get('items') ?? [])->sum(fn (array $item): float => (float) ($item['line_total'] ?? 0)), 2).' ₾'),
            ])->columnSpanFull(),
            Textarea::make('notes')->label('შენიშვნა')->rows(3)->columnSpanFull(),
        ]);
    }

    private static function updateLineTotal(Get $get, Set $set): void
    {
        $set('line_total', round((float) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0), 2));
    }
}
