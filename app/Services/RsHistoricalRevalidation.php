<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Money;
use App\Support\PurchaseQuantity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Owner-requested metadata/precision repair from a completed, retained checkpoint. */
class RsHistoricalRevalidation
{
    public function preview(string $run, User $owner): array
    {
        abort_unless($owner->canManageOwnerModules(), 403);

        DB::beginTransaction();
        try {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $this->plan($run, $owner);
        } finally {
            DB::rollBack();
        }
    }

    public function apply(string $run, User $owner, string $fingerprint): array
    {
        abort_unless($owner->canManageOwnerModules(), 403);

        return DB::transaction(function () use ($run, $owner, $fingerprint): array {
            // Same locks/order as purchase confirmation; recheck every accounting link
            // and the dry-run snapshot before changing even validation metadata.
            $ids = Purchase::where('source', 'rs')->orderBy('id')->lockForUpdate()->pluck('id');
            PurchaseItem::whereIn('purchase_id', $ids)->orderBy('id')->lockForUpdate()->get();
            $plan = $this->plan($run, $owner);
            if (! hash_equals($plan['fingerprint'], $fingerprint)) {
                throw new \DomainException('Database or source checkpoint changed. Run DRY RUN again; nothing was applied.');
            }
            foreach ($plan['changes'] as $change) {
                $purchase = Purchase::findOrFail($change['purchase_id']);
                if (app(PurchaseRecovery::class)->blockers($purchase)) {
                    throw new \DomainException('Accounting links changed. Nothing was applied.');
                }
                $payload = $change['payload'];
                $audit = ['import_id' => $run, 'fingerprint' => $fingerprint, 'user_id' => $owner->id,
                    'timestamp' => now()->toIso8601String(), 'previous_validation_errors' => $purchase->rs_import_payload['validation_errors'] ?? [],
                    'previous_document_kind' => $purchase->rs_import_payload['document_kind'] ?? null,
                    'new_document_kind' => $payload['document_kind'], 'previous_total' => $change['old_total'], 'new_total' => $change['source_total'],
                    'removed_items' => [], 'upgraded_items' => []];
                foreach ($change['remove'] as $id) {
                    $item = $purchase->items()->findOrFail($id);
                    $audit['removed_items'][] = ['item' => $item->getRawOriginal(), 'source' => $purchase->rs_import_payload['source_rows'][$item->source_row_hash] ?? null];
                    $item->delete();
                }
                foreach ($change['upgrade'] as $id => $data) {
                    $item = $purchase->items()->findOrFail($id);
                    $audit['upgraded_items'][] = ['item' => $item->getRawOriginal(), 'source' => $purchase->rs_import_payload['source_rows'][$item->source_row_hash] ?? null];
                    $item->fill(['quantity' => $data['quantity'], 'source_row_hash' => $data['hash']])->saveWithDeferredTotal();
                }
                $payload['historical_revalidations'][] = $audit;
                $purchase->forceFill(['rs_import_payload' => $payload])->saveQuietly();
                $purchase->refreshTotal();
                app(PurchaseReconciliation::class)->assertValid($purchase->fresh(), auditOnly: true);
                $purchaseId = $purchase->id;
                DB::afterCommit(fn () => Log::info('RS historical revalidation', ['purchase_id' => $purchaseId, ...$audit]));
            }

            return $plan;
        });
    }

