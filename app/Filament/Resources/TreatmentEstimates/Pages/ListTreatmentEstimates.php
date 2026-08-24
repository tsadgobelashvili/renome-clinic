<?php

namespace App\Filament\Resources\TreatmentEstimates\Pages;

use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTreatmentEstimates extends ListRecords
{
    protected static string $resource = TreatmentEstimateResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('+ გეგმა')];
    }
}
