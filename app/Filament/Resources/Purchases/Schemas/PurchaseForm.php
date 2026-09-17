<?php

namespace App\Filament\Resources\Purchases\Schemas;

use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Services\PurchaseCatalog;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
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
                    ->searchable()->required()->live()
                    ->disabled(fn ($record) => $record?->exists ?? false)
                    ->afterStateUpdated(fn (Set $set) => $set('items', []))
                    ->createOptionForm([
                        TextInput::make('name')->label('დასახელება')->required()->maxLength(255),
                        TextInput::make('tax_id')->label('საიდენტიფიკაციო კოდი')->maxLength(50),
                        TextInput::make('phone')->label('ტელეფონი')->maxLength(50),
                    ])->createOptionUsing(fn (array $data): int => Supplier::create($data)->getKey()),
                TextInput::make('document_number')->label('ინვოისი / დოკუმენტის №')->maxLength(255),
            ])->columnSpanFull(),
            Section::make('პროდუქტები / მასალები')->compact()->description('მიმართულება დამახსოვრდება ამ პროდუქტის შემდეგი იმპორტებისთვისაც.')->schema([
                Repeater::make('items')->hiddenLabel()->relationship(modifyQueryUsing: fn ($query) => $query->with('purchaseProduct'))
                    ->minItems(1)->defaultItems(1)->columns(['default' => 2, 'md' => 6, 'xl' => 12])->compact()->reorderable(false)
                    ->extraAttributes(['class' => 'renome-rs-items'])->addActionLabel('პროდუქტის დამატება')->live()
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::saveDirection($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::saveDirection($data))->schema([
                        Select::make('purchase_product_id')->label('პროდუქტი / მასალა')->searchable()->required()->wrapOptionLabels(false)
                            ->columnSpan(['default' => 2, 'md' => 4, 'xl' => 4])
                            ->getSearchResultsUsing(fn (string $search, Get $get): array => PurchaseProduct::query()
                                ->where('supplier_id', $get('../../supplier_id'))
                                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($search)).'%'])
                                ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                            ->getOptionLabelUsing(fn ($value): ?string => PurchaseProduct::find($value)?->name)
                            ->live()->afterStateUpdated(function ($state, Set $set): void {
                                $product = PurchaseProduct::find($state);
                                $set('item_name', $product?->name);
                                $set('expense_direction_id', $product?->expense_direction_id);
                                $set('original_direction_id', $product?->expense_direction_id);
                            })
                            ->createOptionForm([
                                TextInput::make('name')->label('დასახელება')->required()->maxLength(255),
                                TextInput::make('supplier_product_code')->label('მომწოდებლის პროდუქტის კოდი')->maxLength(255),
                            ])->createOptionUsing(fn (array $data, Get $get): int => app(PurchaseCatalog::class)->resolve(
                                (int) $get('../../supplier_id'), $data['name'], null, $data['supplier_product_code'] ?? null,
                            )->getKey()),
                        Hidden::make('item_name'),
                        Hidden::make('original_direction_id')->afterStateHydrated(fn (Set $set, ?PurchaseItem $record) => $set('original_direction_id', $record?->purchaseProduct?->expense_direction_id)),
                        TextInput::make('quantity')->label('რაოდ.')->numeric()->minValue(0.001)->step(0.001)->default(1)->required()->live(debounce: 300)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateLineTotal($get, $set)),
                        TextInput::make('unit')->label('ერთეული')->maxLength(50),
                        TextInput::make('unit_price')->label('ფასი')->numeric()->minValue(0)->step(0.01)->required()->live(debounce: 300)->columnSpan(['default' => 1, 'md' => 2, 'xl' => 2])
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::updateLineTotal($get, $set)),
                        TextInput::make('line_total')->label('ჯამი')->numeric()->default(0)->disabled()->dehydrated()->suffix('₾')->columnSpan(['default' => 1, 'md' => 2, 'xl' => 2]),
                        Select::make('expense_direction_id')->label('მიმართულება')->placeholder('დასაზუსტებელია')
                            ->columnSpan(2)->options(fn (Get $get) => app(PurchaseCatalog::class)->directionOptions(filled($get('original_direction_id')) ? (int) $get('original_direction_id') : null))
                            ->afterStateHydrated(fn (Set $set, ?PurchaseItem $record) => $set('expense_direction_id', $record?->purchaseProduct?->expense_direction_id))
                            ->live()->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                // Multiple lines can represent the same supplier-scoped product.
                                foreach ($get('../../items') ?? [] as $key => $item) {
                                    if (filled($get('purchase_product_id')) && ($item['purchase_product_id'] ?? null) == $get('purchase_product_id')) {
                                        $set('../../items.'.$key.'.expense_direction_id', $state);
                                    }
                                }
                            }),
                    ]),
                Placeholder::make('purchase_total')->label('სრული თანხა')->content(fn (Get $get): string => number_format((float) collect($get('items') ?? [])->sum(fn (array $item): float => (float) ($item['line_total'] ?? 0)), 2).' ₾'),
            ])->columnSpanFull(),
            Textarea::make('notes')->label('შენიშვნა')->rows(1)->columnSpanFull(),
        ]);
    }

    private static function saveDirection(array $data): array
    {
        $direction = filled($data['expense_direction_id'] ?? null) ? (int) $data['expense_direction_id'] : null;
        $original = filled($data['original_direction_id'] ?? null) ? (int) $data['original_direction_id'] : null;
        if ($direction !== $original) {
            app(PurchaseCatalog::class)->assignDirection(PurchaseProduct::findOrFail($data['purchase_product_id']), $direction);
        }
        unset($data['expense_direction_id'], $data['original_direction_id']);

        return $data;
    }

    private static function updateLineTotal(Get $get, Set $set): void
    {
        $set('line_total', round((float) ($get('quantity') ?? 0) * (float) ($get('unit_price') ?? 0), 2));
    }
}
