<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\Actions\ViewTreatmentPlansAction;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Visits\VisitResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

class ViewPatient extends ViewRecord
{
    protected static string $resource = PatientResource::class;

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
            Action::make('createVisit')->label('ახალი ვიზიტი')
                ->url(fn (): string => VisitResource::getUrl('create', ['patient_id' => $this->record->getKey()])),
            ViewTreatmentPlansAction::make($this->record),
            ActionGroup::make([
                Action::make('historyPdf')
                    ->label('PDF')
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->url(fn (): string => route('patients.history.pdf', $this->record)),
                Action::make('historyWord')
                    ->label('Word')
                    ->icon(Heroicon::OutlinedDocumentText)
                    ->url(fn (): string => route('patients.history.word', $this->record)),
            ])
                ->label('ისტორიის ექსპორტი')
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->button()
                ->color('gray'),
            EditAction::make(),
        ];
    }
}
