<?php

namespace App\Filament\Resources\Patients\Actions;

use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use App\Models\Patient;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

class ViewTreatmentPlansAction
{
    public static function make(Patient $patient): Action
    {
        return Action::make('treatmentPlans')
            ->label('მკურნალობის გეგმა')
            ->icon(Heroicon::OutlinedClipboardDocumentList)
            ->modal()
            ->slideOver()
            ->modalWidth(Width::FiveExtraLarge)
            ->modalHeading(fn (): string => $patient->full_name.' — მკურნალობის გეგმები')
            ->modalContent(fn (): View => view('filament.resources.patients.treatment-plans-slide-over', [
                'patient' => self::loadPlans($patient),
                'createUrl' => TreatmentEstimateResource::getUrl('create', [
                    'patient_id' => $patient->getKey(),
                ]),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('დახურვა');
    }

    private static function loadPlans(Patient $patient): Patient
    {
        return $patient->load([
            'treatmentEstimates' => fn ($query) => $query
                ->with([
                    'doctor',
                    'options' => fn ($query) => $query->orderBy('id'),
                    'options.items',
                    'options.stages',
                    'options.stages.items',
                ])
                ->orderByDesc('estimate_date')
                ->orderByDesc('id'),
        ]);
    }
}
