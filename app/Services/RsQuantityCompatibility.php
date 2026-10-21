<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Support\PurchaseQuantity;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** Recognize an exact predecessor hash from the old three-decimal importer. */
class RsQuantityCompatibility
{
    public function candidate(Purchase $purchase, array $data, int $productId): ?PurchaseItem
    {
        if (blank($data['total'] ?? null) || (blank($data['rs_code'] ?? null) && blank($data['supplier_code'] ?? null))) {
            return null;
        }
        $precise = PurchaseQuantity::canonical($data['quantity']);
        $rounded = (string) BigDecimal::of($precise)->toScale(3, RoundingMode::HalfUp);
        if (PurchaseQuantity::canonical($rounded) === $precise) {
            return null;
        }
        $importer = app(PurchaseImportService::class);
        $legacyData = [...$data, 'quantity' => $rounded];
        $hash = $importer->sourceHash($purchase, $legacyData);
        $item = $purchase->items()->where('source_row_hash', $hash)->where('purchase_product_id', $productId)->first();
        if (! $item || PurchaseQuantity::canonical($item->quantity) !== PurchaseQuantity::canonical($rounded)) {
            return null;
        }
        foreach ($importer->lineValues($data) as $field => $value) {
            if ($field === 'quantity') {
                continue;
            }
            $stored = $item->$field;
            if ($field === 'vat_amount' && $stored !== null) {
                $stored = number_format((float) $stored, 2, '.', '');
            }
            if ($stored !== $value) {
                return null;
            }
        }
        // A retained original quantity different from the new source is contrary
        // evidence, even if the values happen to round to the same three decimals.
        $raw = $purchase->rs_import_payload['source_rows'][$item->source_row_hash]['raw'] ?? null;
        if ($raw) {
            try {
                $original = $importer->inspectSource($raw);
                if (PurchaseQuantity::canonical($original['quantity']) !== $precise) {
                    return null;
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return $item;
    }

    public function upgrade(Purchase $purchase, PurchaseItem $item, array $data, string $hash, ?int $actor): void
    {
        if (app(PurchaseRecovery::class)->blockers($purchase)) {
            throw new \DomainException('Precision compatibility requires an unaccounted RS document. Owner review required.');
        }
        $payload = $purchase->rs_import_payload ?? [];
        $audit = ['user_id' => $actor, 'timestamp' => now()->toIso8601String(), 'item' => $item->getRawOriginal(),
            'source' => $payload['source_rows'][$item->source_row_hash] ?? null, 'new_hash' => $hash, 'new_quantity' => $data['quantity']];
        $payload['precision_repairs'][] = $audit;
        unset($payload['source_rows'][$item->source_row_hash]);
        $purchase->forceFill(['rs_import_payload' => $payload])->saveQuietly();
        $item->fill(['quantity' => $data['quantity'], 'source_row_hash' => $hash])->saveWithDeferredTotal();
        $purchaseId = $purchase->id;
        DB::afterCommit(fn () => Log::info('RS quantity precision compatibility', ['purchase_id' => $purchaseId, ...$audit]));
    }
}
