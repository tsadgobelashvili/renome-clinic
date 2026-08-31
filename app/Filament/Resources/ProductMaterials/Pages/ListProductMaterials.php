<?php

namespace App\Filament\Resources\ProductMaterials\Pages;

use App\Filament\Resources\ProductMaterials\ProductMaterialResource;
use Filament\Resources\Pages\ListRecords;

class ListProductMaterials extends ListRecords
{
    protected static string $resource = ProductMaterialResource::class;
}
