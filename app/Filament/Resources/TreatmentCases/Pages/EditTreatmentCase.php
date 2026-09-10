<?php

namespace App\Filament\Resources\TreatmentCases\Pages;

use App\Filament\Resources\TreatmentCases\TreatmentCaseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTreatmentCase extends EditRecord
{
    protected static string $resource = TreatmentCaseResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (data_get($this->form->getRawState(), 'statistics_group_mode') === 'direct') {
            $data['statistics_group'] = null;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
