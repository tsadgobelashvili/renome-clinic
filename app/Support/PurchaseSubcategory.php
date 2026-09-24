<?php

namespace App\Support;

use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Services\PurchaseCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\SelectColumn;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** Shared inline classification controls for RS lines and product mappings. */
class PurchaseSubcategory
{
    private static function product(PurchaseItem|PurchaseProduct $record): ?PurchaseProduct
    {
        return $record instanceof PurchaseItem ? $record->purchaseProduct : $record;
    }

    public static function column(): SelectColumn
    {
        return SelectColumn::make('subcategory')->label('ქვეკატეგორია')->placeholder('დასაზუსტებელია')
            ->state(fn ($record) => self::product($record)?->expense_type_id)
            ->options(fn ($record) => app(PurchaseCatalog::class)->subcategoryOptions(self::product($record)?->expense_direction_id, self::product($record)?->expense_type_id))
            ->disabled(fn ($record) => ! self::product($record)?->expense_direction_id || Gate::denies('update', self::product($record)))
            ->updateStateUsing(function ($record, $state) {
                $product = self::product($record);
                Gate::authorize('update', $product);
                app(PurchaseCatalog::class)->assignClassification($product, $product->expense_direction_id, filled($state) ? (int) $state : null);

                return $state;
            });
    }

    public static function createAction(): Action
    {
        return Action::make('createSubcategory')->label('ქვეკატეგორიის დამატება')->icon('heroicon-m-plus')->iconButton()
            ->tooltip('ქვეკატეგორიის დამატება')
            ->visible(fn ($record) => self::product($record)?->expense_direction_id && Gate::allows('update', self::product($record)))
            ->schema([TextInput::make('name')->label('დასახელება')->required()->maxLength(255)])
            ->action(function ($record, array $data): void {
                $product = self::product($record);
                Gate::authorize('update', $product);
                DB::transaction(function () use ($product, $data): void {
                    $catalog = app(PurchaseCatalog::class);
                    $id = $catalog->createSubcategory((int) $product->expense_direction_id, $data['name']);
                    $catalog->assignClassification($product, $product->expense_direction_id, $id);
                });
            });
    }
}
