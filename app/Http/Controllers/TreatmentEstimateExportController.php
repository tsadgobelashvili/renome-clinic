<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\TreatmentEstimate;
use App\Services\TreatmentEstimateExportService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TreatmentEstimateExportController extends Controller
{
    public function pdf(Patient $patient, string $estimate, TreatmentEstimateExportService $exporter): Response
    {
        return $exporter->pdf($this->resolveEstimate($patient, $estimate));
    }

    public function word(Patient $patient, string $estimate, TreatmentEstimateExportService $exporter): BinaryFileResponse
    {
        return $exporter->word($this->resolveEstimate($patient, $estimate));
    }

    private function resolveEstimate(Patient $patient, string $estimate): TreatmentEstimate
    {
        return $patient->treatmentEstimates()->findOrFail($estimate);
    }
}
