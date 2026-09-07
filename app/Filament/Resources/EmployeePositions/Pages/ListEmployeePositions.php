<?php

namespace App\Filament\Resources\EmployeePositions\Pages;

use App\Filament\Resources\EmployeePositions\EmployeePositionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListEmployeePositions extends ListRecords
{
    protected static string $resource = EmployeePositionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
