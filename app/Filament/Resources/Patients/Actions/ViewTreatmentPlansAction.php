<?php

namespace App\Filament\Resources\Patients\Actions;

use App\Filament\Resources\TreatmentEstimates\Actions\CreateTreatmentEstimateAction;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class ViewTreatmentPlansAction
{
    public static function make(Patient $patient): Action
    {
        return CreateTreatmentEstimateAction::make(
            patientId: $patient->getKey(),
            initialMode: 'list',
            name: 'treatmentPlans',
        )
            ->label('მკურნალობის გეგმა')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->slideOver()
            ->modalWidth(Width::FiveExtraLarge)
            ->modalHeading(fn (): string => $patient->full_name.' — მკურნალობის გეგმები')
            ->modalCancelActionLabel('დახურვა');
    }
}
