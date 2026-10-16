<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use DateTimeInterface;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\Common\Creator\ReaderFactory;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use Throwable;

class PurchaseImportService
{
    private array $touchedPurchases = [];

    private array $sourceErrors = [];

    private array $unscopedErrors = [];

    private const HEADERS = [
        'date' => ['date', 'document date', 'invoice date', 'თარიღი', 'გააქტიურების თარიღი'],
        'supplier' => ['supplier', 'supplier name', 'seller', 'მომწოდებელი', 'გამყიდველი'],
        'product' => ['product', 'product name', 'goods', 'item', 'საქონელი', 'დასახელება', 'პროდუქტი', 'საქონლის დასახელება'],
        'quantity' => ['quantity', 'qty', 'რაოდენობა', 'რაოდ.'],
        'unit' => ['unit', 'measurement', 'ერთეული', 'ზომის ერთეული'],
        'unit_price' => ['unit price', 'price', 'ერთეულის ფასი', 'ფასი'],
        'total' => ['total amount', 'total', 'amount', 'ჯამი', 'თანხა', 'საქონლის ფასი'],
        'document_total' => ['document total', 'invoice total', 'rs document total', 'დოკუმენტის სრული თანხა', 'ზედნადების თანხა'],
        'vat' => ['vat', 'tax', 'vat amount', 'დღგ'],
        'tax_treatment' => ['დაბეგვრა', 'tax treatment'],
        'document' => ['invoice', 'invoice number', 'document number', 'document', 'ზედნადები', 'დოკუმენტი', 'ზედნადების ნომერი'],
        'document_id' => ['waybill id', 'rs document id', 'ზედნადების id'],
        'line_id' => ['source line id', 'line id', 'document line id', 'item sequence', 'document line ordinal', 'სტრიქონის id', 'სტრიქონის ნომერი'],
        'rs_code' => ['rs product code', 'rs item code', 'rs code', 'საქონლის კოდი'],
        'supplier_code' => ['supplier item code', 'supplier product code', 'product code', 'item code', 'მომწოდებლის კოდი'],
    ];

    public function __construct(private readonly PurchaseCatalog $catalog) {}

    /** @return array{documents_imported: int, imported: int, skipped: int, needs_review: int, failed_rows: int, errors: array<int, string>} */
    public function import(string $path, ?int $createdBy = null): array
    {
        $this->touchedPurchases = [];
        $this->sourceErrors = $this->unscopedErrors = [];
        $summary = ['documents_imported' => 0, 'imported' => 0, 'skipped' => 0, 'needs_review' => 0, 'failed_rows' => 0, 'errors' => []];
        $batchId = (string) Str::uuid();
        $pending = [];
        foreach ($this->sourceRows($path) as $input) {
            if (isset($input['error'])) {
                $summary['failed_rows'] += (int) $input['failed_row'];
                $summary['errors'][] = $input['error'];
                $this->recordSourceError($input['data'], $input['error']);

                continue;
            }
            if ($pending !== [] && Supplier::normalizeName($pending[0]['data']['supplier']) !== Supplier::normalizeName($input['data']['supplier'])) {
                $this->importBatch($pending, $summary, $createdBy, $batchId);
                $pending = [];
            }
            $pending[] = $input;
            if (count($pending) >= 100) {
                $this->importBatch($pending, $summary, $createdBy, $batchId);
                $pending = [];
            }
        }
        if ($pending !== []) {
            $this->importBatch($pending, $summary, $createdBy, $batchId);
        }

        foreach (array_keys($this->touchedPurchases) as $id) {
            try {
                DB::transaction(function () use ($id): void {
                    $purchase = Purchase::lockForUpdate()->findOrFail($id);
                    if (! $this->hasAccountingHistory($purchase)) {
                        $key = json_encode([$purchase->supplier->normalized_name, $purchase->source_document_id]);
                        $inputErrors = [...$this->unscopedErrors, ...($this->sourceErrors[$key] ?? [])];
                        $payload = $purchase->rs_import_payload;
                        $payload['validation_errors'] = $inputErrors;
                        $payload['validated'] = $inputErrors === [];
                        $purchase->forceFill(['rs_import_payload' => $payload])->saveQuietly();
                        if ($inputErrors === []) {
                            app(PurchaseReconciliation::class)->assertValid($purchase);
                        }
                    }
                });
            } catch (ValidationException $exception) {
                $summary['failed_rows']++;
                $summary['errors'][] = 'RS document #'.$id.': '.$exception->getMessage();
                DB::transaction(function () use ($id, $exception): void {
                    $purchase = Purchase::lockForUpdate()->findOrFail($id);
                    if (! $this->hasAccountingHistory($purchase)) {
                        $payload = $purchase->rs_import_payload;
                        $payload['validated'] = false;
                        $payload['validation_errors'] = array_merge(...array_values($exception->errors()));
                        $purchase->forceFill(['rs_import_payload' => $payload])->saveQuietly();
                    }
                });
            }
        }

        return $summary;
    }

