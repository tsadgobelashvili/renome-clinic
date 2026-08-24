<?php

namespace App\Filament\Resources\TreatmentEstimates\Pages;

use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewTreatmentEstimate extends ViewRecord
{
    protected static string $resource = TreatmentEstimateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label('PDF')
                ->url(fn (): string => route('treatment-estimates.pdf', $this->record))
                ->openUrlInNewTab(),
            Action::make('word')
                ->label('Word')
                ->url(fn (): string => route('treatment-estimates.word', $this->record))
                ->openUrlInNewTab(),
            EditAction::make(),
        ];
    }
}
