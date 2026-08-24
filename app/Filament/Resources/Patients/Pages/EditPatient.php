<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\Actions\ViewTreatmentPlansAction;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditPatient extends EditRecord
{
    protected static string $resource = PatientResource::class;

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
            DeleteAction::make()
                ->disabled(fn (Patient $record): bool => $record->visits()->exists() || $record->treatmentEstimates()->exists())
                ->tooltip(fn (Patient $record): ?string => $record->visits()->exists() || $record->treatmentEstimates()->exists()
                    ? 'პაციენტის წაშლა შეუძლებელია, რადგან მას ვიზიტების ან მკურნალობის გეგმების ისტორია აქვს.'
                    : null),
        ];
    }
}
