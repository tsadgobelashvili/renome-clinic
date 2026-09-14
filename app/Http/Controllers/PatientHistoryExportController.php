<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Services\PatientHistoryExportService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PatientHistoryExportController extends Controller
{
    public function pdf(Patient $patient, PatientHistoryExportService $exporter): Response
    {
        Gate::authorize('exportHistory', $patient);

        return $exporter->pdf($patient);
    }

    public function word(Patient $patient, PatientHistoryExportService $exporter): BinaryFileResponse
    {
        Gate::authorize('exportHistory', $patient);

        return $exporter->word($patient);
    }
}
