<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\PurchaseProduct;
use App\Services\PurchaseCatalog;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

abstract class PurchaseProductMappings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PurchaseResource::class;

    protected string $view = 'filament.resources.purchases.mapping-table';

    abstract protected function productQuery(): Builder;

    public function table(Table $table): Table
    {
        $catalog = app(PurchaseCatalog::class);

        return $table->query($this->productQuery()->with('supplier'))->striped()->columns([
            TextColumn::make('name')->label('პროდუქტი')->searchable()->limit(50)->tooltip(fn ($record) => $record->name),
            TextColumn::make('supplier.name')->label('მომწოდებელი')->limit(30),
            TextColumn::make('rs_product_code')->label('კოდი')->placeholder('—'),
            SelectColumn::make('direction')->label('მიმართულება')->placeholder('დასაზუსტებელია')
                ->state(fn ($record) => $record->expense_direction_id)
                ->options(fn ($record) => $catalog->directionOptions($record->expense_direction_id))
                ->updateStateUsing(function (PurchaseProduct $record, $state) use ($catalog) {
                    Gate::authorize('update', $record);
                    $catalog->assignDirection($record, filled($state) ? (int) $state : null);
                    $this->resetPage();

                    return $state;
                }),
            SelectColumn::make('product_group')->label('პროდუქციის ჯგუფი')->placeholder('დასაზუსტებელია')
                ->state(fn ($record) => $record->purchase_product_group_id)->options($catalog->groupOptions())
                ->updateStateUsing(function (PurchaseProduct $record, $state) use ($catalog) {
                    Gate::authorize('update', $record);
                    $catalog->assignGroup($record, filled($state) ? (int) $state : null);
                    $this->resetPage();

                    return $state;
                }),
        ])->defaultSort('name')->paginated([25, 50])->defaultPaginationPageOption(25);
    }
}
