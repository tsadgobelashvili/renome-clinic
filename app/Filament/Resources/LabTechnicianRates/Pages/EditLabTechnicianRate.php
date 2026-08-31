<?php

namespace App\Filament\Resources\LabTechnicianRates\Pages;

use App\Filament\Resources\LabTechnicianRates\LabTechnicianRateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLabTechnicianRate extends EditRecord
{
    protected static string $resource = LabTechnicianRateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
