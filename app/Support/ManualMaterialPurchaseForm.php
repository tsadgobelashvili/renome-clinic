<?php

namespace App\Support;

use App\Services\PurchaseCatalog;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

class ManualMaterialPurchaseForm
{
    public static function schema(): array
    {
        $classified = fn (Get $get): bool => filled($get('material_product_group_id'));

        return [
            Select::make('material_product_group_id')->label(__('expense-entry.product_group'))
                ->options(fn () => app(PurchaseCatalog::class)->groupOptions())->searchable()->live()->nullable(),
            TextInput::make('material_quantity')->label(__('expense-entry.quantity'))->numeric()->minValue(0.000001)
                ->step(0.000001)->rules(['regex:/^\d+(?:\.\d{1,6})?$/'])
                ->visible($classified)->required($classified)->dehydrated($classified),
            TextInput::make('material_unit')->label(__('expense-entry.unit'))->default('pcs')->maxLength(255)
                ->visible($classified)->dehydrated($classified),
        ];
    }
}
