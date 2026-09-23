<?php

namespace App\Http\Controllers;

use App\Models\Patient;
use App\Models\TreatmentEstimate;
use App\Services\TreatmentEstimateExportService;
use App\Support\TreatmentPlanDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TreatmentEstimateExportController extends Controller
{
    public function chooseLanguage(Patient $patient, string $estimate, Request $request): View
    {
        return view('exports.treatment-estimate-language', [
            'estimate' => $this->resolveEstimate($patient, $estimate),
            'languages' => TreatmentPlanDocument::LANGUAGES,
            'language' => $request->session()->get('treatment_plan_export_language', 'ka'),
            'formats' => $request->query('format') === 'word'
                ? ['word' => 'Word', 'pdf' => 'PDF']
                : ['pdf' => 'PDF', 'word' => 'Word'],
        ]);
    }

    public function pdf(Patient $patient, string $estimate, TreatmentEstimateExportService $exporter, Request $request): Response
    {
        $record = $this->resolveEstimate($patient, $estimate);

        return $exporter->pdf($record, $this->language($request));
    }

    public function word(Patient $patient, string $estimate, TreatmentEstimateExportService $exporter, Request $request): BinaryFileResponse
    {
        $record = $this->resolveEstimate($patient, $estimate);

        return $exporter->word($record, $this->language($request));
    }

    private function language(Request $request): string
    {
        $data = $request->validate(['language' => ['sometimes', 'required', 'string', 'in:ka,en,ru']]);
        $language = $data['language'] ?? $request->session()->get('treatment_plan_export_language', 'ka');
        $request->session()->put('treatment_plan_export_language', $language);

        return $language;
    }

    private function resolveEstimate(Patient $patient, string $estimate): TreatmentEstimate
    {
        Gate::authorize('view', $patient);

        $record = $patient->treatmentEstimates()->findOrFail($estimate);
        Gate::authorize('export', $record);

        return $record;
    }
}
