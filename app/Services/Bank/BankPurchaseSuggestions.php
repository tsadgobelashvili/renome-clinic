<?php

namespace App\Services\Bank;

use App\Models\BankTransaction;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseReconciliation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

/** Read-only advice. Identity and amount matches never select or confirm a document. */
class BankPurchaseSuggestions
{
    public const POOL_LIMIT = 60;

    public const NODE_LIMIT = 25000;

    public const MAX_DOCUMENTS = 4;

    public function __construct(private readonly BankPurchaseMatching $matching) {}

    public function supplier(BankTransaction $bank): array
    {
        $suppliers = Supplier::get(['id', 'name', 'tax_id'])->map(function ($supplier) {
            [$name, $embeddedTax] = $this->supplierLabel($supplier->name);
            $taxes = collect([$this->tax($supplier->tax_id), $embeddedTax])->filter()->unique()->values();

            return ['id' => $supplier->id, 'name' => $name, 'taxes' => $taxes->all(), 'tax' => $taxes->count() === 1 ? $taxes->first() : null];
        });
        $taxIds = $this->taxIds($bank);
        if (count($taxIds) > 1) {
            return ['ids' => [], 'method' => 'ambiguous'];
        }
        if ($taxIds !== []) {
            $matches = $suppliers->filter(fn ($supplier) => in_array($taxIds[0], $supplier['taxes'], true));
            if ($matches->contains(fn ($supplier) => count($supplier['taxes']) > 1)) {
                return ['ids' => [], 'method' => 'ambiguous'];
            }

            // A supplied tax ID is authoritative. A contradictory name cannot override it.
            return ['ids' => $matches->pluck('id')->all(), 'method' => $matches->isEmpty() ? 'unmatched' : 'tax'];
        }
        [$name] = $this->supplierLabel($bank->counterparty_name);
        if ($name === '') {
            return ['ids' => [], 'method' => 'unmatched'];
        }
        $matches = $suppliers->filter(fn ($supplier) => $supplier['name'] === $name);
        // No supplier alias table exists. Reuse only explicit, consistent historical
        // Owner confirmations, never categorization rules or unconfirmed UI choices.
        $aliases = DB::table('bank_purchase_matches as m')->join('bank_transactions as b', 'b.id', '=', 'm.bank_transaction_id')
            ->join('purchases as p', 'p.id', '=', 'm.purchase_id')->join('users as u', 'u.id', '=', 'm.confirmed_by')
            ->where('u.role', User::ROLE_OWNER)->where('p.source', 'rs')->where('b.direction', 'outflow')->where('b.currency', 'GEL')->where('b.id', '!=', $bank->id)
            ->distinct()->get(['p.supplier_id', 'b.counterparty_account', 'b.counterparty_name'])
            ->filter(fn ($row) => $this->supplierLabel($row->counterparty_name)[0] === $name);
        if (filled($bank->counterparty_account)) {
            $aliases = $aliases->filter(fn ($row) => BankRuleMatcher::account($row->counterparty_account) === BankRuleMatcher::account($bank->counterparty_account));
        }
        $ids = $aliases->pluck('supplier_id')->merge($matches->pluck('id'))->unique()->values()->all();
        $candidates = $suppliers->whereIn('id', $ids);
        $identities = $candidates->map(fn ($supplier) => $supplier['tax'] ? 'tax:'.$supplier['tax'] : 'id:'.$supplier['id'])->unique();
        if ($candidates->contains(fn ($supplier) => count($supplier['taxes']) > 1) || $identities->count() > 1) {
            return ['ids' => [], 'method' => 'ambiguous'];
        }
        // RS exports may retain the tax ID in the label rather than tax_id.
        // Include sibling supplier rows only when the same explicit legal ID proves identity.
        if ($candidates->isNotEmpty() && $candidates->first()['tax']) {
            $tax = $candidates->first()['tax'];
            $ids = $suppliers->filter(fn ($supplier) => $supplier['tax'] === $tax)->pluck('id')->values()->all();
        }

        return ['ids' => $ids, 'method' => $ids !== [] ? ($matches->isNotEmpty() ? 'name' : 'alias') : 'unmatched'];
    }

