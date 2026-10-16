<?php

namespace App\Support;

use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Services\ExpenseDimensions;
use App\Services\FinanceExpenseEntry;
use App\Services\PurchaseCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

class FinanceExpenseForm
{
    public static function productForm(): array
    {
        return [
            TextInput::make('name')->label(__('expense-entry.product_name'))->required()->maxLength(255),
            Select::make('expense_direction_id')->label(__('expense-dimensions.direction'))
                ->options(fn () => app(PurchaseCatalog::class)->directionOptions())->searchable()->live()
                ->afterStateUpdated(fn (Set $set) => $set('purchase_product_group_id', null))
                ->default(fn () => app(ExpenseDimensions::class)->id('direction', 'general')),
            PurchaseGroupForm::selector(),
            Select::make('supplier_id')->label(__('expense-entry.supplier'))
                ->getSearchResultsUsing(fn (string $search) => Supplier::where('name', 'like', '%'.$search.'%')->limit(50)->pluck('name', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => Supplier::find($value)?->name)->searchable(),
            TextInput::make('supplier_product_code')->label(__('expense-entry.product_code'))->maxLength(255),
        ];
    }

    public static function schema(): array
    {
        $product = fn (Get $get): bool => app(FinanceExpenseEntry::class)->isProductPurchase($get('entry_expense_type_id'));

        return [
            Select::make('entry_expense_type_id')->label(__('expense-entry.type'))
                ->options(fn () => app(FinanceExpenseEntry::class)->typeOptions())->required()->searchable()->live()
                ->afterStateUpdated(fn (Set $set) => $set('purchase_product_id', null))
                ->createOptionForm([TextInput::make('name')->label(__('expense-entry.type'))->required()->maxLength(255)])
                ->createOptionUsing(fn (array $data) => app(FinanceExpenseEntry::class)->createType($data['name']))
                ->createOptionAction(fn (Action $action) => $action->visible(fn () => auth()->user()?->canManageOwnerModules())),
            Select::make('purchase_product_id')->label(__('expense-entry.product'))->searchable()->live()
                ->getSearchResultsUsing(fn (string $search) => PurchaseProduct::whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower(trim($search)).'%'])
                    ->orderBy('name')->limit(50)->pluck('name', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => PurchaseProduct::find($value)?->name)
                ->afterStateUpdated(function ($state, Set $set): void {
                    $product = PurchaseProduct::find($state);
                    $set('purchase_product_group_id', $product?->purchase_product_group_id);
                    $set('expense_direction_id', $product ? app(PurchaseCatalog::class)->productDirection($product) : null);
                })->visible($product)->required($product)
                ->createOptionForm(self::productForm())
                ->createOptionUsing(fn (array $data) => app(PurchaseCatalog::class)->createManual($data)->id)
                ->createOptionAction(fn (Action $action) => $action->label(__('expense-entry.add_product'))->visible(fn () => auth()->user()?->canManageOwnerModules())),
            Select::make('purchase_product_group_id')->label(__('expense-entry.product_group'))
                ->options(fn () => app(PurchaseCatalog::class)->groupOptions())->disabled()->dehydrated(false)
                ->placeholder(__('expense-entry.unclassified'))->visible($product),
            Select::make('expense_direction_id')->label(__('expense-dimensions.direction'))
                ->options(fn () => app(PurchaseCatalog::class)->directionOptions())->searchable()
                ->default(fn () => app(ExpenseDimensions::class)->id('direction', 'general'))
                ->placeholder(__('expense-entry.general')),
        ];
    }
}
