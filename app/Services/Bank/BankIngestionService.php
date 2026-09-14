<?php

namespace App\Services\Bank;

use App\Data\BankTransactionData;
use App\Models\BankImportBatch;
use App\Models\BankTransaction;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

class BankIngestionService
{
    /** @param iterable<BankTransactionData> $transactions */
    public function ingest(iterable $transactions, string $source, ?int $batchId = null, ?string $filename = null): array
    {
        if (! in_array($source, ['import', 'api'], true)) {
            throw new DomainException('Unsupported bank data source.');
        }

        return DB::transaction(function () use ($transactions, $source, $batchId, $filename): array {
            $classifier = app(BankClassificationService::class);
            $classificationContext = $classifier->context();
            if ($batchId !== null) {
                // Serialize ingestion with rollback; an audited rollback batch cannot gain new rows.
                BankImportBatch::whereNull('rolled_back_at')->lockForUpdate()->findOrFail($batchId);
            }
            $counts = ['imported_rows' => 0, 'duplicate_rows' => 0];
            // Preload existing keys in bounded chunks; reimports do not issue a query per row.
            foreach (LazyCollection::make(fn () => yield from $transactions)->chunk(250) as $chunk) {
                $known = BankTransaction::whereIn('deduplication_key', $chunk->map(fn ($data) => $data->deduplicationKey()))->get()->keyBy('deduplication_key');
                foreach ($chunk as $data) {
                    $key = $data->deduplicationKey();
                    $record = $known->get($key) ?? BankTransaction::createOrFirst(['deduplication_key' => $key], [
                        ...$data->attributes, 'fingerprint' => $data->fingerprint(), 'source' => $source,
                        'source_file' => $filename, 'import_batch_id' => $batchId,
                        ...$classifier->classify($data->attributes, $classificationContext),
                    ]);
                    // Same bank ID with changed accounting facts must never silently replace a row.
                    foreach (['transaction_date', 'direction', 'amount', 'currency'] as $field) {
                        $actual = $field === 'transaction_date' ? $record->transaction_date->format('Y-m-d H:i:s') : $record->$field;
                        if ((string) $actual !== (string) $data->attributes[$field]) {
                            throw new DomainException(__('bank.conflicting_transaction', ['id' => $data->attributes['operation_id'] ?? $key]));
                        }
                    }
                    $counts[! $known->has($key) && $record->wasRecentlyCreated ? 'imported_rows' : 'duplicate_rows']++;
                    $known->put($key, $record);
                }
            }

            return $counts;
        });
    }
}
