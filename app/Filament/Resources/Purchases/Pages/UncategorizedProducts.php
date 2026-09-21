<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Models\PurchaseProduct;
use Illuminate\Database\Eloquent\Builder;

class UncategorizedProducts extends PurchaseProductMappings
{
    public function getTitle(): string
    {
        return 'უკატეგორიო';
    }

    protected function productQuery(): Builder
    {
        return PurchaseProduct::query()->whereHas('items.purchase', fn ($query) => $query->where('source', 'rs'))
            ->where(fn ($query) => $query->whereDoesntHave('direction')->orWhereDoesntHave('group'));
    }
}
