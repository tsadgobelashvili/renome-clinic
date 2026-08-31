<?php

namespace App\Filament\Resources\LabTechnicianRates\Pages;

use App\Filament\Resources\LabTechnicianRates\LabTechnicianRateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLabTechnicianRates extends ListRecords
{
    protected static string $resource = LabTechnicianRateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
