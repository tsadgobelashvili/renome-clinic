<?php

namespace App\Filament\Resources\TreatmentEstimates\Pages;

use App\Filament\Resources\TreatmentEstimates\Actions\TreatmentEstimateExportActions;
use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTreatmentEstimate extends ViewRecord
{
    protected static string $resource = TreatmentEstimateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...TreatmentEstimateExportActions::make($this->record),
            EditAction::make(),
        ];
    }
}
