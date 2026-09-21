<?php

namespace App\Services\Bank;

use App\Models\BankTransaction;
use App\Models\EmployeeAdvance;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\PurchaseExpenseAllocation;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Confirmed analytical links only. Never posts Finance, Cashier or payment records. */
class BankPurchaseMatching
{
    public function confirm(int $transactionId, array $documents, User $actor): void
    {
        abort_unless($actor->isOwner(), 403);
        validator(['documents' => $documents], [
            'documents' => 'array|max:100', 'documents.*.purchase_id' => 'required|integer|distinct',
            'documents.*.amount' => 'required|numeric|gt:0|decimal:0,2',
        ])->validate();

        DB::transaction(function () use ($transactionId, $documents, $actor): void {
            $bank = BankTransaction::lockForUpdate()->findOrFail($transactionId);
            if ($documents !== []) {
                $this->validateBank($bank);
            }
            // Lock documents in a stable order: concurrent payments cannot consume the same invoice twice.
            $ids = collect($documents)->pluck('purchase_id')->map(fn ($id) => (int) $id);
            $oldIds = DB::table('bank_purchase_matches')->where('bank_transaction_id', $bank->id)->pluck('purchase_id');
            $purchases = Purchase::whereIn('id', $ids->merge($oldIds)->unique())->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if (Purchase::whereIn('id', $ids)->where(fn ($q) => $q->whereHas('cashExpense')->orWhereHas('advanceSettlement'))->exists()) {
                throw ValidationException::withMessages(['rsDocuments' => 'ქეშით გადახდილი RS დოკუმენტის ბანკთან მიბმა შეუძლებელია.']);
            }
            $used = DB::table('bank_purchase_matches')->whereIn('purchase_id', $ids)->where('bank_transaction_id', '!=', $bank->id)
                ->selectRaw('purchase_id, SUM(amount) AS amount')->groupBy('purchase_id')->pluck('amount', 'purchase_id');
            $totals = $this->documentTotals()->whereIn('purchase_id', $ids)->get()->keyBy('purchase_id');
            foreach ($documents as $document) {
                $purchase = $purchases->get($document['purchase_id']);
                $total = $totals->get($document['purchase_id']);
                if (! $purchase || $purchase->source !== 'rs' || ! $total || $total->minimum < 0 || $total->total <= 0
                    || $this->cents($document['amount']) + $this->cents($used[$purchase->id] ?? 0) > $this->cents($total->total)) {
                    throw ValidationException::withMessages(['rsDocuments' => __('bank-rs.invalid_document')]);
                }
            }
            DB::table('bank_purchase_matches')->where('bank_transaction_id', $bank->id)->delete();
            if ($documents !== []) {
                DB::table('bank_purchase_matches')->insert(array_map(fn ($document) => [
                    'bank_transaction_id' => $bank->id, 'purchase_id' => $document['purchase_id'],
                    'amount' => number_format($this->cents($document['amount']) / 100, 2, '.', ''),
                    'confirmed_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
                ], $documents));
            }
        });
    }

    public function validateBank(BankTransaction $bank): void
    {
        if (EmployeeAdvance::where('bank_transaction_id', $bank->id)->exists()) {
            throw ValidationException::withMessages(['rsDocuments' => 'საბანკო გასავალი უკვე მიბმულია თანამშრომლის ავანსზე.']);
        }
        $treatment = $bank->category?->accounting_treatment;
        if ($bank->direction !== 'outflow' || $bank->currency !== 'GEL' || $bank->amount <= 0
            || $bank->operation_type === 'COM' || ($bank->category?->code !== 'uncategorized' && in_array($treatment, ['transfer', 'settlement', 'exclude', 'income'], true))) {
            throw ValidationException::withMessages(['rsDocuments' => __('bank-rs.invalid_bank')]);
        }
    }

    /** Bounded, on-demand suggestions. Names reuse the Supplier normalization; no fuzzy auto-matching. */
    public function suggestions(BankTransaction $bank, string $search = ''): Collection
    {
        $name = Supplier::normalizeName($bank->counterparty_name ?? '');
        $query = Purchase::query()->join('suppliers as s', 's.id', '=', 'purchases.supplier_id')
            ->whereDoesntHave('cashExpense')
            ->whereDoesntHave('advanceSettlement')
            ->leftJoinSub($this->documentTotals(), 'dt', 'dt.purchase_id', '=', 'purchases.id')
            ->where('purchases.source', 'rs')->where('dt.total', '>', 0)
            ->select('purchases.id', 'purchases.document_number', 'purchases.purchase_date', 's.name as supplier_name', 's.tax_id', 'dt.total');
        if (trim($search) !== '') {
            $pattern = '%'.Supplier::normalizeName($search).'%';
            $query->where(fn ($q) => $q->where('s.normalized_name', 'like', $pattern)->orWhere('s.tax_id', 'like', $pattern)->orWhereRaw('LOWER(purchases.document_number) LIKE ?', [$pattern]));
        }

        return $query->orderByRaw('CASE WHEN s.normalized_name = ? AND s.normalized_name <> ? THEN 0 WHEN s.tax_id IS NOT NULL AND s.tax_id <> ? AND ? LIKE (\'%\' || s.tax_id || \'%\') THEN 1 ELSE 2 END', [$name, '', '', $bank->description ?? ''])
            ->orderByRaw('ABS(dt.total - ?)', [$bank->amount])
            ->orderByRaw(DB::getDriverName() === 'sqlite' ? 'ABS(julianday(purchases.purchase_date) - julianday(?))' : 'ABS(CAST(purchases.purchase_date AS DATE) - CAST(? AS DATE))', [$bank->transaction_date->toDateString()])
            ->orderByDesc('purchases.purchase_date')->limit(40)->get();
    }

