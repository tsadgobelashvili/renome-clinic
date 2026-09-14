<?php

namespace App\Services\Bank;

use App\Models\BankImportBatch;
use Illuminate\Support\Facades\DB;

class BankStatementImportService
{
    public function __construct(private BogStatementParser $parser, private BankIngestionService $ingestion) {}

    public function import(string $path, string $filename, ?int $userId = null, ?string $storedPath = null): BankImportBatch
    {
        // Recognize and normalize before writing anything; database failures roll back the whole batch.
        $parsed = $this->parser->parse($path);

        return DB::transaction(function () use ($path, $filename, $userId, $storedPath, $parsed): BankImportBatch {
            $batch = BankImportBatch::create([
                ...$parsed['metadata'], 'bank' => 'BOG', 'source_file' => mb_substr(basename($filename), 0, 255),
                'stored_path' => $storedPath, 'file_hash' => hash_file('sha256', $path),
                'imported_by' => $userId, 'imported_at' => now(),
                'rejected_rows' => count($parsed['errors']), 'errors' => $parsed['errors'],
            ]);
            $batch->update($this->ingestion->ingest($parsed['transactions'], 'import', $batch->id, $batch->source_file));

            return $batch;
        });
    }
}
