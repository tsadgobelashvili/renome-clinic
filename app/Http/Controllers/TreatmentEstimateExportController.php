<?php

namespace App\Http\Controllers;

use App\Models\TreatmentEstimate;
use App\Services\TreatmentEstimateExportService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TreatmentEstimateExportController extends Controller
{
    public function pdf(TreatmentEstimate $estimate, TreatmentEstimateExportService $exporter): Response
    {
        return $exporter->pdf($estimate);
    }

    public function word(TreatmentEstimate $estimate, TreatmentEstimateExportService $exporter): BinaryFileResponse
    {
        return $exporter->word($estimate);
    }
}