    private function plan(string $run, User $owner): array
    {
        if (! Str::isUuid($run)) {
            throw new \DomainException('A completed import UUID is required.');
        }
        $work = new RsImportWorkspace($run);
        abort_unless($work->get('owner') === $owner->id, 403);
        $path = Storage::disk('local')->path($work->get('staged') ?? 'missing');
        if (! is_file($path)) {
            throw new \DomainException('Retained import checkpoint not found. No source values will be guessed.');
        }
        $meta = ['phase' => $work->get('phase'), 'cursor' => $work->get('cursor'), 'total' => $work->get('total')];
        if (($meta['phase'] ?? null) !== 'completed' || ($meta['cursor'] ?? null) !== ($meta['total'] ?? null)) {
            throw new \DomainException('Import is not completed. Revalidation is blocked.');
        }
        $documents = Purchase::where('source', 'rs')->with(['items' => fn ($q) => $q->orderBy('id')->with('purchaseProduct'), 'supplier'])->orderBy('id')->get();
        $lookup = [];
        foreach ($documents as $purchase) {
            foreach (array_unique(array_filter([$purchase->source_document_id, $purchase->document_number])) as $identity) {
                $lookup[$purchase->supplier->normalized_name.'|'.$identity][$purchase->id] = $purchase;
            }
        }
        $source = $errors = $knownErrors = [];
        $fileErrors = [];
        foreach ($work->entries('issue')->orderBy('id')->cursor() as $issue) {
            $knownErrors[$issue->value] = true;
        }
        foreach ($work->inputs() as $input) {
            $data = $input['data'] ?? [];
            $candidates = [];
            foreach (array_unique(array_filter([$data['source_id'] ?? null, $data['document_id'] ?? null, $data['document'] ?? null])) as $id) {
                foreach ($lookup[Supplier::normalizeName($data['supplier'] ?? '').'|'.$id] ?? [] as $key => $purchase) {
                    $candidates[$key] = $purchase;
                }
            }
            $purchase = count($candidates) === 1 ? reset($candidates) : null;
            if (isset($input['error'])) {
                $knownErrors[$input['error']] = true;
                if ($purchase && empty($input['ambiguous'])) {
                    $errors[$purchase->id][] = $input['error'];
                } else {
                    $fileErrors[] = $input['error'];
                }

                continue;
            }
            if (! $purchase) {
                throw new \DomainException('A retained accepted row has no unique existing document. Review source identities first.');
            }
            $source[$purchase->id][] = $data;
        }
        $plan = ['import_id' => $run, 'changes' => [], 'review' => [], 'unchanged' => [], 'file_errors' => $fileErrors, 'attributable_errors' => $errors,
            'legacy_negative_documents' => $documents->filter(fn ($p) => (float) $p->total_amount < 0)->modelKeys()];
        $digest = hash_init('sha256');
        hash_update($digest, hash_file('sha256', $path));
        hash_update($digest, json_encode([$meta, array_keys($knownErrors)], JSON_THROW_ON_ERROR));
        foreach ($documents as $purchase) {
            hash_update($digest, json_encode([$purchase->getRawOriginal(), $purchase->items->map->getRawOriginal()->all()], JSON_THROW_ON_ERROR));
            if (! isset($source[$purchase->id])) {
                $plan['review'][$purchase->id] = 'No accepted source rows in this checkpoint; left unchanged.'
                    .(isset($errors[$purchase->id]) ? ' Attributable error: '.implode('; ', $errors[$purchase->id]) : '');

                continue;
            }
            try {
                if ($blockers = app(PurchaseRecovery::class)->blockers($purchase)) {
                    throw new \DomainException('Accounting links: '.implode('; ', $blockers));
                }
                if (! empty($purchase->rs_import_payload['pending_import'])) {
                    throw new \DomainException('Document has an active pending import.');
                }
                if (! empty($errors[$purchase->id])) {
                    throw new \DomainException('Attributable source error: '.implode('; ', $errors[$purchase->id]));
                }
                foreach ($purchase->rs_import_payload['validation_errors'] ?? [] as $error) {
                    if (! isset($knownErrors[$error])) {
                        throw new \DomainException('An unresolved validation error is not explained by this checkpoint.');
                    }
                }
                $change = $this->document($purchase, $source[$purchase->id]);
                if ($change['remove'] === [] && $change['upgrade'] === [] && $change['payload'] == $purchase->rs_import_payload) {
                    $plan['unchanged'][] = $purchase->id;
                } else {
                    $plan['changes'][] = $change;
                }
            } catch (\DomainException|ValidationException $exception) {
                $plan['review'][$purchase->id] = $exception->getMessage();
            }
        }
        // Hash one document at a time: the full-year raw payload must never be
        // serialized into another large duplicate PHP string merely for a preview.
        foreach ($plan as $key => $value) {
            hash_update($digest, (string) $key);
            foreach ((array) $value as $entryKey => $entry) {
                hash_update($digest, json_encode([$entryKey, $entry], JSON_THROW_ON_ERROR));
            }
        }
        $plan['fingerprint'] = hash_final($digest);

        return $plan;
    }

