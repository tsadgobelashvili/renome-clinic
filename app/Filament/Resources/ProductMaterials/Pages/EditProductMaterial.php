<?php

namespace App\Filament\Resources\ProductMaterials\Pages;

use App\Filament\Resources\ProductMaterials\ProductMaterialResource;
use Filament\Resources\Pages\EditRecord;

class EditProductMaterial extends EditRecord
{
    protected static string $resource = ProductMaterialResource::class;
}
