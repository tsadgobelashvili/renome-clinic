<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\PurchaseProductGroup;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseCatalog;
use App\Support\PurchaseGroupForm;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

class PurchaseProductGroups extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PurchaseResource::class;

    protected string $view = 'filament.resources.purchases.mapping-table';

    public function getTitle(): string
    {
        return 'პროდუქციის ჯგუფები';
    }

    public function table(Table $table): Table
    {
        return $table->query(PurchaseProductGroup::query()->withCount('products'))->striped()
            ->columns([
                TextColumn::make('name')->label('ჯგუფი')->searchable()->sortable(),
                TextColumn::make('products_count')->label('პროდუქტები')->alignEnd(),
                TextColumn::make('resolved_direction')->label(__('expense-dimensions.direction'))
                    ->state(fn ($record) => app(ExpenseDimensions::class)->labelById(app(PurchaseCatalog::class)->groupDirection($record->id))),
            ])->recordUrl(fn ($record) => PurchaseResource::getUrl('group-products', ['group' => $record->id]))
            ->headerActions([CreateAction::make()->label('ჯგუფის დამატება')->schema(PurchaseGroupForm::schema())
                ->using(fn (array $data) => app(PurchaseCatalog::class)->createGroup($data))])
            ->recordActions([
                EditAction::make()->label('რედაქტირება')->iconButton()->schema(PurchaseGroupForm::schema())
                    ->mutateRecordDataUsing(fn (array $data, PurchaseProductGroup $record) => array_replace($data, ['expense_direction_id' => app(PurchaseCatalog::class)->groupDirection($record->id)]))
                    ->using(fn (PurchaseProductGroup $record, array $data) => app(PurchaseCatalog::class)->updateGroup($record, $data)),
                DeleteAction::make()->label('წაშლა')->iconButton()->disabled(fn ($record) => $record->products_count > 0)
                    ->tooltip('გამოყენებული ჯგუფის წაშლამდე გადაიტანეთ პროდუქტები'),
            ])->defaultSort('name')->paginated([25, 50]);
    }
}
