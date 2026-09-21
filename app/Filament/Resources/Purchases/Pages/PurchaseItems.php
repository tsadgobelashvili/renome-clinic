<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\PurchaseItem;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseCatalog;
use App\Support\PurchaseQuantity;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseItems extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PurchaseResource::class;

    protected string $view = 'filament.resources.purchases.items';

    public function getTitle(): string
    {
        return 'RS / შეძენილი პროდუქტები';
    }

    public function mount(): void
    {
        abort_unless(PurchaseResource::canViewAny(), 403);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('documents')->label('დოკუმენტები')->url(PurchaseResource::getUrl())];
    }

    public function table(Table $table): Table
    {
        return $table->query(PurchaseItem::query()->with(['purchase.supplier', 'purchaseProduct']))
            ->striped()->columns([
                TextColumn::make('purchase.purchase_date')->label('თარიღი')->date('d.m.Y')->sortable(),
                TextColumn::make('purchase.document_number')->label('დოკუმენტი')->searchable()->placeholder('—'),
                TextColumn::make('item_name')->label('პროდუქტი / მასალა')->searchable()->limit(40)->tooltip(fn ($record) => $record->item_name),
                TextColumn::make('purchase.supplier.name')->label('მომწოდებელი')->searchable()->limit(25),
                TextColumn::make('quantity')->label('რაოდ.')->formatStateUsing(fn ($state): string => PurchaseQuantity::format($state))->alignEnd(),
                TextColumn::make('unit_price')->label('ფასი')->money('GEL')->alignEnd(),
                TextColumn::make('line_total')->label('ჯამი')->money('GEL')->alignEnd(),
                SelectColumn::make('direction')->label('მიმართულება')->placeholder('დასაზუსტებელია')
                    ->state(fn (PurchaseItem $record) => $record->purchaseProduct?->expense_direction_id)
                    ->options(fn (PurchaseItem $record) => app(PurchaseCatalog::class)->directionOptions($record->purchaseProduct?->expense_direction_id))
                    ->disabled(fn (PurchaseItem $record) => ! PurchaseResource::canEdit($record) || ! $record->purchaseProduct)
                    ->updateStateUsing(function (PurchaseItem $record, mixed $state) {
                        abort_unless(PurchaseResource::canEdit($record), 403);
                        app(PurchaseCatalog::class)->assignDirection($record->purchaseProduct, filled($state) ? (int) $state : null);

                        return $state;
                    }),
            ])->filters([
                Filter::make('uncategorized')->label('უკატეგორიო პროდუქტები')->toggle()
                    ->query(fn ($query) => $query->whereDoesntHave('purchaseProduct.direction')),
                Filter::make('date')->schema([
                    DatePicker::make('from')->label('დან'), DatePicker::make('until')->label('მდე'),
                ])->query(fn ($query, array $data) => $query->whereHas('purchase', fn ($purchase) => $purchase
                    ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('purchase_date', '>=', $date))
                    ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('purchase_date', '<=', $date)))),
                SelectFilter::make('supplier')->label('მომწოდებელი')->relationship('purchase.supplier', 'name')->searchable(),
                SelectFilter::make('direction')->label('მიმართულება')->options(fn () => app(ExpenseDimensions::class)->options('direction'))
                    ->query(fn ($query, array $data) => $query->when($data['value'] ?? null, fn ($q, $id) => $q->whereHas('purchaseProduct', fn ($product) => $product->where('expense_direction_id', $id)))),
            ], layout: FiltersLayout::AboveContent)->filtersFormColumns(4)->deferFilters(false)
            ->defaultSort('id', 'desc');
    }
}