    /** Shared streaming parser; parsing and recovery previews never write to the database. */
    public function sourceRows(string $path): \Generator
    {
        $fileHash = hash_file('sha256', $path);
        $detectedHeaders = false;
        $hasRows = false;
        $occurrences = [];
        $reader = $this->reader($path);
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                $rawHeaders = [];
                $fragment = null;
                $rowNumber = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    $values = $row->toArray();
                    if ($headers === null) {
                        try {
                            $headers = $this->headerMap($values);
                        } catch (\DomainException $exception) {
                            yield ['error' => $exception->getMessage(), 'data' => null, 'failed_row' => false];

                            return;
                        }
                        if (! isset($headers['product'], $headers['supplier'])) {
                            $headers = null;
                        } else {
                            $detectedHeaders = true;
                            $rawHeaders = $values;
                        }

                        continue;
                    }

                    $data = null;
                    try {
                        $rawRows = [$values];
                        // This RS CSV export can emit an unquoted newline immediately before
                        // the description's closing delimiter. Join only the unambiguous
                        // two-cell code/description prefix + empty continuation cell shape.
                        if ($fragment !== null) {
                            if (count($values) !== count($rawHeaders) - 1 || ($values[0] ?? null) !== '') {
                                $fragment = null;
                                throw new \DomainException('Malformed RS multiline row. Export a correctly quoted CSV or Excel file.');
                            }
                            $rawRows = [$fragment, $values];
                            $values = [$fragment[0], $fragment[1]."\n".$values[0], ...array_slice($values, 1)];
                            $fragment = null;
                        } elseif (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv' && count($values) === 2
                            && ($headers['rs_code'] ?? null) === 0 && $headers['product'] === 1 && filled($values[0]) && filled($values[1])) {
                            $fragment = $values;

                            continue;
                        }
                        $data = $this->rowData($values, $headers);
                        if (blank($data['product']) && blank($data['supplier'])) {
                            continue;
                        }
                        if (blank($data['product']) || blank($data['supplier'])) {
                            throw new \DomainException('Supplier and product are required.');
                        }
                        $this->validateAmounts($data);
                        $sourceCell = fn ($value) => $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : $value;
                        $data['audit'] = ['file_hash' => $fileHash, 'sheet' => $sheet->getName(), 'row' => $rowNumber,
                            'headers' => $rawHeaders, 'cells' => array_map($sourceCell, $values),
                            'original_rows' => array_map(fn ($row) => array_map($sourceCell, $row), $rawRows)];
                        $data['source_id'] = $this->sourceId($data, $fileHash);
                        $signature = $this->contentSignature($data);
                        $scope = json_encode([Supplier::normalizeName($data['supplier']), $data['source_id']], JSON_THROW_ON_ERROR);
                        $occurrenceKey = hash('sha256', $scope.$signature);
                        // Assign before persistence: failures, savepoints and batch boundaries must
                        // not shift later identical lines onto an earlier line's identity.
                        $occurrence = $occurrences[$occurrenceKey] = ($occurrences[$occurrenceKey] ?? 0) + 1;
                        $data['occurrence'] = $occurrence;
                        $line = filled($data['line_id'])
                            ? ['id', trim((string) $data['line_id'])]
                            : ['occurrence', $signature, $occurrence];
                        // A version prefix distinguishes historical content-only hashes without
                        // rewriting audit identities or changing the existing unique constraint.
                        $data['line_hash'] = 'v2:'.substr(hash('sha256', $scope.json_encode($line, JSON_THROW_ON_ERROR)), 0, 61);

                    } catch (Throwable $exception) {
                        $message = "Row {$rowNumber}: {$exception->getMessage()}";
                        $hasRows = true;
                        yield ['error' => $message, 'data' => $data, 'failed_row' => true,
                            'ambiguous' => $data === null || count($values) !== count($rawHeaders)];

                        continue;
                    }
                    $hasRows = true;
                    yield ['data' => $data, 'row' => $rowNumber];
                }
                if ($fragment !== null) {
                    $hasRows = true;
                    yield ['error' => 'Incomplete RS multiline row at end of sheet. No amount was inferred.', 'data' => null, 'failed_row' => true];
                }
            }
        } finally {
            $reader->close();
        }

        if (! $detectedHeaders) {
            yield ['error' => 'RS სათაურები ვერ მოიძებნა: საჭიროა საქონლის დასახელება და გამყიდველი/მომწოდებელი. ატვირთეთ საქონლის დეტალური ექსპორტი.', 'data' => null, 'failed_row' => false];
        } elseif (! $hasRows) {
            yield ['error' => 'ფაილში იმპორტისთვის ვარგისი საქონლის სტრიქონები არ არის.', 'data' => null, 'failed_row' => false];
        }
    }

    /** Parse one explicitly selected document without persisting a staging import. */
    public function recoverySource(string $path, string $documentNumber): array
    {
        $rows = $errors = [];
        foreach ($this->sourceRows($path) as $input) {
            $data = $input['data'];
            if (isset($input['error'])) {
                $id = $data['document'] ?? $data['document_id'] ?? null;
                if (! empty($input['ambiguous']) || blank($id) || trim((string) $id) === trim($documentNumber)
                    || in_array(trim((string) $id), ['აქტიური', 'დასრულებული', 'გაუქმებული'], true)) {
                    throw ValidationException::withMessages(['file' => $input['error']]);
                }
                $errors[] = $input['error'];

                continue;
            }
            if (trim((string) $data['document']) !== trim($documentNumber)) {
                continue;
            }
            if (blank($data['total']) || blank($data['unit_price'])) {
                throw ValidationException::withMessages(['file' => 'Recovery requires source unit prices and authoritative RS line amounts.']);
            }
            $data['mapped'] = [...$this->lineValues($data), 'item_name' => trim($data['product'])];
            try {
                $data['parsed_date'] = $this->date($data['date']);
            } catch (Throwable $exception) {
                throw ValidationException::withMessages(['file' => $exception->getMessage()]);
            }
            if (isset($rows[$data['line_hash']])) {
                throw ValidationException::withMessages(['file' => 'Ambiguous duplicate RS source line ID.']);
            }
            $rows[$data['line_hash']] = $data;
        }
        if ($rows === []) {
            throw ValidationException::withMessages(['document_number' => 'RS document not found in the supplied source file.']);
        }
        $first = reset($rows);
        $total = 0;
        $declared = null;
        foreach ($rows as $data) {
            if ($data['source_id'] !== $first['source_id'] || Supplier::normalizeName($data['supplier']) !== Supplier::normalizeName($first['supplier'])
                || $data['parsed_date'] !== $first['parsed_date']) {
                throw ValidationException::withMessages(['file' => 'Ambiguous RS document: supplier, date or source identity differs between lines.']);
            }
            $total += Money::minorUnits($data['mapped']['line_total']);
            if (filled($data['document_total'])) {
                $amount = Money::minorUnits($this->number($data['document_total']));
                if ($declared !== null && $declared !== $amount) {
                    throw ValidationException::withMessages(['file' => 'Conflicting authoritative RS document totals.']);
                }
                $declared = $amount;
            }
        }
        if ($total <= 0 || ($declared !== null && abs($total - $declared) > 1)) {
            throw ValidationException::withMessages(['file' => 'RS line amounts do not reconcile with the document total (tolerance 0.01 GEL).']);
        }

        return ['document_number' => trim($documentNumber), 'source_id' => $first['source_id'], 'supplier' => trim($first['supplier']),
            'date' => $first['parsed_date'], 'total' => number_format($total / 100, 2, '.', ''),
            'document_total' => $declared === null ? null : number_format($declared / 100, 2, '.', ''),
            'file_hash' => hash_file('sha256', $path), 'rows' => $rows, 'other_document_errors' => $errors];
    }

    /** Bounded transaction: retain row savepoints, locks and model events; publish totals before commit. */
    private function importBatch(array $rows, array &$summary, ?int $createdBy, string $batchId): void
    {
        $results = DB::transaction(function () use ($rows, $createdBy, $batchId): array {
            $suppliers = $products = $purchases = $affected = $results = [];
            foreach ($rows as $input) {
                $data = $input['data'];
                try {
                    $results[] = DB::transaction(function () use ($data, $createdBy, $batchId, &$suppliers, &$products, &$purchases, &$affected): array {
                        $supplierKey = Supplier::normalizeName($data['supplier']);
                        if (! isset($suppliers[$supplierKey])) {
                            $supplier = $this->supplier($data['supplier']);
                            $suppliers[$supplierKey] = Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                        }
                        $supplier = $suppliers[$supplierKey];
                        // Reuse only inside the transaction holding the supplier lock.
                        $productKey = json_encode([$supplier->id, trim($data['product']), $data['rs_code'], $data['supplier_code']], JSON_THROW_ON_ERROR);
                        $product = $products[$productKey] ??= $this->catalog->resolve($supplier->id, $data['product'], $data['rs_code'], $data['supplier_code']);
                        $hash = $data['line_hash'];
                        $oldHash = $this->rowHash($data, $supplier);
                        $legacyHash = $this->rowHash($data, $supplier, includeCode: false);
                        $existing = PurchaseItem::query()->where('source_row_hash', $hash)
                            ->orWhere('source_row_hash', $oldHash)
                            ->orWhere(fn ($query) => $query->whereNotNull('product_id')->where('source_row_hash', $legacyHash))
                            ->get();
                        if ($identified = $existing->firstWhere('source_row_hash', $hash)) {
                            if (filled($data['line_id']) && ! $this->sameLine($identified, $data, $product->id)) {
                                throw new \DomainException('RS source line ID already exists with different values. Review the document; no existing line was overwritten.');
                            }
                            $this->retainSource($identified->purchase, $identified, $data);

                            return ['skipped' => true];
                        }
                        $date = $this->date($data['date']);
                        $document = filled($data['document']) ? trim((string) $data['document']) : null;
                        $sourceId = $data['source_id'];
                        $purchaseKey = json_encode([$supplier->id, $sourceId], JSON_THROW_ON_ERROR);
                        $created = false;
                        // Legacy imports may predate source_document_id. Reuse their document,
                        // and count each historical line only once instead of skipping all copies.
                        if ($existing->pluck('purchase_id')->unique()->count() > 1) {
                            throw new \DomainException('RS line matches multiple historical documents. Review the ambiguous import.');
                        }
                        if ($existing->isNotEmpty()) {
                            $purchases[$purchaseKey] ??= $existing->first()->purchase;
                        }
                        if (! isset($purchases[$purchaseKey])) {
                            $purchases[$purchaseKey] = Purchase::query()->firstOrCreate([
                                'source' => 'rs', 'supplier_id' => $supplier->id, 'source_document_id' => $sourceId,
                            ], [
                                'purchase_date' => $date, 'document_number' => $document,
                                'import_batch_id' => $batchId, 'created_by' => $createdBy,
                            ]);
                            $created = $purchases[$purchaseKey]->wasRecentlyCreated;
                        }
                        $purchase = Purchase::lockForUpdate()->findOrFail($purchases[$purchaseKey]->id);
                        $purchases[$purchaseKey] = $purchase;
                        if ($purchase->cashPostings()->exists() || $purchase->bankTransactions()->exists() || $purchase->advanceSettlement()->exists()) {
                            throw new \DomainException('Confirmed RS documents cannot be changed by import.');
                        }
                        $quantity = max($this->number($data['quantity'], 1), 0.001);
                        $unitPrice = max($this->number($data['unit_price']), 0);
                        $total = $this->number($data['total'], round($quantity * $unitPrice, 2));
                        $unit = filled($data['unit']) ? trim((string) $data['unit']) : null;
                        $vat = filled($data['vat']) ? max($this->number($data['vat']), 0) : null;
                        $legacyItems = $purchase->wasRecentlyCreated ? collect() : $purchase->items()
                            ->whereNotNull('source_row_hash')->where('source_row_hash', 'not like', 'v2:%')
                            ->where(fn ($query) => $query->whereIn('id', $existing->modelKeys())
                                ->orWhere(fn ($query) => $query->where('purchase_product_id', $product->id)
                                    ->where('quantity', number_format($quantity, 3, '.', ''))
                                    ->where('unit', $unit)
                                    ->where('unit_price', number_format($unitPrice, 2, '.', ''))
                                    ->where('line_total', number_format($total, 2, '.', ''))
                                    ->where('vat_amount', $vat === null ? null : number_format($vat, 2, '.', ''))))
                            ->orderBy('id')->get();
                        $legacyCount = $legacyItems->count();
                        if ($legacyCount > 0 && filled($data['line_id'])) {
                            throw new \DomainException('Cannot safely associate source line IDs with historical content-only RS lines. Review the document before importing this format.');
                        }
                        if ($data['occurrence'] <= $legacyCount) {
                            $this->retainSource($purchase, $legacyItems[$data['occurrence'] - 1], $data);

                            return ['skipped' => true];
                        }
                        $item = $purchase->items()->make([
                            'purchase_product_id' => $product->id, 'item_name' => trim($data['product']),
                            'quantity' => $quantity, 'unit' => $unit,
                            'unit_price' => $unitPrice, 'line_total' => $total,
                            'vat_amount' => $vat,
                            'source_row_hash' => $hash,
                        ]);
                        $item->setRelation('purchase', $purchase)->setRelation('purchaseProduct', $product);
                        $item->saveWithDeferredTotal();
                        $this->retainSource($purchase, $item, $data);
                        $affected[$purchase->id] = $purchase;

                        return ['skipped' => false, 'document_created' => $created, 'needs_review' => $product->expense_direction_id === null];
                    });
                } catch (Throwable $exception) {
                    if ($exception instanceof DeadlockException
                        || app(ConcurrencyErrorDetector::class)->causedByConcurrencyError($exception)) {
                        throw $exception;
                    }
                    // Never reuse objects created by a rolled-back row/savepoint.
                    $suppliers = $products = $purchases = [];
                    $results[] = ['error' => "Row {$input['row']}: {$exception->getMessage()}"];
                    $this->recordSourceError($data, "Row {$input['row']}: {$exception->getMessage()}");
                }
            }
            foreach ($affected as $purchase) {
                $purchase->refreshTotal();
            }

            return $results;
        }, attempts: 3);
        foreach ($results as $result) {
            if (isset($result['error'])) {
                $summary['failed_rows']++;
                $summary['errors'][] = $result['error'];
            } elseif ($result['skipped']) {
                $summary['skipped']++;
            } else {
                $summary['imported']++;
                $summary['documents_imported'] += (int) $result['document_created'];
                $summary['needs_review'] += (int) $result['needs_review'];
            }
        }
    }

    private function supplier(string $name): Supplier
    {
        $normalized = Supplier::normalizeName($name);

        return Supplier::query()->where('normalized_name', $normalized)->first()
            ?? Supplier::create(['name' => trim($name), 'normalized_name' => $normalized]);
    }

    private function validateAmounts(array $data): void
    {
        foreach (['quantity', 'unit_price', 'total', 'vat', 'document_total'] as $field) {
            if (blank($data[$field])) {
                continue;
            }
            $number = str_replace([' ', ','], ['', '.'], trim((string) $data[$field]));
            if (! is_numeric($number) || ! is_finite((float) $number) || (float) $number < 0 || ($field === 'quantity' && (float) $number <= 0)) {
                throw new \DomainException("Invalid RS {$field}: numeric source values are required; no amount was inferred. Check CSV columns/quoting.");
            }
        }
        if (blank($data['quantity']) || (blank($data['unit_price']) && blank($data['total']))) {
            throw new \DomainException('RS quantity and monetary values are missing. Check CSV columns/quoting.');
        }
        if (in_array(trim((string) $data['document']), ['აქტიური', 'დასრულებული', 'გაუქმებული'], true)) {
            throw new \DomainException('RS status was mapped as document number. Check CSV columns/quoting.');
        }
    }

    private function recordSourceError(?array $data, string $message): void
    {
        $sourceId = $data['source_id'] ?? $data['document_id'] ?? $data['document'] ?? null;
        if (filled($sourceId) && filled($data['supplier'] ?? null)) {
            $key = json_encode([Supplier::normalizeName($data['supplier']), trim((string) $sourceId)]);
            $this->sourceErrors[$key][] = $message;
        } else {
            $this->unscopedErrors[] = $message;
        }
    }

    private function hasAccountingHistory(Purchase $purchase): bool
    {
        return $purchase->cashPostings()->exists() || $purchase->bankTransactions()->exists() || $purchase->advanceSettlement()->exists();
    }

    private function retainSource(Purchase $purchase, PurchaseItem $item, array $data): void
    {
        $purchase = Purchase::lockForUpdate()->findOrFail($purchase->id);
        if ($this->hasAccountingHistory($purchase)) {
            return;
        }
        $payload = $purchase->rs_import_payload ?? ['version' => 1, 'source_rows' => []];
        $payload['source_rows'] = array_intersect_key($payload['source_rows'], array_fill_keys($purchase->items()->pluck('source_row_hash')->filter()->all(), true));
        $payload['validated'] = false; // Publish only after the whole file has been checked.
        if (filled($data['document_total'])) {
            $total = number_format($this->number($data['document_total']), 2, '.', '');
            if (isset($payload['document_total']) && $payload['document_total'] !== $total) {
                throw new \DomainException('Conflicting authoritative RS document totals.');
            }
            $payload['document_total'] = $total;
        }
        $payload['source_rows'][$item->source_row_hash] ??= [
            'mapped' => [...$this->lineValues($data), 'item_name' => trim($data['product'])],
            'raw' => $data['audit'],
        ];
        $purchase->forceFill(['rs_import_payload' => $payload])->saveQuietly();
        $this->touchedPurchases[$purchase->id] = true;
    }

    /** Explicit Owner action; never changes confirmed history or creates accounting. */
    public function refreshUnconfirmed(int $purchaseId, User $actor): Purchase
    {
        abort_unless($actor->canManageOwnerModules(), 403);

        return DB::transaction(function () use ($purchaseId): Purchase {
            $purchase = Purchase::lockForUpdate()->findOrFail($purchaseId);
            $payload = $purchase->rs_import_payload;
            if ($purchase->source !== 'rs' || ! $payload || empty($payload['validated']) || $this->hasAccountingHistory($purchase) || ! empty($payload['validation_errors'])) {
                throw ValidationException::withMessages(['items' => 'RS წყარო არ არის გადამოწმებული ან დოკუმენტს უკვე აქვს გადახდის ისტორია. საჭიროა ორიგინალი ექსპორტის გადამოწმება.']);
            }
            foreach ($payload['source_rows'] as $hash => &$source) {
                $data = $this->rowData($source['raw']['cells'], $this->headerMap($source['raw']['headers']));
                $this->validateAmounts($data);
                if ($this->sourceId($data, $source['raw']['file_hash']) !== $purchase->source_document_id
                    || Supplier::normalizeName($data['supplier']) !== $purchase->supplier->normalized_name) {
                    throw new \DomainException('RS source identity changed. Cross-document remapping requires review.');
                }
                $mapped = [...$this->lineValues($data), 'item_name' => trim($data['product'])];
                $item = $purchase->items()->where('source_row_hash', $hash)->sole();
                $item->fill([...$mapped, 'purchase_product_id' => $this->catalog->resolve($purchase->supplier_id, $data['product'], $data['rs_code'], $data['supplier_code'])->id])->saveWithDeferredTotal();
                $source['mapped'] = $mapped;
            }
            unset($source);
            $purchase->forceFill(['rs_import_payload' => $payload])->saveQuietly();
            $purchase->refreshTotal();
            app(PurchaseReconciliation::class)->assertValid($purchase->fresh());

            return $purchase->fresh();
        });
    }

    private function reader(string $path): ReaderInterface
    {
        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'csv') {
            return ReaderFactory::createFromFile($path);
        }

        $handle = fopen($path, 'r');
        $firstLine = $handle ? (string) fgets($handle) : '';
        if ($handle) {
            fclose($handle);
        }
        $options = new CsvOptions;
        $options->FIELD_DELIMITER = collect([',', ';', "\t"])->sortByDesc(fn (string $delimiter): int => substr_count($firstLine, $delimiter))->first() ?? ',';

        return new CsvReader($options);
    }

    /** @param array<int, mixed> $values @return array<string, int> */
    private function headerMap(array $values): array
    {
        $map = [];
        foreach ($values as $index => $value) {
            $header = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', str_replace("\xEF\xBB\xBF", '', (string) $value))));
            foreach (self::HEADERS as $canonical => $aliases) {
                if (in_array($header, $aliases, true)) {
                    if (isset($map[$canonical])) {
                        throw new \DomainException("Ambiguous RS columns for {$canonical}. Use one authoritative column per field.");
                    }
                    $map[$canonical] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /** @param array<int, mixed> $values @param array<string, int> $headers @return array<string, mixed> */
    private function rowData(array $values, array $headers): array
    {
        return collect(array_keys(self::HEADERS))->mapWithKeys(fn (string $key): array => [$key => isset($headers[$key]) ? ($values[$headers[$key]] ?? null) : null])->all();
    }

    private function date(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->toDateString();
        }
        $value = strtr(mb_strtolower(trim((string) $value)), [
            'იან' => '01', 'თებ' => '02', 'მარ' => '03', 'აპრ' => '04', 'მაი' => '05', 'ივნ' => '06',
            'ივლ' => '07', 'აგვ' => '08', 'სექ' => '09', 'ოქტ' => '10', 'ნოე' => '11', 'დეკ' => '12',
        ]);
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})(?:\s+\d{2}:\d{2}:\d{2})?$/', $value, $matches)) {
            if (! checkdate((int) $matches[2], (int) $matches[1], (int) $matches[3])) {
                throw new \DomainException('Invalid RS document date.');
            }

            return $matches[3].'-'.$matches[2].'-'.$matches[1];
        }
        foreach (['d.m.Y', 'Y-m-d', 'd/m/Y', 'm/d/Y'] as $format) {
            try {
                return Carbon::createFromFormat($format, trim((string) $value))->toDateString();
            } catch (Throwable) {
            }
        }

        return filled($value) ? Carbon::parse((string) $value)->toDateString() : today()->toDateString();
    }

    private function number(mixed $value, float $default = 0): float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }
        $normalized = str_replace([' ', ','], ['', '.'], trim((string) $value));

        return is_numeric($normalized) ? (float) $normalized : $default;
    }

    private function sourceId(array $data, string $fileHash): string
    {
        return filled($data['document_id']) ? trim((string) $data['document_id'])
            : (filled($data['document']) ? trim((string) $data['document']) : 'file:'.$fileHash.':'.$this->date($data['date']));
    }

    private function lineValues(array $data): array
    {
        $quantity = max($this->number($data['quantity'], 1), 0.001);
        $price = max($this->number($data['unit_price']), 0);

        return [
            'quantity' => number_format($quantity, 3, '.', ''),
            'unit' => filled($data['unit']) ? trim((string) $data['unit']) : null,
            'unit_price' => number_format($price, 2, '.', ''),
            'line_total' => number_format($this->number($data['total'], round($quantity * $price, 2)), 2, '.', ''),
            'vat_amount' => filled($data['vat']) ? number_format(max($this->number($data['vat']), 0), 2, '.', '') : null,
        ];
    }

    private function contentSignature(array $data): string
    {
        $product = filled($data['rs_code']) ? ['rs', trim((string) $data['rs_code'])]
            : (filled($data['supplier_code']) ? ['supplier', trim((string) $data['supplier_code'])]
                : ['name', Product::normalizeName($data['product'])]);

        return hash('sha256', json_encode([$product, $this->lineValues($data)], JSON_THROW_ON_ERROR));
    }

    private function sameLine(PurchaseItem $item, array $data, int $productId): bool
    {
        if ($item->purchase_product_id !== $productId) {
            return false;
        }
        foreach ($this->lineValues($data) as $field => $value) {
            $stored = $item->{$field};
            if ($field === 'vat_amount' && $stored !== null) {
                $stored = number_format((float) $stored, 2, '.', '');
            }
            if ($stored !== $value) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $data */
    private function rowHash(array $data, Supplier $supplier, bool $includeCode = true): string
    {
        $code = filled($data['rs_code']) ? '|rs|'.trim($data['rs_code']) : (filled($data['supplier_code']) ? '|supplier|'.trim($data['supplier_code']) : '');
        $document = $includeCode && filled($data['document_id']) ? $data['document_id'] : $data['document'];

        return hash('sha256', implode('|', [$supplier->normalized_name, trim((string) $document), $this->date($data['date']), Product::normalizeName($data['product']), $data['quantity'], $data['unit'], $data['unit_price'], $data['total'], $data['vat']]).($includeCode ? $code : ''));
    }
}
