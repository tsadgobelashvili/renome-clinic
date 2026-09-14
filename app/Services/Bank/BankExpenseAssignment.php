<?php

namespace App\Services\Bank;

use App\Models\BankCategorizationRule;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BankExpenseAssignment
{
    public function validateTarget(?int $category, ?int $subcategory, ?BankTransaction $record = null): void
    {
        if (! $category) {
            if ($subcategory) {
                throw ValidationException::withMessages(['expense_category_id' => __('expense-categories.invalid')]);
            }

            return;
        }
        $parent = ExpenseCategory::find($category);
        if (! $parent || (! $parent->active && $record?->expense_category_id !== $category)) {
            throw ValidationException::withMessages(['expense_category_id' => __('expense-categories.invalid')]);
        }
        if ($subcategory) {
            $child = ExpenseSubcategory::find($subcategory);
            if (! $child || $child->expense_category_id !== $category || ((! $child->active || ! $parent->active) && $record?->expense_subcategory_id !== $subcategory)) {
                throw ValidationException::withMessages(['expense_subcategory_id' => __('expense-categories.invalid')]);
            }
        }
    }

    public function saveRule(array $data, User $actor, ?int $id = null): BankCategorizationRule
    {
        abort_unless($actor->isOwner(), 403);
        $data = validator($data, ['counterparty' => 'nullable|string|max:255', 'purpose_keyword' => 'nullable|string|max:255',
            'counterparty_account' => 'nullable|string|max:255', 'expense_category_id' => 'required|integer', 'expense_subcategory_id' => 'nullable|integer',
            'active' => 'required|boolean', 'confirm_company_default' => 'boolean'])->validate();
        foreach (['counterparty', 'purpose_keyword', 'counterparty_account'] as $field) {
            $data[$field] = trim($data[$field] ?? '') ?: null;
        }
        $company = BankRuleMatcher::company($data['counterparty']);
        if ($company === '' && ! $data['counterparty_account'] && ! $data['purpose_keyword']) {
            throw ValidationException::withMessages(['purpose_keyword' => __('bank-rules.need_match')]);
        }
        if (! $data['purpose_keyword'] && empty($data['confirm_company_default'])) {
            throw ValidationException::withMessages(['confirm_company_default' => __('bank-rules.confirm_default')]);
        }
        $this->validateTarget((int) $data['expense_category_id'], isset($data['expense_subcategory_id']) ? (int) $data['expense_subcategory_id'] : null);
        unset($data['confirm_company_default']);
        $rule = $id ? BankCategorizationRule::lockForUpdate()->findOrFail($id) : new BankCategorizationRule;
        $rule->fill([...$data, 'field' => $data['counterparty'] ? 'counterparty' : 'description',
            'phrase' => $data['counterparty'] ?? $data['purpose_keyword'] ?? $data['counterparty_account'],
            'bank_category_id' => null])->save();

        return $rule;
    }

    public function assign(int $id, array $data, User $actor): BankTransaction
    {
        abort_unless($actor->isOwner(), 403);
        validator($data, ['expense_category_id' => 'nullable|integer', 'expense_subcategory_id' => 'nullable|integer',
            'remember' => 'sometimes|boolean', 'update_rule' => 'sometimes|boolean', 'apply_existing' => 'sometimes|boolean',
            'use_account' => 'sometimes|boolean', 'confirm_company_default' => 'sometimes|boolean', 'purpose_keyword' => 'nullable|string|max:255'])->validate();

        return DB::transaction(function () use ($id, $data, $actor) {
            $record = BankTransaction::lockForUpdate()->findOrFail($id);
            $category = filled($data['expense_category_id'] ?? null) ? (int) $data['expense_category_id'] : null;
            $subcategory = filled($data['expense_subcategory_id'] ?? null) ? (int) $data['expense_subcategory_id'] : null;
            $this->validateTarget($category, $subcategory, $record);
            if ($record->direction !== 'outflow') {
                throw ValidationException::withMessages(['expense_category_id' => __('bank-rules.outflow_only')]);
            }
            $rule = null;
            if (! empty($data['remember']) || ! empty($data['update_rule'])) {
                if (! empty($data['update_rule']) && ! $record->categorization_rule_id) {
                    throw ValidationException::withMessages(['update_rule' => __('bank-rules.no_rule')]);
                }
                $rule = $this->saveRule(['counterparty' => $record->counterparty_name, 'purpose_keyword' => $data['purpose_keyword'] ?? null,
                    'counterparty_account' => ! empty($data['use_account']) ? $record->counterparty_account : null,
                    'expense_category_id' => $category, 'expense_subcategory_id' => $subcategory, 'active' => ! empty($data['update_rule']) ? (bool) $record->categorizationRule?->active : true,
                    'confirm_company_default' => $data['confirm_company_default'] ?? false], $actor,
                    ! empty($data['update_rule']) ? $record->categorization_rule_id : null);
            }
            $record->update(['expense_category_id' => $category, 'expense_subcategory_id' => $subcategory,
                'bank_category_id' => $category ? BankCategory::where('code', 'operating_expense')->value('id')
                    : ($record->expense_category_id ? BankCategory::where('code', 'uncategorized')->value('id') : $record->bank_category_id),
                'classification_source' => 'manual', 'include_embedded_fee' => false,
                'categorization_rule_id' => $rule?->id ?? $record->categorization_rule_id]);
            if ($rule && ! empty($data['apply_existing'])) {
                app(BankClassificationService::class)->applyToUncategorized($rule->id);
            }

            return $record;
        });
    }
}
