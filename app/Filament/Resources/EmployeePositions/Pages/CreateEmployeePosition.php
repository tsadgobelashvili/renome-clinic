<?php

namespace App\Filament\Resources\EmployeePositions\Pages;

use App\Filament\Resources\EmployeePositions\EmployeePositionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeePosition extends CreateRecord
{
    protected static string $resource = EmployeePositionResource::class;
}
