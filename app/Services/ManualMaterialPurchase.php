<?php

namespace App\Services;

use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\PurchaseProductGroup;
use App\Support\PurchaseQuantity;
use Illuminate\Validation\ValidationException;

/** Optional metadata on the original expense. Never posts money or creates invoices/catalog identities. */
class ManualMaterialPurchase
{
    public function prepare(array $data, FinanceTransaction|PartnerFinanceTransaction|null $record = null): array
    {
        if (! array_key_exists('material_product_group_id', $data)) {
            return $data;
        }
        $group = $data['material_product_group_id'];
        unset($data['material_product_group_id']);
        if (blank($group)) {
            unset($data['material_quantity'], $data['material_unit']);
            if ($record?->material_quantity !== null) {
                $data = array_replace($data, ['material_quantity' => null, 'material_unit' => null, 'purchase_product_group_id' => null]);
            }

            return $data;
        }
        validator(['material_product_group_id' => $group], ['material_product_group_id' => 'required|integer|exists:purchase_product_groups,id'])->validate();
        if (app(PurchaseCatalog::class)->isUnclassifiedName(PurchaseProductGroup::findOrFail($group)->name)) {
            throw ValidationException::withMessages(['material_product_group_id' => __('expense-dimensions.invalid')]);
        }
        $details = $data;
        if ($record) {
            $details += ['material_quantity' => $record->material_quantity, 'material_unit' => $record->material_unit,
                'description' => $record instanceof FinanceTransaction ? $record->description : $record->recipient];
        }
        validator($details, [
            'material_quantity' => ['required', 'numeric', 'gt:0', 'lt:100000000000000'],
            'material_unit' => 'nullable|string|max:255',
            'description' => 'required|string|max:255',
        ])->validate();
        $data['purchase_product_group_id'] = (int) $group;
        $data['material_quantity'] = $this->quantity($details['material_quantity']);
        $data['material_unit'] = trim($details['material_unit'] ?? '') ?: 'pcs';

        return $data;
    }

    public function validate(FinanceTransaction|PartnerFinanceTransaction $record): void
    {
        $quantity = $record->getAttributes()['material_quantity'] ?? null;
        if ($quantity === null) {
            return; // Historical catalog classifications have no reliable quantity; leave them unchanged.
        }
        $name = $record instanceof FinanceTransaction ? $record->description : $record->recipient;
        validator(['material_quantity' => $quantity, 'material_unit' => $record->material_unit,
            'purchase_product_group_id' => $record->purchase_product_group_id, 'description' => $name], [
            'material_quantity' => 'required|numeric|gt:0|lt:100000000000000',
            'material_unit' => 'required|string|max:255',
            'purchase_product_group_id' => 'required|integer|exists:purchase_product_groups,id',
            'description' => 'required|string|max:255',
        ])->validate();
        $record->material_quantity = $this->quantity($quantity);
        if ($record->type !== 'expense' || $record->getAttribute('purchase_id')
            || $record->getAttribute('finance_transaction_id') || $record->salary_settlement_id
            || $record->getAttribute('payroll_entry_id') || $record->getAttribute('employee_salary_settlement_id')
            || $record->lab_salary_settlement_id || $record->salary_payout_allocation_id) {
            throw ValidationException::withMessages(['material_product_group_id' => __('expense-dimensions.invalid')]);
        }
    }

    private function quantity(int|float|string $value): string
    {
        try {
            return PurchaseQuantity::canonical($value);
        } catch (\Brick\Math\Exception\MathException) {
            throw ValidationException::withMessages(['material_quantity' => __('validation.regex', ['attribute' => __('expense-entry.quantity')])]);
        }
    }
}
