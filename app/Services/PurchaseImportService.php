<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductCategory;
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
        'date' => ['date', 'document date', 'invoice date', 'თარიღი'],
        'supplier' => ['supplier', 'supplier name', 'seller', 'მომწოდებელი', 'გამყიდველი'],
        'product' => ['product', 'product name', 'goods', 'item', 'საქონელი', 'დასახელება', 'პროდუქტი'],
        'quantity' => ['quantity', 'qty', 'რაოდენობა'],
        'unit' => ['unit', 'measurement', 'ერთეული'],
        'unit_price' => ['unit price', 'price', 'ერთეულის ფასი', 'ფასი'],
        'total' => ['total amount', 'total', 'amount', 'ჯამი', 'თანხა'],
        'vat' => ['vat', 'tax', 'vat amount', 'დღგ'],
        'document' => ['invoice', 'invoice number', 'document number', 'document', 'ზედნადები', 'დოკუმენტი'],
        'category' => ['category', 'კატეგორია'],
    ];

    public function __construct(private readonly PurchaseCategoryResolver $categories) {}

    /** @return array{imported: int, skipped: int, needs_review: int, errors: array<int, string>} */
    public function import(string $path, ?int $createdBy = null): array
    {
        $summary = ['imported' => 0, 'skipped' => 0, 'needs_review' => 0, 'errors' => []];
        $batchId = (string) Str::uuid();
        $purchases = [];
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

                        DB::transaction(function () use ($data, $createdBy, $batchId, &$purchases, &$summary): void {
                            $supplier = $this->supplier($data['supplier']);
                            $product = $this->product($data['product'], $data['category']);
                            $hash = $this->rowHash($data, $supplier, $product);
                            if (PurchaseItem::query()->where('source_row_hash', $hash)->exists()) {
                                $summary['skipped']++;

                                return;
                            }

                            $date = $this->date($data['date']);
                            $document = filled($data['document']) ? trim((string) $data['document']) : null;
                            $key = implode('|', [$supplier->id, $document ?: $date, $batchId]);
                            $purchase = $purchases[$key] ??= Purchase::create([
                                'purchase_date' => $date,
                                'supplier_id' => $supplier->id,
                                'document_number' => $document,
                                'source' => 'rs',
                                'source_document_id' => $document,
                                'import_batch_id' => $batchId,
                                'created_by' => $createdBy,
                            ]);
                            $quantity = max($this->number($data['quantity'], 1), 0.001);
                            $unitPrice = max($this->number($data['unit_price']), 0);
                            $total = $this->number($data['total'], round($quantity * $unitPrice, 2));
                            $purchase->items()->create([
                                'product_id' => $product->id,
                                'quantity' => $quantity,
                                'unit' => filled($data['unit']) ? trim((string) $data['unit']) : null,
                                'unit_price' => $unitPrice,
                                'line_total' => $total,
                                'vat_amount' => filled($data['vat']) ? max($this->number($data['vat']), 0) : null,
                                'source_row_hash' => $hash,
                            ]);
                            $summary['imported']++;
                            if ($product->category?->slug === ProductCategory::NEEDS_REVIEW_SLUG) {
                                $summary['needs_review']++;
                            }
                        });
                    } catch (Throwable $exception) {
                        $summary['errors'][] = "Row {$rowNumber}: {$exception->getMessage()}";
                    }
                }
            }
        } finally {
            $reader->close();
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

    private function product(string $name, mixed $category): Product
    {
        $normalized = Product::normalizeName($name);
        $product = Product::query()->with('category')->where('normalized_name', $normalized)->first();
        if ($product && $product->category?->slug !== ProductCategory::NEEDS_REVIEW_SLUG) {
            return $product;
        }
        $resolved = $this->categories->resolve($name, filled($category) ? (string) $category : null);
        if ($product) {
            $product->update(['product_category_id' => $resolved->id]);

            return $product->fresh('category');
        }

        return Product::create(['name' => trim($name), 'normalized_name' => $normalized, 'product_category_id' => $resolved->id, 'selling_price' => 0, 'is_active' => true])->load('category');
    }

    /** @param array<int, mixed> $values @return array<string, int> */
    private function headerMap(array $values): array
    {
        $map = [];
        foreach ($values as $index => $value) {
            $header = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $value)));
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
    private function rowHash(array $data, Supplier $supplier, Product $product): string
    {
        return hash('sha256', implode('|', [$supplier->normalized_name, trim((string) $data['document']), $this->date($data['date']), $product->normalized_name, $data['quantity'], $data['unit'], $data['unit_price'], $data['total'], $data['vat']]));
    }
}
