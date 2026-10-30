<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Explicit preview/confirm recovery of unaccounted RS imports; never posts money. */
class PurchaseRecovery
{
    public function __construct(private readonly PurchaseImportService $importer, private readonly PurchaseCatalog $catalog) {}

    public function blockers(Purchase $purchase): array
    {
        $records = [];
        foreach ($purchase->cashPostings()->get() as $posting) {
            $records[] = "Purchase #{$purchase->id}: Finance #{$posting->id} ({$posting->type}, {$posting->amount} {$posting->currency})";
            foreach (DB::table('cashbox_transactions')->where('finance_transaction_id', $posting->id)->pluck('id') as $id) {
                $records[] = "Purchase #{$purchase->id}: Cashier transaction #{$id}";
            }
            foreach (['salary_settlement_id', 'employee_salary_settlement_id', 'lab_salary_settlement_id', 'payroll_entry_id'] as $field) {
                if ($posting->getAttribute($field)) {
                    $records[] = "Purchase #{$purchase->id}: {$field} #{$posting->getAttribute($field)}";
                }
            }
        }
        foreach ($purchase->bankTransactions()->get() as $bank) {
            $records[] = "Purchase #{$purchase->id}: Bank transaction/allocation #{$bank->id} ({$bank->pivot->amount})";
        }
        foreach ($purchase->advanceSettlement()->get() as $entry) {
            $records[] = "Purchase #{$purchase->id}: Advance settlement #{$entry->id}, advance #{$entry->employee_advance_id}";
        }

        return $records;
    }

    public function preview(int $purchaseId, string $path, string $documentNumber, User $actor): array
    {
        $this->authorize($actor);
        $source = $this->importer->recoverySource($path, $documentNumber);
        $target = Purchase::findOrFail($purchaseId);
        if ($target->source !== 'rs') {
            $this->fail('Only existing RS purchases can be recovered.');
        }
        $supplier = Supplier::where('normalized_name', Supplier::normalizeName($source['supplier']))->first();
        $overlaps = $supplier ? Purchase::where('source', 'rs')->where('supplier_id', $supplier->id)->whereKeyNot($target->id)
            ->where(fn ($q) => $q->where('source_document_id', $source['source_id'])->orWhere('document_number', $source['document_number']))->orderBy('id')->get() : new Collection;
        $documents = new Collection([$target, ...$overlaps->all()]);
        $documents = $documents->sortBy('id');
        foreach ($documents as $document) {
            if ($blockers = $this->blockers($document)) {
                $this->fail("Recovery blocked; accounting history must remain unchanged:\n".implode("\n", $blockers));
            }
        }
        $items = PurchaseItem::with('purchaseProduct')->whereIn('purchase_id', $documents->modelKeys())->orderBy('id')->get();
        $foreign = PurchaseItem::whereIn('source_row_hash', array_keys($source['rows']))->whereNotIn('purchase_id', $documents->modelKeys())->first();
        if ($foreign) {
            $this->fail("RS source line already belongs to purchase #{$foreign->purchase_id}, item #{$foreign->id}. Recovery requires review.");
        }
        $used = $plan = [];
        foreach ($source['rows'] as $hash => $data) {
            $item = $items->first(fn ($item) => ! isset($used[$item->id]) && $item->source_row_hash === $hash);
            $item ??= $items->first(fn ($item) => ! isset($used[$item->id]) && $supplier && $item->purchaseProduct?->supplier_id === $supplier->id
                && $this->stableCodeMatches($item->purchaseProduct, $data) && $this->sameValues($item, $data['mapped']));
            if ($item) {
                $used[$item->id] = true;
            }
            $product = $supplier ? $this->existingProduct($supplier, $data, $item) : null;
            $plan[$hash] = ['item_id' => $item?->id, 'product_id' => $product?->id,
                'classification' => $product?->only(['expense_direction_id', 'expense_type_id', 'purchase_product_group_id'])];
        }
        foreach ($items->whereIn('purchase_id', $overlaps->modelKeys()) as $item) {
            $match = collect($plan)->search(fn ($line) => $line['item_id'] === $item->id);
            if ($match === false || ! $this->sameValues($item, $source['rows'][$match]['mapped'])) {
                $this->fail("Purchase #{$item->purchase_id}, item #{$item->id} is not an exact source match. Automatic consolidation is blocked.");
            }
        }
        $snapshot = $documents->map(fn ($doc) => ['purchase' => $doc->getRawOriginal(),
            'items' => $items->where('purchase_id', $doc->id)->map(fn ($item) => ['item' => $item->getRawOriginal(), 'product' => $item->purchaseProduct?->getRawOriginal()])->values()->all()])->values()->all();
        $fingerprint = hash('sha256', json_encode([$snapshot, $plan, $source['file_hash']], JSON_THROW_ON_ERROR));

        return ['purchase_id' => $target->id, 'current_total' => $target->total_amount, 'source' => $source,
            'consolidate_ids' => $overlaps->modelKeys(), 'plan' => $plan, 'snapshot' => $snapshot, 'fingerprint' => $fingerprint];
    }

