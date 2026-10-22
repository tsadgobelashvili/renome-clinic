<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Models\RsImportCheckpoint;
use App\Services\RsImportReport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

class RsImportReportController
{
    public function __invoke(string $report, RsImportReport $reports)
    {
        Gate::authorize('create', Purchase::class);
        abort_unless(preg_match('/\A[0-9a-f-]{36}\z/', $report), 404);
        $path = $reports->path($report);
        $checkpoint = RsImportCheckpoint::where('error_report_path', $path)->first();
        abort_if($checkpoint && $checkpoint->owner_id !== auth()->id(), 403);
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, 'rs-import-errors-'.$report.'.jsonl', ['Content-Type' => 'application/x-ndjson', 'Cache-Control' => 'private, no-store']);
    }
}
