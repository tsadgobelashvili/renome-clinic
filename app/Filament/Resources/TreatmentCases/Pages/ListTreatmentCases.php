<?php

namespace App\Filament\Resources\TreatmentCases\Pages;

use App\Filament\Resources\TreatmentCases\TreatmentCaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTreatmentCases extends ListRecords
{
    protected static string $resource = TreatmentCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
