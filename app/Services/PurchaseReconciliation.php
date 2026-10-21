<?php

namespace App\Services;

use App\Models\Purchase;
use App\Support\Money;
use App\Support\PurchaseQuantity;
use Illuminate\Validation\ValidationException;

/** Checks imported source evidence before any new RS accounting confirmation. */
class PurchaseReconciliation
{
    public function assertValid(Purchase $purchase, bool $auditOnly = false): void
    {
        if ($purchase->source !== 'rs') {
            return;
        }
        $payload = $purchase->rs_import_payload;
        if (! empty($payload['pending_import'])) {
            $this->fail('RS ფაილის სრული დამუშავება ჯერ არ დასრულებულა. ფინანსური გატარება დაბლოკილია.');
        }
        if (! $auditOnly && app(RsDocumentSemantics::class)->payloadKind($payload) !== 'purchase') {
            $this->fail('გაუქმებული, დაბრუნების/კორექტირების ან დაუზუსტებელი სტატუსის RS დოკუმენტი გადასახდელ შესყიდვად ვერ დადასტურდება.');
        }
        // Historical imports have no retained raw source. Never certify a derived
        // amount as an RS document total; require a validated re-import first.
        if (! $payload && $purchase->import_batch_id) {
            $this->fail('ორიგინალი RS მონაცემები არ არის შენახული. გადამოწმებამდე გადახდა დაბლოკილია; ხელახლა ატვირთეთ ორიგინალი ექსპორტი.');
        }
        if (! $payload) {
            return; // Existing manually entered documents keep their existing validation.
        }
        if (! empty($payload['validation_errors'])) {
            $this->fail(implode("\n", $payload['validation_errors']));
        }
        if (empty($payload['validated']) || empty($payload['source_rows'])) {
            $this->fail('RS იმპორტის გადამოწმება არ დასრულებულა. ფინანსური გატარება დაბლოკილია.');
        }
        $items = $purchase->items()->get();
        if ($items->count() !== count($payload['source_rows'])) {
            $this->fail('RS სტრიქონების რაოდენობა არ ემთხვევა შენახულ წყაროს.');
        }
        $expected = 0;
        foreach ($items as $item) {
            $mapped = $payload['source_rows'][$item->source_row_hash]['mapped'] ?? null;
            if (! $mapped) {
                $this->fail('RS სტრიქონის წყარო ვერ მოიძებნა.');
            }
            foreach ($mapped as $field => $value) {
                $actual = $item->{$field};
                $same = in_array($field, ['line_total', 'unit_price', 'vat_amount'], true)
                    ? (($actual === null) === ($value === null) && Money::minorUnits($actual) === Money::minorUnits($value))
                    : ($field === 'quantity' ? PurchaseQuantity::canonical($actual) === PurchaseQuantity::canonical($value) : $actual === $value);
                if (! $same) {
                    $this->fail('RS სტრიქონის მნიშვნელობები არ ემთხვევა ორიგინალ წყაროს. გამოიყენეთ წყაროდან განახლება.');
                }
            }
            $expected += Money::minorUnits($mapped['line_total']);
        }
        if ($expected !== Money::minorUnits($purchase->total_amount)
            || (isset($payload['document_total']) && abs($expected - Money::minorUnits($payload['document_total'])) > 1)) {
            $this->fail('RS სტრიქონების ჯამი არ ემთხვევა დოკუმენტის თანხას (დასაშვები დამრგვალება: 0.01 GEL). ფინანსური გატარება დაბლოკილია.');
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['items' => $message]);
    }
}
