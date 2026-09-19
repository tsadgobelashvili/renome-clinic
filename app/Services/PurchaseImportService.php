<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use DateTimeInterface;
use Illuminate\Contracts\Database\ConcurrencyErrorDetector;
use Illuminate\Database\DeadlockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Reader\Common\Creator\ReaderFactory;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\ReaderInterface;
use Throwable;

class PurchaseImportService
{
    private const HEADERS = [
        'date' => ['date', 'document date', 'invoice date', 'თარიღი', 'გააქტიურების თარიღი'],
        'supplier' => ['supplier', 'supplier name', 'seller', 'მომწოდებელი', 'გამყიდველი'],
        'product' => ['product', 'product name', 'goods', 'item', 'საქონელი', 'დასახელება', 'პროდუქტი', 'საქონლის დასახელება'],
        'quantity' => ['quantity', 'qty', 'რაოდენობა', 'რაოდ.'],
        'unit' => ['unit', 'measurement', 'ერთეული', 'ზომის ერთეული'],
        'unit_price' => ['unit price', 'price', 'ერთეულის ფასი', 'ფასი'],
        'total' => ['total amount', 'total', 'amount', 'ჯამი', 'თანხა', 'საქონლის ფასი'],
        'vat' => ['vat', 'tax', 'vat amount', 'დღგ'],
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
        $summary = ['documents_imported' => 0, 'imported' => 0, 'skipped' => 0, 'needs_review' => 0, 'failed_rows' => 0, 'errors' => []];
        $batchId = (string) Str::uuid();
        $fileHash = hash_file('sha256', $path);
        $detectedHeaders = false;
        $pending = [];
        $occurrences = [];
        $reader = $this->reader($path);
        $reader->open($path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $headers = null;
                $rowNumber = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $rowNumber++;
                    $values = $row->toArray();
                    if ($headers === null) {
                        $headers = $this->headerMap($values);
                        if (! isset($headers['product'], $headers['supplier'])) {
                            $headers = null;
                        } else {
                            $detectedHeaders = true;
                        }

                        continue;
                    }

                    try {
                        $data = $this->rowData($values, $headers);
                        if (blank($data['product']) && blank($data['supplier'])) {
                            continue;
                        }
                        if (blank($data['product']) || blank($data['supplier'])) {
                            throw new \DomainException('Supplier and product are required.');
                        }
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
                        $summary['failed_rows']++;
                        $summary['errors'][] = "Row {$rowNumber}: {$exception->getMessage()}";

                        continue;
                    }
                    // Keep locks bounded to one supplier and at most 100 input rows.
                    if ($pending !== [] && Supplier::normalizeName($pending[0]['data']['supplier']) !== Supplier::normalizeName($data['supplier'])) {
                        $this->importBatch($pending, $summary, $createdBy, $batchId);
                        $pending = [];
                    }
                    $pending[] = ['data' => $data, 'row' => $rowNumber];
                    if (count($pending) >= 100) {
                        $this->importBatch($pending, $summary, $createdBy, $batchId);
                        $pending = [];
                    }
                }
            }
            if ($pending !== []) {
                $this->importBatch($pending, $summary, $createdBy, $batchId);
            }
        } finally {
            $reader->close();
        }

        if (! $detectedHeaders) {
            $summary['errors'][] = 'RS სათაურები ვერ მოიძებნა: საჭიროა საქონლის დასახელება და გამყიდველი/მომწოდებელი. ატვირთეთ საქონლის დეტალური ექსპორტი.';
        } elseif ($summary['imported'] === 0 && $summary['skipped'] === 0 && $summary['errors'] === []) {
            $summary['errors'][] = 'ფაილში იმპორტისთვის ვარგისი საქონლის სტრიქონები არ არის.';
        }

        return $summary;
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
                        $purchase = $purchases[$purchaseKey];
                        $quantity = max($this->number($data['quantity'], 1), 0.001);
                        $unitPrice = max($this->number($data['unit_price']), 0);
                        $total = $this->number($data['total'], round($quantity * $unitPrice, 2));
                        $unit = filled($data['unit']) ? trim((string) $data['unit']) : null;
                        $vat = filled($data['vat']) ? max($this->number($data['vat']), 0) : null;
                        $legacyCount = $purchase->wasRecentlyCreated ? 0 : $purchase->items()
                            ->whereNotNull('source_row_hash')->where('source_row_hash', 'not like', 'v2:%')
                            ->where(fn ($query) => $query->whereIn('id', $existing->modelKeys())
                                ->orWhere(fn ($query) => $query->where('purchase_product_id', $product->id)
                                    ->where('quantity', number_format($quantity, 3, '.', ''))
                                    ->where('unit', $unit)
                                    ->where('unit_price', number_format($unitPrice, 2, '.', ''))
                                    ->where('line_total', number_format($total, 2, '.', ''))
                                    ->where('vat_amount', $vat === null ? null : number_format($vat, 2, '.', ''))))
                            ->count();
                        if ($legacyCount > 0 && filled($data['line_id'])) {
                            throw new \DomainException('Cannot safely associate source line IDs with historical content-only RS lines. Review the document before importing this format.');
                        }
                        if ($data['occurrence'] <= $legacyCount) {
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
