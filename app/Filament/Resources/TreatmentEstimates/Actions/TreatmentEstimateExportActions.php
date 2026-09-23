<?php

namespace App\Filament\Resources\TreatmentEstimates\Actions;

use App\Models\TreatmentEstimate;
use Filament\Actions\Action;
use Illuminate\Support\Facades\Gate;

class TreatmentEstimateExportActions
{
    /** @return array<Action> */
    public static function make(TreatmentEstimate $estimate): array
    {
        return collect(['pdf' => 'PDF', 'word' => 'Word'])->map(
            fn (string $label, string $format): Action => Action::make($format)
                ->label($label)
                ->visible(fn (): bool => Gate::allows('export', $estimate))
                ->url(route('treatment-estimates.export', [
                    'patient' => $estimate->patient_id,
                    'estimate' => $estimate,
                    'format' => $format,
                ]))
                ->openUrlInNewTab(),
        )->values()->all();
    }
}