    public function browse(BankTransaction $bank, string $search = '', bool $showAll = false, int $page = 1, array $selected = []): array
    {
        $identity = $this->supplier($bank);
        $selectedTetri = collect($selected)->sum(fn ($amount) => $this->matching->cents($amount));
        $target = max(0, $this->matching->cents($bank->amount) - $selectedTetri);
        $result = ['identity' => $identity, 'documents' => collect(), 'page' => max(1, $page), 'has_more' => false,
            'combinations' => [], 'suggested_ids' => [], 'limited' => false, 'errors' => [], 'target_tetri' => $target, 'selected_tetri' => $selectedTetri];
        try {
            $this->matching->validateBank($bank);
        } catch (ValidationException $exception) {
            $result['errors'] = collect($exception->errors())->flatten()->all();

            return $result;
        }
        $query = $this->matching->availableDocuments($bank);
        if (! $showAll) {
            $query->whereIn('purchases.supplier_id', $identity['ids']);
        }
        if (trim($search) !== '') {
            $pattern = '%'.Supplier::normalizeName($search).'%';
            $query->where(fn ($query) => $query->where('s.normalized_name', 'like', $pattern)->orWhereRaw('LOWER(s.name) LIKE ?', [$pattern])->orWhere('s.tax_id', 'like', $pattern)
                ->orWhereRaw('LOWER(purchases.document_number) LIKE ?', [$pattern]));
        }
        $this->order($query, $bank, $target);
        // Validate before slicing pages: rejected source rows must not hide eligible
        // invoices behind an empty first page or consume the suggestion pool.
        $documents = $this->eligibleDocuments($query)->skip((max(1, $page) - 1) * 20)->take(21)->collect();
        $result['documents'] = $documents->take(20)->values();
        $result['has_more'] = $documents->count() > 20;
        if ($identity['ids'] === [] || $target <= 0) {
            return $result;
        }
        // Suggestions stay within the identified supplier even when Show all is enabled.
        // Pagination/search never limit access to the remaining supplier documents.
        $poolQuery = $this->matching->availableDocuments($bank)->whereIn('purchases.supplier_id', $identity['ids'])
            ->whereNotIn('purchases.id', array_keys($selected))
            ->whereRaw('(dt.total - COALESCE(rs_used.amount, 0)) <= ?', [$target / 100]);
        $this->order($poolQuery, $bank, $target);
        $pool = $this->eligibleDocuments($poolQuery)->take(self::POOL_LIMIT + 1)->collect();
        $result['limited'] = $pool->count() > self::POOL_LIMIT;
        $pool = $pool->take(self::POOL_LIMIT)->values();
        $nodes = 0;
        $groups = [];
        $amounts = $pool->map(fn ($document) => $this->matching->cents($document->outstanding))->all();
        $add = function (array $indices) use (&$groups, $pool, $target): void {
            $groups[] = ['documents' => collect($indices)->map(fn ($index) => $pool[$index]), 'total_tetri' => $target];
        };
        $pairs = [];
        foreach ($amounts as $i => $amount) {
            if ($amount === $target) {
                $add([$i]);
            }
            for ($j = $i + 1; $j < count($amounts); $j++) {
                $sum = $amount + $amounts[$j];
                if ($sum <= $target) {
                    $pairs[$sum][] = [$i, $j];
                }
            }
        }
        foreach ($pairs[$target] ?? [] as $pair) {
            if (count($groups) >= 200) {
                break;
            }
            $add($pair);
        }
        // Meet in the middle: at most 1,770 indexed pairs for 60 documents.
        // Avoid a cubic traversal of nonmatching triples before testing four-invoice
        // sums. Disjoint ordered indices deduplicate groups within the work budget.
        foreach (range(3, self::MAX_DOCUMENTS) as $size) {
            $starts = $size === 3 ? array_map(fn ($i) => [$i], array_keys($amounts)) : collect($pairs)->flatten(1)->all();
            foreach ($starts as $start) {
                $remaining = $target - array_sum(array_map(fn ($i) => $amounts[$i], $start));
                foreach ($pairs[$remaining] ?? [] as $end) {
                    if (++$nodes >= self::NODE_LIMIT || count($groups) >= 200) {
                        break 3;
                    }
                    if ($end[0] > $start[array_key_last($start)]) {
                        $add([...$start, ...$end]);
                    }
                }
            }
        }
        $result['limited'] = $result['limited'] || $nodes >= self::NODE_LIMIT || count($groups) >= 200;
        $result['combinations'] = collect($groups)->sortBy(function ($group) use ($bank) {
            $documents = $group['documents'];

            return [$documents->sum(fn ($document) => abs($document->purchase_date->diffInDays($bank->transaction_date->copy()->startOfDay()))),
                $documents->filter(fn ($document) => $document->purchase_date->gt($bank->transaction_date->copy()->startOfDay()))->count(),
                -$documents->sum(fn ($document) => $this->referenceHit($bank, $document->document_number) ? 1 : 0),
                $documents->count(), $documents->pluck('id')->all()];
        })->take(3)->values()->all();
        $result['suggested_ids'] = collect($result['combinations'])->flatMap(fn ($group) => $group['documents']->pluck('id'))->unique()->values()->all();

        return $result;
    }

