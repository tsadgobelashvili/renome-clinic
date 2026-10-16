<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use App\Services\PurchaseCatalog;
use App\Support\FinanceExpenseForm;
use App\Support\PurchaseGroupForm;
use Filament\Actions\Action;
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
                    $catalog->assignCatalogDirection($record, filled($state) ? (int) $state : null);
                    $this->flushCachedTableRecords();

                    return $state;
                }),
            SelectColumn::make('product_group')->label('პროდუქციის ჯგუფი')->placeholder('დასაზუსტებელია')
                ->state(fn ($record) => $record->purchase_product_group_id)
                ->options(fn ($record) => $catalog->directionGroupOptions($record->expense_direction_id))
                ->disabled(fn ($record) => ! $record->expense_direction_id || Gate::denies('update', $record))
                ->updateStateUsing(function (PurchaseProduct $record, $state) use ($catalog) {
                    Gate::authorize('update', $record);
                    $catalog->assignCatalogGroup($record, filled($state) ? (int) $state : null);
                    $this->flushCachedTableRecords();

                    return $state;
                }),
        ])->recordActions([
            Action::make('createGroup')->label(__('product-groups.create'))->icon('heroicon-m-plus')->iconButton()
                ->tooltip(__('product-groups.create'))->modalHeading(__('product-groups.create'))->modalWidth('sm')
                ->visible(fn (PurchaseProduct $record) => $record->expense_direction_id
                    && Gate::allows('update', $record) && Gate::allows('create', PurchaseProductGroup::class))
                ->fillForm(fn (PurchaseProduct $record) => ['expense_direction_id' => $record->expense_direction_id])
                ->schema(PurchaseGroupForm::schema(true))
                ->action(function (PurchaseProduct $record, array $data) use ($catalog): void {
                    $catalog->createGroupForProduct($record, $data);
                    $this->flushCachedTableRecords();
                }),
        ])->headerActions([
            Action::make('createProduct')->label(__('expense-entry.add_product'))
                ->visible(fn () => Gate::allows('create', PurchaseProduct::class))
                ->schema(FinanceExpenseForm::productForm())
                ->action(fn (array $data) => $catalog->createManual($data)),
        ])->defaultSort('name')->paginated([25, 50])->defaultPaginationPageOption(25);
    }
}
