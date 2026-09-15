<?php

namespace App\Filament\Resources\LabCases\Pages;

use App\Filament\Resources\LabCases\LabCaseResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLabCase extends CreateRecord
{
    protected static string $resource = LabCaseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['created_by'] = auth()->id();

        if (($data['source'] ?? null) === 'external') {
            return \App\Services\ExternalLabCaseData::prepare($data);
        }

        return $data;
    }
}
