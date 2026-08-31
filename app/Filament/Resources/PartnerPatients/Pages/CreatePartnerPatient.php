<?php

namespace App\Filament\Resources\PartnerPatients\Pages;

use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
use App\Models\PatientGroup;
use Filament\Resources\Pages\CreateRecord;

class CreatePartnerPatient extends CreateRecord
{
    protected static string $resource = PartnerPatientResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['patient_group_id'] = PatientGroup::israelPartnerId();

        return $data;
    }
}
