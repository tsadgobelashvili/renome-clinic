<?php

namespace App\Filament\Resources\TreatmentEstimates\Pages;

use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditTreatmentEstimate extends EditRecord
{
    protected static string $resource = TreatmentEstimateResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewAction::make(), DeleteAction::make()];
    }
}
