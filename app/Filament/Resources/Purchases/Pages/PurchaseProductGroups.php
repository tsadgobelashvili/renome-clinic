<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\PurchaseProductGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
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

    private function groupForm(): array
    {
        return [TextInput::make('name')->label('დასახელება')->required()->maxLength(255)->unique(ignoreRecord: true)];
    }

    public function table(Table $table): Table
    {
        return $table->query(PurchaseProductGroup::query()->withCount('products'))->striped()
            ->columns([
                TextColumn::make('name')->label('ჯგუფი')->searchable()->sortable(),
                TextColumn::make('products_count')->label('პროდუქტები')->alignEnd(),
            ])->recordUrl(fn ($record) => PurchaseResource::getUrl('group-products', ['group' => $record->id]))
            ->headerActions([CreateAction::make()->label('ჯგუფის დამატება')->schema($this->groupForm())])
            ->recordActions([
                EditAction::make()->label('რედაქტირება')->iconButton()->schema($this->groupForm()),
                DeleteAction::make()->label('წაშლა')->iconButton()->disabled(fn ($record) => $record->products_count > 0)
                    ->tooltip('გამოყენებული ჯგუფის წაშლამდე გადაიტანეთ პროდუქტები'),
            ])->defaultSort('name')->paginated([25, 50]);
    }
}
