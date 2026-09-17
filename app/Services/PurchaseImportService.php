<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use DateTimeInterface;
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

                        $result = DB::transaction(function () use ($data, $createdBy, $batchId, $fileHash): array {
                            $supplier = $this->supplier($data['supplier']);
                            // Serialize document lookup and row insertion for the same supplier.
                            Supplier::query()->whereKey($supplier->id)->lockForUpdate()->firstOrFail();
                            $product = $this->catalog->resolve($supplier->id, $data['product'], $data['rs_code'], $data['supplier_code']);
                            $hash = $this->rowHash($data, $supplier);
                            // Older imports ignored code columns. Keep their original hash valid
                            // so reimporting an unchanged historical export cannot duplicate it.
                            $legacyHash = $this->rowHash($data, $supplier, includeCode: false);
                            if (PurchaseItem::query()->where('source_row_hash', $hash)
                                ->orWhere(fn ($query) => $query->whereNotNull('product_id')->where('source_row_hash', $legacyHash))->exists()) {
                                return ['skipped' => true];
                            }

                            $date = $this->date($data['date']);
                            $document = filled($data['document']) ? trim((string) $data['document']) : null;
                            $sourceId = filled($data['document_id']) ? trim((string) $data['document_id']) : ($document ?? 'file:'.$fileHash.':'.$date);
                            $purchase = Purchase::query()->firstOrCreate([
                                'source' => 'rs',
                                'supplier_id' => $supplier->id,
                                'source_document_id' => $sourceId,
                            ], [
                                'purchase_date' => $date,
                                'supplier_id' => $supplier->id,
                                'document_number' => $document,
                                'source' => 'rs',
                                'import_batch_id' => $batchId,
                                'created_by' => $createdBy,
                            ]);
                            $quantity = max($this->number($data['quantity'], 1), 0.001);
                            $unitPrice = max($this->number($data['unit_price']), 0);
                            $total = $this->number($data['total'], round($quantity * $unitPrice, 2));
                            $purchase->items()->create([
                                'purchase_product_id' => $product->id,
                                'item_name' => trim($data['product']),
                                'quantity' => $quantity,
                                'unit' => filled($data['unit']) ? trim((string) $data['unit']) : null,
                                'unit_price' => $unitPrice,
                                'line_total' => $total,
                                'vat_amount' => filled($data['vat']) ? max($this->number($data['vat']), 0) : null,
                                'source_row_hash' => $hash,
                            ]);

                            return ['skipped' => false, 'document_created' => $purchase->wasRecentlyCreated, 'needs_review' => $product->expense_direction_id === null];
                        });
                        if ($result['skipped']) {
                            $summary['skipped']++;
                        } else {
                            $summary['imported']++;
                            $summary['documents_imported'] += (int) $result['document_created'];
                            $summary['needs_review'] += (int) $result['needs_review'];
                        }
                    } catch (Throwable $exception) {
                        $summary['failed_rows']++;
                        $summary['errors'][] = "Row {$rowNumber}: {$exception->getMessage()}";
                    }
                }
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

    /** @param array<string, mixed> $data */
    private function rowHash(array $data, Supplier $supplier, bool $includeCode = true): string
    {
        $code = filled($data['rs_code']) ? '|rs|'.trim($data['rs_code']) : (filled($data['supplier_code']) ? '|supplier|'.trim($data['supplier_code']) : '');
        $document = $includeCode && filled($data['document_id']) ? $data['document_id'] : $data['document'];

        return hash('sha256', implode('|', [$supplier->normalized_name, trim((string) $document), $this->date($data['date']), Product::normalizeName($data['product']), $data['quantity'], $data['unit'], $data['unit_price'], $data['total'], $data['vat']]).($includeCode ? $code : ''));
    }
}