    public function recover(int $purchaseId, string $path, string $documentNumber, string $fingerprint, string $requestKey, User $actor): Purchase
    {
        $this->authorize($actor);
        validator(compact('requestKey'), ['requestKey' => 'required|uuid'])->validate();
        $source = $this->importer->recoverySource($path, $documentNumber);

        return DB::transaction(function () use ($purchaseId, $path, $documentNumber, $fingerprint, $requestKey, $actor, $source): Purchase {
            $supplier = Supplier::firstOrCreate(['normalized_name' => Supplier::normalizeName($source['supplier'])], ['name' => $source['supplier']]);
            $supplier = Supplier::lockForUpdate()->findOrFail($supplier->id);
            $ids = Purchase::where('source', 'rs')->where('supplier_id', $supplier->id)
                ->where(fn ($q) => $q->where('source_document_id', $source['source_id'])->orWhere('document_number', $source['document_number']))->pluck('id')->push($purchaseId);
            $locked = Purchase::whereIn('id', $ids->unique())->orderBy('id')->lockForUpdate()->get();
            $target = $locked->firstWhere('id', $purchaseId) ?? Purchase::findOrFail($purchaseId);
            $previous = $target->rs_import_payload['recovery'] ?? null;
            if (($previous['request_key'] ?? null) === $requestKey) {
                if ($previous['file_hash'] !== $source['file_hash'] || $previous['document_number'] !== trim($documentNumber)) {
                    $this->fail('Recovery request was already used for another source.');
                }

                return $target;
            }
            $preview = $this->preview($purchaseId, $path, $documentNumber, $actor);
            if (! hash_equals($preview['fingerprint'], $fingerprint)) {
                $this->fail('Purchase, classifications, overlapping document or source changed after preview. Preview again before confirming.');
            }
            $history = $target->rs_import_payload['recovery_history'] ?? [];
            $history[] = ['before' => $preview['snapshot'], 'user_id' => $actor->id, 'timestamp' => now()->toIso8601String()];
            $keep = array_filter(array_column($preview['plan'], 'item_id'));
            foreach ($target->items()->whereNotIn('id', $keep)->get() as $item) {
                $item->delete();
            }
            $target->fill(['supplier_id' => $supplier->id, 'document_number' => $source['document_number'],
                // Reserve the final unique identity only after source-matched duplicates
                // are consolidated below. This temporary NULL never escapes this transaction.
                'source_document_id' => null, 'purchase_date' => $source['date']])->save();
            $payload = ['version' => 1, 'validated' => true, 'validation_errors' => [], 'source_rows' => [], 'recovery_history' => $history,
                'recovery' => ['request_key' => $requestKey, 'file_hash' => $source['file_hash'], 'document_number' => $source['document_number'],
                    'consolidated_purchase_ids' => $preview['consolidate_ids'], 'user_id' => $actor->id, 'timestamp' => now()->toIso8601String()]];
            if ($source['document_total'] !== null) {
                $payload['document_total'] = $source['document_total'];
            }
            foreach ($source['rows'] as $hash => $data) {
                $line = $preview['plan'][$hash];
                $product = $line['product_id'] ? PurchaseProduct::findOrFail($line['product_id']) : $this->newProduct($supplier, $data);
                $item = $line['item_id'] ? PurchaseItem::findOrFail($line['item_id']) : new PurchaseItem;
                $item->fill([...$data['mapped'], 'purchase_id' => $target->id, 'purchase_product_id' => $product->id, 'source_row_hash' => $hash]);
                $item->setRelation('purchase', $target)->setRelation('purchaseProduct', $product)->saveWithDeferredTotal();
                $payload['source_rows'][$hash] = ['mapped' => $data['mapped'], 'raw' => $data['audit']];
            }
            foreach ($preview['consolidate_ids'] as $id) {
                $duplicate = $locked->firstWhere('id', $id);
                if ($duplicate->items()->exists()) {
                    $this->fail("Purchase #{$id} still has unmatched lines; consolidation was rolled back.");
                }
                $duplicate->delete();
            }
            $target->forceFill(['source_document_id' => $source['source_id'], 'rs_import_payload' => $payload])->save();
            $target->refreshTotal();
            app(PurchaseReconciliation::class)->assertValid($target->fresh());
            $audit = ['purchase_id' => $target->id, 'old_total' => $preview['current_total'], 'new_total' => $target->total_amount,
                'document_number' => $source['document_number'], 'consolidated_purchase_ids' => $preview['consolidate_ids'],
                'file_hash' => $source['file_hash'], 'user_id' => $actor->id, 'timestamp' => now()->toIso8601String()];
            DB::afterCommit(fn () => Log::info('Unconfirmed RS purchase recovered from source', $audit));

            return $target->fresh();
        });
    }

