<?php

use App\Http\Controllers\PatientHistoryExportController;
use App\Http\Controllers\TreatmentEstimateExportController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::middleware('auth')->group(function (): void {
    Route::get('/patients/{patient}/history/pdf', [PatientHistoryExportController::class, 'pdf'])
        ->name('patients.history.pdf');
    Route::get('/patients/{patient}/history/word', [PatientHistoryExportController::class, 'word'])
        ->name('patients.history.word');
    Route::get('/treatment-estimates/{estimate}/pdf', [TreatmentEstimateExportController::class, 'pdf'])
        ->name('treatment-estimates.pdf');
    Route::get('/treatment-estimates/{estimate}/word', [TreatmentEstimateExportController::class, 'word'])
        ->name('treatment-estimates.word');
});