    private function document(Purchase $purchase, array $source): array
    {
        $importer = app(PurchaseImportService::class);
        $payload = $purchase->rs_import_payload ?? [];
        $payload['source_rows'] = [];
        $used = $remove = $upgrade = [];
        $sum = 0;
        $declared = null;
        foreach ($source as $input) {
            $data = $importer->inspectSource($input['audit']);
            $data['occurrence'] = $input['occurrence'];
            if ($data['parsed_date'] !== $purchase->purchase_date->format('Y-m-d')
                || Supplier::normalizeName($data['supplier']) !== $purchase->supplier->normalized_name
                || ! in_array($data['source_id'], array_filter([$purchase->source_document_id, $purchase->document_number]), true)) {
                throw new \DomainException('Retained raw source document identity does not match.');
            }
            $hash = $importer->sourceHash($purchase, $data);
            $values = $importer->lineValues($data);
            $matches = $purchase->items->filter(fn ($item) => $this->matches($purchase, $item, $data, $values));
            $exact = $matches->firstWhere('source_row_hash', $hash);
            if (! $exact && $matches->count() > 1) {
                throw new \DomainException('Multiple source-equivalent lines require manual review.');
            }
            $item = $exact ?? $matches->first();
            $productId = $item?->purchase_product_id;
            if (! $productId) {
                $products = $purchase->items->filter(fn ($i) => $this->productMatches($purchase, $i, $data))->pluck('purchase_product_id')->unique();
                $productId = $products->count() === 1 ? $products->first() : null;
            }
            $rounded = $productId ? app(RsQuantityCompatibility::class)->candidate($purchase, $data, $productId) : null;
            if (! $item && $rounded) {
                $item = $rounded;
                $upgrade[$item->id] = ['quantity' => $data['quantity'], 'hash' => $hash];
            } elseif ($item && $rounded && $rounded->id !== $item->id) {
                $remove[$rounded->id] = $rounded->id;
            }
            if (! $item || isset($used[$item->id]) || isset($remove[$item->id])) {
                throw new \DomainException('Source occurrence has no unique compatible line.');
            }
            $used[$item->id] = true;
            $key = isset($upgrade[$item->id]) ? $hash : $item->source_row_hash;
            $payload['source_rows'][$key] = ['mapped' => [...$values, 'item_name' => $item->item_name], 'raw' => $input['audit']];
            $sum += Money::minorUnits($values['line_total']);
            if (filled($data['document_total'])) {
                $total = Money::minorUnits(str_replace([' ', ','], ['', '.'], (string) $data['document_total']));
                if ($declared !== null && $declared !== $total) {
                    throw new \DomainException('Conflicting authoritative source totals.');
                }
                $declared = $total;
            }
        }
        if (array_intersect_key($used, $remove) !== [] || $purchase->items->count() !== count($used) + count($remove)) {
            throw new \DomainException('Unexplained historical lines remain; no automatic repair.');
        }
        if ($declared !== null && abs($sum - $declared) > 1) {
            throw new \DomainException('Source lines do not reconcile to the authoritative document total.');
        }
        if (isset($payload['document_total']) && abs($sum - Money::minorUnits($payload['document_total'])) > 1) {
            throw new \DomainException('Source lines conflict with the retained authoritative document total.');
        }
        unset($payload['document_kind'], $payload['pending_import']);
        $payload['document_kind'] = app(RsDocumentSemantics::class)->payloadKind($payload);
        $previousKind = app(RsDocumentSemantics::class)->payloadKind($purchase->rs_import_payload);
        if (in_array($previousKind, ['cancelled', 'return'], true) && $previousKind !== $payload['document_kind']) {
            throw new \DomainException('Previous cancellation/return evidence conflicts with this checkpoint; Owner review required.');
        }
        if ($payload['document_kind'] === 'review') {
            throw new \DomainException('Unverified source semantics still require review.');
        }
        if ($payload['document_kind'] === 'purchase' && $sum <= 0) {
            throw new \DomainException('Non-positive normal purchase requires review.');
        }
        $payload['validated'] = true;
        $payload['validation_errors'] = [];

        return ['purchase_id' => $purchase->id, 'document_number' => $purchase->document_number,
            'old_total' => $purchase->total_amount, 'source_total' => number_format($sum / 100, 2, '.', ''),
            'remove' => array_values($remove), 'upgrade' => $upgrade, 'payload' => $payload];
    }

    private function matches(Purchase $purchase, PurchaseItem $item, array $data, array $values): bool
    {
        if (! $this->productMatches($purchase, $item, $data)) {
            return false;
        }
        foreach ($values as $field => $value) {
            $stored = $item->$field;
            if ($field === 'quantity') {
                $stored = PurchaseQuantity::canonical($stored);
            } elseif ($field === 'vat_amount' && $stored !== null) {
                $stored = number_format((float) $stored, 2, '.', '');
            }
            if ($stored !== $value) {
                return false;
            }
        }

        return true;
    }

    private function productMatches(Purchase $purchase, PurchaseItem $item, array $data): bool
    {
        $product = $item->purchaseProduct;
        if (! $product || ($product->supplier_id !== null && $product->supplier_id !== $purchase->supplier_id)) {
            return false;
        }
        foreach (['rs_code' => ['rs_product_code', 'rs'], 'supplier_code' => ['supplier_product_code', 'supplier']] as $key => [$column, $kind]) {
            if (filled($data[$key])) {
                return trim((string) $data[$key]) === $product->$column || DB::table('purchase_product_aliases')
                    ->where('identity_key', hash('sha256', $purchase->supplier_id.'|'.$kind.'|'.trim((string) $data[$key])))
                    ->where('purchase_product_id', $product->id)->exists();
            }
        }

        return Product::normalizeName($product->name) === Product::normalizeName($data['product']);
    }
}