    private function existingProduct(Supplier $supplier, array $data, ?PurchaseItem $item): ?PurchaseProduct
    {
        if ($item?->purchaseProduct?->supplier_id === $supplier->id && $item->source_row_hash === $data['line_hash']
            && ((blank($data['rs_code']) && blank($data['supplier_code'])) || $this->stableCodeMatches($item->purchaseProduct, $data))) {
            return $item->purchaseProduct;
        }
        if (filled($data['rs_code'])) {
            return $this->oneProduct(PurchaseProduct::where('supplier_id', $supplier->id)->where('rs_product_code', trim($data['rs_code']))->get());
        }
        if (filled($data['supplier_code'])) {
            return $this->oneProduct(PurchaseProduct::where('supplier_id', $supplier->id)->where('supplier_product_code', trim($data['supplier_code']))->get());
        }

        return null;
    }

    private function oneProduct(Collection $products): ?PurchaseProduct
    {
        if ($products->count() > 1) {
            $this->fail('Ambiguous product code: multiple catalog products match. Classification requires Owner review.');
        }

        return $products->first();
    }

    private function newProduct(Supplier $supplier, array $data): PurchaseProduct
    {
        if (filled($data['rs_code']) || filled($data['supplier_code'])) {
            return $this->catalog->resolve($supplier->id, $data['product'], $data['rs_code'], $data['supplier_code']);
        }

        // A name alone is not evidence for transferring an Owner classification.
        return PurchaseProduct::firstOrCreate(['identity_key' => hash('sha256', $supplier->id.'|recovery-source|'.$data['line_hash'])],
            ['supplier_id' => $supplier->id, 'name' => trim($data['product']), 'normalized_name' => Product::normalizeName($data['product']), 'catalog_origin' => 'rs']);
    }

    private function stableCodeMatches(?PurchaseProduct $product, array $data): bool
    {
        return $product && ((filled($data['rs_code']) && $product->rs_product_code === trim($data['rs_code']))
            || (blank($data['rs_code']) && filled($data['supplier_code']) && $product->supplier_product_code === trim($data['supplier_code'])));
    }

    private function sameValues(PurchaseItem $item, array $mapped): bool
    {
        foreach ($mapped as $field => $value) {
            if ($field === 'vat_amount') {
                if (($item->{$field} === null) !== ($value === null) || Money::minorUnits($item->{$field}) !== Money::minorUnits($value)) {
                    return false;
                }
            } elseif ((string) $item->{$field} !== (string) $value) {
                return false;
            }
        }

        return $mapped !== [];
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->canManageOwnerModules(), 403);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['recovery' => $message]);
    }
}
