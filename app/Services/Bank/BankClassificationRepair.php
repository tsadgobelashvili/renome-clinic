<?php

namespace App\Services\Bank;

use App\Models\User;
use App\Services\Finance\AccountingLedger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Forensic repair boundary: a suspicious name/pair alone never authorizes a write. */
class BankClassificationRepair
{
    public static function snapshot(object $row): string
    {
        $attributes = (array) $row;
        ksort($attributes);

        return hash('sha256', json_encode($attributes, JSON_THROW_ON_ERROR));
    }

    public function inspect(array $evidence = [], ?string $from = null, ?string $until = null): array
    {
        $context = app(BankClassificationService::class)->context();

        return DB::table('bank_transactions as b')
            ->where('b.direction', 'outflow')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('expense_categories as d')
                ->whereColumn('d.id', 'b.expense_direction_id')->where('d.name', 'სტერილიზაცია'))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('expense_categories as t')
                ->whereColumn('t.id', 'b.expense_type_id')->where('t.name', 'დასუფთავება'))
            ->when($from, fn ($q) => $q->where('b.created_at', '>=', $from.' 00:00:00'))
            ->when($until, fn ($q) => $q->where('b.created_at', '<=', $until.' 23:59:59'))
            ->orderBy('b.id')->get()->map(function ($row) use ($evidence, $context) {
                $hash = self::snapshot($row);
                $proof = $evidence[$row->id] ?? [];
                $mechanism = match ($row->classification_source) {
                    'manual' => 'manual', 'rule' => 'explicit_vendor_rule',
                    'default' => 'system_default', default => 'unknown_requires_evidence',
                };
                $reason = match ($row->classification_source) {
                    'manual' => 'Protected manual classification; preserve.',
                    'rule' => 'Protected vendor-rule classification; preserve.',
                    'default' => 'Protected system bank operation; preserve.',
                    default => 'No verified fallback evidence; preserve.',
                };
                $eligible = false;
                if ($row->classification_source === null && $row->categorization_rule_id === null
                    && in_array($row->source, ['import', 'api'], true)
                    && ! app(BankRuleMatcher::class)->match((array) $row, $context['rules'])
                    && ($proof['snapshot_sha256'] ?? '') === $hash
                    && ($proof['mechanism'] ?? '') === 'buggy_null_code_fallback'
                    && filled($proof['reference'] ?? null)
                    && array_key_exists('expense_direction_id', $proof['before'] ?? [])
                    && array_key_exists('expense_type_id', $proof['before'] ?? [])
                    && $proof['before']['expense_direction_id'] === null && $proof['before']['expense_type_id'] === null) {
                    $mechanism = 'proven_buggy_fallback';
                    // Classification must not be the only fact recognizing this expense.
                    $category = DB::table('bank_categories')->where('id', $row->bank_category_id)->first();
                    $eligible = $category?->accounting_treatment === 'expense' || $row->expense_category_id !== null
                        || DB::table('bank_purchase_matches')->where('bank_transaction_id', $row->id)->exists();
                    $reason = $eligible ? 'Clear only expense_direction_id and expense_type_id.'
                        : 'Blocked: clearing would remove expense recognition. Preserve for Owner review.';
                }

                return ['id' => $row->id, 'date' => $row->transaction_date, 'created_at' => $row->created_at,
                    'vendor' => $row->counterparty_name, 'amount' => $row->amount, 'currency' => $row->currency,
                    'current_classification' => ['expense_direction_id' => $row->expense_direction_id,
                        'expense_type_id' => $row->expense_type_id, 'classification_source' => $row->classification_source,
                        'categorization_rule_id' => $row->categorization_rule_id],
                    'mechanism' => $mechanism, 'eligible' => $eligible, 'proposal' => $reason,
                    'snapshot_sha256' => $hash, 'evidence_reference' => $proof['reference'] ?? null];
            })->all();
    }

    public function apply(array $evidence, User $owner, ?string $from = null, ?string $until = null): array
    {
        abort_unless($owner->isOwner(), 403);

        return DB::transaction(function () use ($evidence, $from, $until) {
            // Lock evidence targets before repeating the diagnostic / snapshot checks.
            DB::table('bank_transactions')->whereIn('id', array_keys($evidence))->orderBy('id')->lockForUpdate()->get();
            $before = app(AccountingLedger::class)->pnlTotals(null, null)->toJson();
            $rows = $this->inspect($evidence, $from, $until);
            foreach ($rows as $row) {
                if ($row['eligible']) {
                    // Query builder deliberately bypasses inference and leaves timestamps/history intact.
                    DB::table('bank_transactions')->where('id', $row['id'])->update([
                        'expense_direction_id' => null, 'expense_type_id' => null,
                    ]);
                }
            }
            if ($before !== app(AccountingLedger::class)->pnlTotals(null, null)->toJson()) {
                throw new RuntimeException('Repair rolled back: Expense/Profit totals would change.');
            }

            return $rows;
        });
    }
}
