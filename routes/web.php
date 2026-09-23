<?php

use App\Http\Controllers\PatientHistoryExportController;
use App\Http\Controllers\TreatmentEstimateExportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Preserve bookmarked panel URLs without registering another root route.
Route::get('/admin/{path?}', function (Request $request, ?string $path = null) {
    $target = url('/'.ltrim($path ?? '', '/'));

    return redirect()->to($target.($request->getQueryString() ? '?'.$request->getQueryString() : ''));
})->where('path', '.*');

Route::middleware('auth')->group(function (): void {
    Route::get('/patients/{patient}/history/pdf', [PatientHistoryExportController::class, 'pdf'])
        ->name('patients.history.pdf');
    Route::get('/patients/{patient}/history/word', [PatientHistoryExportController::class, 'word'])
        ->name('patients.history.word');
    Route::get('/patients/{patient}/treatment-estimates/{estimate}/pdf', [TreatmentEstimateExportController::class, 'pdf'])
        ->name('treatment-estimates.pdf');
    Route::get('/patients/{patient}/treatment-estimates/{estimate}/export', [TreatmentEstimateExportController::class, 'chooseLanguage'])
        ->name('treatment-estimates.export');
    Route::get('/patients/{patient}/treatment-estimates/{estimate}/word', [TreatmentEstimateExportController::class, 'word'])
        ->name('treatment-estimates.word');
});
