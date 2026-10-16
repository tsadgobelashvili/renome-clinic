<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Models\PurchaseProduct;
use Illuminate\Database\Eloquent\Builder;

class PurchaseProducts extends PurchaseProductMappings
{
    public function getTitle(): string
    {
        return __('expense-entry.catalog');
    }

    protected function productQuery(): Builder
    {
        return PurchaseProduct::query();
    }
}
