<?php

namespace App\Filament\Resources\LabCases\Pages;

use App\Filament\Resources\LabCases\LabCaseResource;
use App\Services\LabPartyAutocomplete;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLabCase extends EditRecord
{
    protected static string $resource = LabCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()->visible(fn () => auth()->user()?->isOwner())];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['case_date'] = $this->record->case_date?->toDateString();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['source'] ?? null) !== $this->record->source) {
            abort_unless(LabCaseResource::canEdit($this->record)
                && LabCaseResource::getEloquentQuery()->whereKey($this->record->id)->exists(), 403);
        }

        $patient = app(LabPartyAutocomplete::class)->resolvePatientForLab(
            filled($data['patient_id'] ?? null) ? (int) $data['patient_id'] : null,
            $data['patient_entry'] ?? null,
            (string) ($data['source'] ?? 'clinic'),
        );
        unset($data['patient_entry']);
        $data['patient_id'] = $patient->getKey();

        return $data;
    }
}
