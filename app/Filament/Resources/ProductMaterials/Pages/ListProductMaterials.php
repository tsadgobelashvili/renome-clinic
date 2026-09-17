<?php

namespace App\Filament\Resources\ProductMaterials\Pages;

use App\Filament\Resources\ProductMaterials\ProductMaterialResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;

class ListProductMaterials extends ListRecords
{
    protected static string $resource = ProductMaterialResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->mutateDataUsing(fn (array $data) => [...$data, 'catalog_status' => 'sellable'])];
    }

    public function getTabs(): array
    {
        return [
            'sellable' => Tab::make('პროდუქტები')->modifyQueryUsing(fn ($query) => $query->where('catalog_status', 'sellable')),
            'review' => Tab::make('ძველი ჩანაწერები — გადასამოწმებელი')->modifyQueryUsing(fn ($query) => $query->where('catalog_status', 'review')),
        ];
    }
}
