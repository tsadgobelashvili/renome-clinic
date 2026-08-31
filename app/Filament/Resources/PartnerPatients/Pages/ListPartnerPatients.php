<?php

namespace App\Filament\Resources\PartnerPatients\Pages;

use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPartnerPatients extends ListRecords
{
    protected static string $resource = PartnerPatientResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
