<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Services\PatientHistoryExportService;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PatientHistoryExportController extends Controller
{
    public function pdf(Patient $patient, PatientHistoryExportService $exporter): Response
    {
        return $exporter->pdf($patient);
    }

    public function word(Patient $patient, PatientHistoryExportService $exporter): BinaryFileResponse
    {
        return $exporter->word($patient);
    }
}
