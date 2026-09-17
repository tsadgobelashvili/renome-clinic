<?php

namespace App\Services\Bank;

use Illuminate\Support\Collection;

class BankRuleMatcher
{
    public static function text(?string $value): string
    {
        return mb_strtolower(trim(preg_replace('/[\s\x{00a0}]+/u', ' ', $value ?? '')));
    }

    public static function company(?string $value): string
    {
        $name = self::text($value);
        $name = preg_replace('/^(?:llc\.?|შპს\.?|შ\.\s*პ\.\s*ს\.?)\s+/iu', '', $name);

        // PHP trim's byte-based character list can corrupt Georgian letter endings.
        return preg_replace('/^[\s"\x{0027}„“”«»]+|[\s"\x{0027}„“”«»]+$/u', '', $name);
    }

    public static function account(?string $value): string
    {
        return mb_strtoupper(preg_replace('/\s+/u', '', $value ?? ''));
    }

    /** Same specificity with different targets is ambiguous, never first-rule-wins. */
    public function match(array $data, Collection $rules): ?object
    {
        $matches = [];
        foreach ($rules as $rule) {
            $company = self::company($rule->counterparty);
            $keyword = self::text($rule->purpose_keyword);
            $account = self::account($rule->counterparty_account);
            if ($company === '' && $keyword === '' && $account === '') {
                continue;
            }
            if (($company !== '' && $company !== self::company($data['counterparty_name'] ?? null))
                || ($account !== '' && $account !== self::account($data['counterparty_account'] ?? null))
                || ($keyword !== '' && ! str_contains(self::text($data['description'] ?? null), $keyword))) {
                continue;
            }
            $identity = $company !== '' || $account !== '';
            $score = ($identity ? ($keyword !== '' ? 30 : 20) : 10) + ($account !== '' ? 1 : 0);
            $matches[$score][] = $rule;
        }
        if ($matches === []) {
            return null;
        }
        krsort($matches);
        $best = collect(reset($matches));
        if ($best->map(fn ($rule) => $rule->expense_type_id ? $rule->expense_direction_id.':'.$rule->expense_type_id : 'legacy:'.$rule->expense_category_id.':'.$rule->expense_subcategory_id)->unique()->count() !== 1) {
            return null;
        }

        return $best->sortBy('id')->first();
    }
}
