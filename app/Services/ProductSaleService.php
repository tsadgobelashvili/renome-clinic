<?php

namespace App\Services;

use App\Enums\PaymentMethod;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\Visit;
use App\Support\CashboxManager;
use App\Support\Currency;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductSaleService
{
    public function __construct(private readonly CashboxManager $cashboxManager) {}

    /** @param array<string, mixed> $data */
    public function create(array $data): ProductSale
    {
        $items = $this->normalizeItems($data['items'] ?? []);
        $method = PaymentMethod::normalize($data['payment_method'] ?? '');
        $currency = $data['currency'] ?? Currency::DEFAULT;
        $baseTotal = Money::decimal(collect($items)->sum('line_total'));
        $exchangeRate = $currency === Currency::DEFAULT ? null : round((float) ($data['exchange_rate'] ?? 0), 6);

        if (! PaymentMethod::isSupported($method)) {
            throw ValidationException::withMessages(['payment_method' => 'აირჩიეთ გადახდის მეთოდი.']);
        }
        if (! Currency::isSupported($currency)) {
            throw ValidationException::withMessages(['currency' => 'აირჩიეთ მხარდაჭერილი ვალუტა.']);
        }
        if ($currency !== Currency::DEFAULT && $exchangeRate <= 0) {
            throw ValidationException::withMessages(['exchange_rate' => 'USD გადახდისთვის საჭიროა მოქმედი NBG კურსი.']);
        }
        if (filled($data['patient_id'] ?? null) && ! Patient::query()->whereKey($data['patient_id'])->exists()) {
            throw ValidationException::withMessages(['patient_id' => 'არჩეული პაციენტი ვერ მოიძებნა.']);
        }
        if (filled($data['visit_id'] ?? null) && ! Visit::query()->whereKey($data['visit_id'])->exists()) {
            throw ValidationException::withMessages(['visit_id' => 'ვიზიტი ვერ მოიძებნა.']);
        }

        return DB::transaction(function () use ($data, $items, $method, $currency, $baseTotal, $exchangeRate): ProductSale {
            $sale = ProductSale::create([
                'sold_at' => $data['sold_at'] ?? now(),
                'patient_id' => $data['patient_id'] ?? null,
                'visit_id' => $data['visit_id'] ?? null,
                'total' => $currency === Currency::DEFAULT ? $baseTotal : Money::decimal($baseTotal / $exchangeRate),
                'base_total' => $baseTotal,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'payment_method' => $method,
                'note' => $data['note'] ?? null,
                'created_by' => auth()->id(),
            ]);
            $sale->items()->createMany($items);
            $this->cashboxManager->syncProductSale($sale, $data['payment_rows'] ?? null);

            return $sale->load(['items.product', 'cashboxTransaction']);
        });
    }

    /** @return array{product: array<int, array<string, mixed>>, service: array<int, array<string, mixed>>} */
    public function partitionPaymentRows(array $rows, float $productTotal, string $currency): array
    {
        $remaining = Money::minorUnits($productTotal);
        $product = $service = [];

        foreach (app(PaymentProcessor::class)->normalizeRows($rows, $currency) as $row) {
            $rate = ($row['currency'] ?? $currency) === $currency ? 1.0 : (float) $row['exchange_rate'];
            $rowBase = Money::minorUnits((float) $row['amount'] * $rate);
            $productBase = min($remaining, $rowBase);
            $productAmount = Money::decimal(($productBase / 100) / $rate);
            $serviceAmount = Money::decimal((float) $row['amount'] - $productAmount);

            if (Money::minorUnits($productAmount) > 0) {
                $product[] = [...$row, 'amount' => $productAmount];
            }
            if (Money::minorUnits($serviceAmount) > 0) {
                $service[] = [...$row, 'amount' => $serviceAmount];
            }
            $remaining -= $productBase;
        }

        if ($remaining > 1) {
            throw ValidationException::withMessages(['products' => 'პროდუქტის თანხა გადახდის განაწილებას აღემატება.']);
        }

        return ['product' => $product, 'service' => $service];
    }

    /** @param array<int|string, array<string, mixed>> $items @return array<int, array<string, int|float>> */
    public function normalizeItems(array $items): array
    {
        $normalized = collect($items)->values()->map(function (array $item): array {
            $product = Product::query()->where('is_active', true)->find($item['product_id'] ?? null);
            $quantity = max((int) ($item['quantity'] ?? 1), 1);
            $unitPrice = Money::decimal($item['unit_price'] ?? $product?->selling_price ?? 0);

            if (! $product) {
                throw ValidationException::withMessages(['items' => 'აირჩიეთ აქტიური პროდუქტი.']);
            }
            if (Money::minorUnits($unitPrice) <= 0) {
                throw ValidationException::withMessages(['items' => 'პროდუქტის ფასი 0-ზე მეტი უნდა იყოს.']);
            }

            return ['product_id' => $product->getKey(), 'quantity' => $quantity, 'unit_price' => $unitPrice, 'line_total' => round($quantity * $unitPrice, 2)];
        })->all();

        if ($normalized === []) {
            throw ValidationException::withMessages(['items' => 'დაამატეთ მინიმუმ ერთი პროდუქტი.']);
        }

        return $normalized;
    }
}