    public function documentTotals(bool $matchedOnly = false): Builder
    {
        return DB::table('purchase_items')
            ->when($matchedOnly, fn ($q) => $q->whereExists(fn ($m) => $m->selectRaw('1')->from('bank_purchase_matches as linked')->whereColumn('linked.purchase_id', 'purchase_items.purchase_id')))
            ->selectRaw('purchase_id, SUM(line_total) AS total, MIN(line_total) AS minimum')->groupBy('purchase_id');
    }

    /** Live groups, including unknown products; integer cents conserve each confirmed payment portion. */
    public function allocations(): Builder
    {
        $payments = DB::table('bank_purchase_matches')->select('id', 'bank_transaction_id as entry_id', 'purchase_id', 'amount');

        return DB::query()->fromSub(app(PurchaseExpenseAllocation::class)->shares($payments), 'shares')
            ->select('entry_id as bank_transaction_id', 'purchase_id', 'expense_direction_id', 'amount');
    }

    /** One aggregate row per bank transaction, also shared by the status filter and report. */
    public function summaryQuery(): Builder
    {
        $used = DB::table('bank_purchase_matches')->selectRaw('purchase_id, SUM(amount) AS amount')->groupBy('purchase_id');
        $matched = DB::table('bank_purchase_matches as m')->leftJoinSub($this->documentTotals(true), 'dt', 'dt.purchase_id', '=', 'm.purchase_id')
            ->joinSub($used, 'used', 'used.purchase_id', '=', 'm.purchase_id')
            ->selectRaw('m.bank_transaction_id, COUNT(*) AS documents_count, SUM(m.amount) AS matched_amount, SUM(COALESCE(dt.total, 0)) AS document_total,
                MAX(CASE WHEN dt.total IS NULL OR dt.total <= 0 OR dt.minimum < 0 OR used.amount > dt.total THEN 1 ELSE 0 END) AS invalid_documents')
            ->groupBy('m.bank_transaction_id');
        $allocated = DB::query()->fromSub($this->allocations(), 'alloc')->selectRaw('bank_transaction_id, SUM(CASE WHEN expense_direction_id IS NOT NULL THEN amount ELSE 0 END) AS allocated')
            ->groupBy('bank_transaction_id');
        $summary = DB::table('bank_transactions as bank')->joinSub($matched, 'm', 'm.bank_transaction_id', '=', 'bank.id')
            ->leftJoinSub($allocated, 'a', 'a.bank_transaction_id', '=', 'bank.id')
            ->selectRaw('bank.id AS bank_transaction_id, m.documents_count, m.matched_amount, m.document_total,
                bank.amount - m.document_total AS difference,
                CASE WHEN m.invalid_documents = 0 AND m.matched_amount <= bank.amount THEN 1 ELSE 0 END AS usable,
                CASE WHEN m.invalid_documents = 0 AND m.matched_amount <= bank.amount THEN COALESCE(a.allocated, 0) ELSE 0 END AS allocated,
                bank.amount AS bank_amount');

        return DB::query()->fromSub($summary, 'rs')->select('rs.*')->selectRaw("bank_amount - allocated AS unallocated,
            CASE WHEN usable = 1 AND ABS(bank_amount - allocated) < 0.005 AND ABS(difference) < 0.005 THEN 'matched' ELSE 'partial' END AS status");
    }

    public function summary(int $transactionId): ?object
    {
        return $this->summaryQuery()->where('bank_transaction_id', $transactionId)->first();
    }

    /** All analytical shares plus an explicit residual; their sum is exactly the single bank debit. */
    public function distribution(): Builder
    {
        $known = DB::query()->fromSub($this->allocations(), 'a')
            ->joinSub($this->summaryQuery(), 's', 's.bank_transaction_id', '=', 'a.bank_transaction_id')
            ->where('s.usable', 1)->whereNotNull('a.expense_direction_id')
            ->select('a.bank_transaction_id', 'a.expense_direction_id')->selectRaw('SUM(a.amount) AS amount')
            ->groupBy('a.bank_transaction_id', 'a.expense_direction_id');
        $residual = DB::query()->fromSub($this->summaryQuery(), 'saved_rs')->where('unallocated', '>', 0)
            ->select('bank_transaction_id')->selectRaw('CAST(NULL AS BIGINT) AS expense_direction_id, unallocated AS amount');

        return DB::query()->fromSub($known->unionAll($residual), 'rs_distribution');
    }

    private function cents(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
