<?php

namespace App\Filament\Resources\PartnerPatients\Pages;

use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\PatientGroup;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditPartnerPatient extends EditRecord
{
    protected static string $resource = PartnerPatientResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['patient_group_id'] = PatientGroup::israelPartnerId();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createVisit')
                ->label('ახალი ვიზიტი')
                ->url(fn (): string => VisitResource::getUrl('create', [
                    'patient_id' => $this->record->getKey(),
                ])),
            ViewAction::make(),
        ];
    }
}
