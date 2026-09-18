<?php

namespace App\Services;

use App\Models\BogTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class BogStatementSyncService
{
    /** @return array{inserted: int, duplicates: int, errors: array<string>} */
    public function import(array $records, string $account, string $currency): array
    {
        return $this->importNormalized($this->normalize($records, $account, $currency));
    }

    /** Persist the exact preparation also used by the Bank conflict checks. */
    public function importNormalized(array $prepared): array
    {
        ['rows' => $rows, 'errors' => $errors] = $prepared;
        $inserted = DB::transaction(function () use ($rows): int {
            $inserted = 0;
            foreach (array_chunk($rows, 100) as $chunk) {
                // The unique entry_id constraint also protects concurrent syncs. Never update duplicates.
                $inserted += BogTransaction::query()->insertOrIgnore($chunk);
            }

            return $inserted;
        });

        return ['inserted' => $inserted, 'duplicates' => count($rows) - $inserted, 'errors' => $errors];
    }

    /** Shared preparation lets the Bank ledger check fresh API facts, including duplicate entry IDs. */
    public function normalize(array $records, string $account, string $currency): array
    {
        $rows = [];
        $errors = [];
        $now = now();
        foreach ($records as $index => $record) {
            if (! is_array($record)) {
                $errors[] = 'Row '.($index + 1).': expected a transaction object.';

                continue;
            }
            // Accept casing/snake_case variants without fuzzy monetary-column matching.
            $fields = [];
            foreach ($record as $key => $value) {
                $fields[strtolower(str_replace('_', '', (string) $key))] = $value;
            }
            [$debit, $credit] = $this->amounts($fields);
            $party = $fields[$credit > 0 ? 'senderdetails' : 'beneficiarydetails'] ?? [];
            $party = is_array($party) ? $party : [];
            $row = [
                'entry_id' => is_scalar($fields['entryid'] ?? null) ? trim((string) $fields['entryid']) : null,
                'account_number' => $account,
                'currency' => $currency,
                'operation_date' => $this->date($fields['operationdate'] ?? $fields['entrydate'] ?? $fields['date'] ?? null),
                'value_date' => $this->date($fields['documentvaluedate'] ?? $fields['valuedate'] ?? null),
                'debit' => $debit,
                'credit' => $credit,
                'description' => $fields['documentnomination'] ?? $fields['entrycomment'] ?? $fields['description'] ?? $fields['purpose'] ?? null,
                'counterparty_name' => $party['name'] ?? $fields['counterpartyname'] ?? null,
                'counterparty_account' => $party['accountNumber'] ?? $fields['counterpartyaccount'] ?? null,
                'counterparty_bank' => $party['bankName'] ?? $party['bankCode'] ?? $fields['counterpartybank'] ?? null,
                'operation_type' => $fields['documentproductgroup'] ?? $fields['operationtype'] ?? null,
            ];
            $validator = Validator::make($row, [
                'entry_id' => 'required|string|max:255', 'account_number' => 'required|string|max:255',
                'currency' => 'required|string|size:3', 'operation_date' => 'required|date_format:Y-m-d',
                'value_date' => 'nullable|date_format:Y-m-d',
                'debit' => 'required|numeric|min:0|max:9999999999999999.99',
                'credit' => 'required|numeric|min:0|max:9999999999999999.99',
                'description' => 'nullable|string', 'counterparty_name' => 'nullable|string|max:255',
                'counterparty_account' => 'nullable|string|max:255', 'counterparty_bank' => 'nullable|string|max:255',
                'operation_type' => 'nullable|string|max:255',
            ]);
            if ($validator->fails() || (($debit > 0) === ($credit > 0))) {
                $errors[] = 'Row '.($index + 1).': invalid fields: '.implode(', ', $validator->fails() ? $validator->errors()->keys() : ['debit/credit direction'])
                    .'. Amount/direction fields: '.$this->amountDiagnostics($record);

                continue;
            }
            $rows[] = $row + [
                'status' => 'unreviewed', 'raw_payload' => json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => $now, 'updated_at' => $now,
            ];
        }

        return compact('rows', 'errors');
    }

    /** Prefer BOG account-currency amounts, never *Base or document conversion amounts. */
    private function amounts(array $fields): array
    {
        if (array_key_exists('entryamountdebit', $fields) || array_key_exists('entryamountcredit', $fields)) {
            $debit = $fields['entryamountdebit'] ?? null;
            $credit = $fields['entryamountcredit'] ?? null;
            if (filled($debit) || filled($credit)) {
                return [$this->decimal($debit, blankIsZero: true), $this->decimal($credit, blankIsZero: true)];
            }
        } elseif (array_key_exists('debit', $fields) || array_key_exists('credit', $fields)) {
            // Retain compatibility with records already accepted by the initial integration.
            return [$this->decimal($fields['debit'] ?? null, blankIsZero: true), $this->decimal($fields['credit'] ?? null, blankIsZero: true)];
        }

        // Actual BOG entryAmount is signed: negative = debit, positive = credit.
        $amount = $this->decimal($fields['entryamount'] ?? null);

        return $amount === null ? [null, null] : [max(-$amount, 0), max($amount, 0)];
    }

    private function decimal(mixed $value, bool $blankIsZero = false): ?float
    {
        if (blank($value)) {
            return $blankIsZero ? 0.0 : null;
        }

        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private function amountDiagnostics(array $record): string
    {
        $details = [];
        foreach ($record as $key => $value) {
            $normalized = strtolower(str_replace('_', '', (string) $key));
            if (! in_array($normalized, ['entryamountdebit', 'entryamountcredit', 'entryamount', 'entryamountbase',
                'entryamountdebitbase', 'entryamountcreditbase', 'documentsourceamount', 'documentdestinationamount',
                'debit', 'credit', 'amount', 'direction', 'type', 'operationtype', 'documentproductgroup'], true)) {
                continue;
            }
            // Never echo arbitrary API strings, credentials, token fields, or personal details.
            $details[$key] = $value === null ? null : ($this->decimal($value) ?? (
                is_string($value) && in_array($value, ['TRN', 'COM', 'PMD', 'PBS', 'FEE', 'debit', 'credit', 'inflow', 'outflow', 'D', 'C'], true)
                    ? $value : '[invalid '.get_debug_type($value).']'
            ));
        }

        return json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function date(mixed $value): mixed
    {
        if ($value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}(?:T|\s)/', $value)) {
            return substr($value, 0, 10);
        }

        return $value;
    }
}
