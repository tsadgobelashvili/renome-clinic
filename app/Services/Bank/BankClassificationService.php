<?php

namespace App\Services\Bank;

use App\Models\BankCategorizationRule;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Services\ExpenseDimensions;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BankClassificationService
{
    /** Fixed reads per import/backfill, including the request-scoped dimension registry. */
    public function context(): array
    {
        $categories = BankCategory::where('active', true)->get()->keyBy('code');
        $rules = BankCategorizationRule::query()->from('bank_categorization_rules as r')
            ->leftJoin('bank_categories as legacy', 'legacy.id', '=', 'r.bank_category_id')
            ->leftJoin('expense_categories as ec', 'ec.id', '=', DB::raw('COALESCE(r.expense_category_id, legacy.expense_category_id)'))
            ->leftJoin('expense_categories as et', 'et.id', '=', 'r.expense_type_id')
            ->leftJoin('expense_categories as ed', 'ed.id', '=', 'r.expense_direction_id')
            ->leftJoin('expense_subcategories as es', 'es.id', '=', 'r.expense_subcategory_id')
            ->where('r.active', true)->where(fn ($q) => $q->where(fn ($q) => $q->whereNotNull('r.expense_type_id')->where('et.active', true)
            ->where('ed.active', true)->whereColumn('et.parent_id', 'r.expense_direction_id'))
            ->orWhere(fn ($q) => $q->whereNull('r.expense_type_id')->where('ec.active', true)))
            ->where(fn ($q) => $q->whereNotNull('r.expense_type_id')->orWhereNull('r.expense_subcategory_id')->orWhere(fn ($q) => $q->where('es.active', true)->whereColumn('es.expense_category_id', 'ec.id')))
            ->select('r.*')->selectRaw('ec.id AS resolved_category_id')->orderBy('r.id')->get();
        foreach ($rules as $rule) {
            $rule->expense_category_id = $rule->resolved_category_id;
            // Retained legacy fields are audit/compatibility inputs, never a second category list.
            if (! $rule->counterparty && ! $rule->purpose_keyword && ! $rule->counterparty_account) {
                if ($rule->field === 'counterparty') {
                    $rule->counterparty = $rule->phrase;
                } else {
                    $rule->purpose_keyword = $rule->phrase;
                }
            }
        }

        $dimensions = app(ExpenseDimensions::class);

        $general = $dimensions->id('direction', 'general');

        return compact('categories', 'rules') + ['fee_dimensions' => ['expense_direction_id' => $general, 'expense_type_id' => $dimensions->id('type', 'bank_fee', $general)]];
    }

    public function classify(array $data, array $context, ?int $onlyRule = null): array
    {
        $empty = ['bank_category_id' => $context['categories']->get('uncategorized')?->id, 'expense_category_id' => null,
            'expense_subcategory_id' => null, 'categorization_rule_id' => null, 'classification_source' => null];
        if (strtoupper($data['bank'] ?? '') === 'BOG') {
            $type = strtoupper(trim($data['operation_type'] ?? ''));
            $code = match (true) {
                $type === 'COM' && $data['direction'] === 'outflow' => 'bank_fee',
                $type === 'PBS' => 'cash_deposit',
                $type === 'TRN' && $data['direction'] === 'inflow' && $this->isCardSettlement($data['description'] ?? '') => 'card_settlement',
                default => null,
            };
            if ($code && ($category = $context['categories']->get($code))) {
                return [...$empty, ...($code === 'bank_fee' ? $context['fee_dimensions'] : []), 'bank_category_id' => $category->id, 'expense_category_id' => $category->accounting_treatment === 'expense' ? $category->expense_category_id : null, 'classification_source' => 'default'];
            }
        }
        $rule = app(BankRuleMatcher::class)->match($data, $context['rules']);
        if ($rule && ($onlyRule === null || $rule->id === $onlyRule) && $data['direction'] === 'outflow') {
            return [...$empty, 'bank_category_id' => $context['categories']->get('operating_expense')?->id,
                'expense_category_id' => $rule->expense_category_id, 'expense_subcategory_id' => $rule->expense_subcategory_id,
                'expense_direction_id' => $rule->expense_direction_id, 'expense_type_id' => $rule->expense_type_id,
                'categorization_rule_id' => $rule->id, 'classification_source' => 'rule'];
        }

        return $empty;
    }

    private function isCardSettlement(string $description): bool
    {
        if (preg_match('/ბარათი\s*:\s*(?:MC|VISA|AMEXD?|MAESTRO)\b/iu', $description)
            && preg_match('/ტერმინალის\s+ID\s*:\s*POS[A-Z0-9]+\b/iu', $description)) {
            return true;
        }

        return preg_match('/\b(?:pos|card|merchant)\s+settlement\b|settlement of card transactions|საბარათე ანგარიშსწორება|ბარათებით განხორციელებული ოპერაციების ანგარიშსწორება/iu', $description) === 1;
    }

    public function applyToUncategorized(?int $onlyRule = null): int
    {
        $context = $this->context();
        $count = 0;
        $uncategorized = $context['categories']->get('uncategorized')?->id;
        BankTransaction::where(fn ($q) => $q->whereNull('bank_category_id')->when($uncategorized, fn ($q) => $q->orWhere('bank_category_id', $uncategorized)))
            ->whereNull('expense_category_id')->whereNull('expense_type_id')->whereNull('classification_source')
            ->select(['id', 'bank_category_id', 'bank', 'operation_type', 'direction', 'description', 'counterparty_name', 'counterparty_account'])
            ->chunkById(250, function (Collection $rows) use ($context, $onlyRule, &$count): void {
                $groups = [];
                foreach ($rows as $row) {
                    $classification = $this->classify($row->getAttributes(), $context, $onlyRule);
                    if ($onlyRule !== null && $classification['categorization_rule_id'] !== $onlyRule) {
                        continue;
                    }
                    if ($classification['classification_source'] !== null) {
                        $key = json_encode($classification);
                        $groups[$key]['classification'] = $classification;
                        $groups[$key]['ids'][] = $row->id;
                    }
                }
                foreach ($groups as $group) {
                    $count += BankTransaction::whereIn('id', $group['ids'])->whereNull('classification_source')
                        ->whereNull('expense_category_id')->whereNull('expense_type_id')->update($group['classification']);
                }
            });

        return $count;
    }
}
