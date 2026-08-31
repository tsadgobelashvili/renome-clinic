<?php

namespace App\Filament\Resources\PartnerPatients\Pages;

use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
use App\Filament\Resources\Visits\VisitResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewPartnerPatient extends ViewRecord
{
    protected static string $resource = PartnerPatientResource::class;

    public function getTitle(): string
    {
        return $this->record->full_name;
    }

    public function getBreadcrumbs(): array
    {
        return [];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createVisit')
                ->label('+ ახალი ვიზიტი')
                ->url(fn (): string => VisitResource::getUrl('create', [
                    'patient_id' => $this->record->getKey(),
                ])),
            EditAction::make(),
        ];
    }
}
