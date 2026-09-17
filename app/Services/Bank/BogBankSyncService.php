<?php

namespace App\Services\Bank;

use App\Data\BankTransactionData;
use App\Models\BogTransaction;
use App\Services\BogBusinessApiService;
use App\Services\BogStatementSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Publishes API staging records through the same ledger/classifier as Excel imports. */
class BogBankSyncService
{
    public function lastSuccessfulSync(): ?CarbonImmutable
    {
        $value = DB::table('bog_sync_states')->where($this->accountKey())->value('last_successful_sync_at');

        return $value ? CarbonImmutable::parse($value, config('app.timezone')) : null;
    }

    private function accountKey(): array
    {
        return ['account_number' => BogAccountIdentifier::normalize(config('services.bog.account_number')) ?? '',
            'currency' => strtoupper((string) config('services.bog.account_currency', 'GEL'))];
    }

    public function sync(): array
    {
        // Capture the covered cutoff before the request, not a later completion time.
        $startedAt = CarbonImmutable::now(config('app.timezone'));
        $lastSync = $this->lastSuccessfulSync();
        $from = $lastSync ? $lastSync->subDay()->startOfDay() : $startedAt->startOfDay()->subDays(6);
        $records = app(BogBusinessApiService::class)->statement($from->toDateString(), $startedAt->toDateString(), false, true)['records'];
        $account = config('services.bog.account_number');
        $currency = strtoupper(config('services.bog.account_currency'));

        return DB::transaction(function () use ($records, $account, $currency, $startedAt): array {
            $importer = app(BogStatementSyncService::class);
            $staged = $importer->import($records, $account, $currency);
            $prepared = $importer->normalize($records, $account, $currency)['rows'];
            // Also publish older CLI imports. Repeated runs never overwrite classification or raw data.
            $rows = function () use ($prepared, $account, $currency) {
                foreach ($prepared as $row) {
                    yield $this->toBankData(new BogTransaction([...$row, 'raw_payload' => json_decode($row['raw_payload'], true, flags: JSON_THROW_ON_ERROR)]));
                }
                foreach (BogTransaction::where('account_number', $account)->where('currency', $currency)
                    ->whereNotIn('entry_id', array_column($prepared, 'entry_id'))->lazyById(250) as $row) {
                    yield $this->toBankData($row);
                }
            };
            $published = app(BankIngestionService::class)->ingest($rows(), 'api');

            if ($staged['errors'] === []) {
                $key = $this->accountKey();
                DB::table('bog_sync_states')->insertOrIgnore([...$key, 'last_successful_sync_at' => null]);
                $state = DB::table('bog_sync_states')->where($key)->lockForUpdate()->first();
                // Concurrent successful requests must never move the checkpoint backwards.
                if ($state->last_successful_sync_at === null || $state->last_successful_sync_at < $startedAt->toDateTimeString()) {
                    DB::table('bog_sync_states')->where($key)->update(['last_successful_sync_at' => $startedAt->toDateTimeString()]);
                }
            }

            return ['fetched' => count($records), 'inserted' => $published['imported_rows'],
                'duplicates' => $published['duplicate_rows'], 'errors' => $staged['errors']];
        });
    }

    public function toBankData(BogTransaction $row): BankTransactionData
    {
        return new BankTransactionData([
            'account_identifier' => strtoupper(preg_replace('/\s+/u', '', $row->account_number)),
            // Verified against the original Excel operation IDs; documentKey is NOT this identifier.
            'operation_id' => $row->entry_id,
            'transaction_date' => $row->operation_date->format('Y-m-d H:i:s'),
            'value_date' => $row->value_date?->toDateString(),
            'direction' => (float) $row->credit > 0 ? 'inflow' : 'outflow',
            'amount' => (float) $row->credit > 0 ? $row->credit : $row->debit,
            'currency' => $row->currency, 'operation_type' => $row->operation_type,
            'counterparty_name' => $row->counterparty_name, 'counterparty_account' => $row->counterparty_account,
            'description' => $row->description, 'raw_data' => $row->raw_payload,
        ]);
    }
}