    private function eligibleDocuments($query): LazyCollection
    {
        return $query->with('items')->lazy(100)->filter(function ($purchase): bool {
            try {
                app(PurchaseReconciliation::class)->assertValid($purchase, items: $purchase->items);

                return $this->matching->cents($purchase->outstanding) > 0;
            } catch (ValidationException) {
                return false;
            }
        })->values();
    }

    private function order($query, BankTransaction $bank, int $target): void
    {
        $reference = DB::getDriverName() === 'sqlite'
            ? 'instr(LOWER(?), LOWER(purchases.document_number)) > 0'
            : 'POSITION(LOWER(purchases.document_number) IN LOWER(?)) > 0';
        $query->orderByRaw('ABS((dt.total - COALESCE(rs_used.amount, 0)) - ?)', [$target / 100])
            ->orderByRaw(DB::getDriverName() === 'sqlite' ? 'ABS(julianday(purchases.purchase_date) - julianday(?))' : 'ABS(CAST(purchases.purchase_date AS DATE) - CAST(? AS DATE))', [$bank->transaction_date->toDateString()])
            ->orderByRaw('CASE WHEN purchases.purchase_date <= ? THEN 0 ELSE 1 END', [$bank->transaction_date->toDateString()])
            ->orderByRaw("CASE WHEN COALESCE(purchases.document_number, '') <> '' AND {$reference} THEN 0 ELSE 1 END", [($bank->reference ?? '').' '.($bank->description ?? '')])
            ->orderBy('purchases.id');
    }

    private function referenceHit(BankTransaction $bank, ?string $reference): bool
    {
        $reference = mb_strtolower(trim($reference ?? ''));

        return $reference !== '' && preg_match('/(?<![\pL\pN])'.preg_quote($reference, '/').'(?![\pL\pN])/u', mb_strtolower(($bank->reference ?? '').' '.($bank->description ?? ''))) === 1;
    }

    private function tax(?string $value): ?string
    {
        $value = preg_replace('/[\s-]+/u', '', $value ?? '');

        return preg_match('/^(?:\d{9}|\d{11})$/D', $value) ? $value : null;
    }

    /** Only the explicit RS export prefix, never arbitrary digits within a name. */
    private function supplierLabel(?string $label): array
    {
        $label = trim($label ?? '');
        $tax = null;
        if (preg_match('/^\(\s*([0-9][0-9\s-]*[0-9])\s*\)\s*(.+)$/u', $label, $matches)) {
            $tax = $this->tax($matches[1]);
            if ($tax !== null) {
                $label = $matches[2];
            }
        }

        return [BankRuleMatcher::company($label), $tax];
    }

    /** Explicit counterparty fields only. Never search arbitrary account/reference digits. */
    private function taxIds(BankTransaction $bank): array
    {
        $keys = fn (array $data) => collect($data)->mapWithKeys(fn ($value, $key) => [strtolower(str_replace(['_', ' '], '', (string) $key)) => $value])->all();
        $raw = $keys($bank->raw_data ?? []);
        $values = [$this->supplierLabel($bank->counterparty_name)[1]];
        foreach (['counterpartytaxid', 'counterpartyinn', 'beneficiarytaxid', 'beneficiaryinn'] as $field) {
            if (is_scalar($raw[$field] ?? null)) {
                $values[] = $raw[$field];
            }
        }
        $party = $raw[$bank->direction === 'outflow' ? 'beneficiarydetails' : 'senderdetails'] ?? [];
        foreach (is_array($party) ? $keys($party) : [] as $key => $value) {
            if (in_array($key, ['inn', 'taxid', 'taxnumber', 'identificationnumber'], true) && is_scalar($value)) {
                $values[] = $value;
            }
        }
        foreach (is_array($raw['cells'] ?? null) ? $raw['cells'] : [] as $cell) {
            if (is_array($cell) && in_array(Supplier::normalizeName((string) ($cell['header'] ?? '')),
                ['counterparty tax id', 'beneficiary tax id', 'კონტრაგენტის საიდენტიფიკაციო კოდი', 'მიმღების საიდენტიფიკაციო კოდი'], true)
                && is_scalar($cell['value'] ?? null)) {
                $values[] = $cell['value'];
            }
        }

        return collect($values)->map(fn ($value) => $this->tax((string) $value))->filter()->unique()->values()->all();
    }
}
