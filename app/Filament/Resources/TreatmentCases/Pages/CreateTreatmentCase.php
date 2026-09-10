<?php

namespace App\Filament\Resources\TreatmentCases\Pages;

use App\Filament\Resources\TreatmentCases\TreatmentCaseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTreatmentCase extends CreateRecord
{
    protected static string $resource = TreatmentCaseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (data_get($this->form->getRawState(), 'statistics_group_mode') === 'direct') {
            $data['statistics_group'] = null;
        }

        return $data;
    }
}
