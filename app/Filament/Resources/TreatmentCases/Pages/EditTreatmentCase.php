<?php

namespace App\Filament\Resources\TreatmentCases\Pages;

use App\Filament\Resources\TreatmentCases\TreatmentCaseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTreatmentCase extends EditRecord
{
    protected static string $resource = TreatmentCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
