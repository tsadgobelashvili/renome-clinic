<?php

namespace App\Filament\Resources\EmployeePositions\Pages;

use App\Filament\Resources\EmployeePositions\EmployeePositionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditEmployeePosition extends EditRecord
{
    protected static string $resource = EmployeePositionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->disabled(fn (): bool => $this->record->employees()->exists())];
    }
}
