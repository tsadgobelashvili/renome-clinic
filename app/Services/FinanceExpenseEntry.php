<?php

namespace App\Services;

use App\Models\ExpenseCategory;
use App\Models\PurchaseProduct;
use Illuminate\Validation\ValidationException;

/** New Main Finance entry boundary; historical classifications are never rewritten. */
class FinanceExpenseEntry
{
    public function typeOptions(): array
    {
        return app(ExpenseDimensions::class)->registry()
            ->filter(fn ($type) => $type->classification_dimension === 'type' && $type->active
                && ! app(PurchaseCatalog::class)->isUnclassifiedName($type->name))
            ->sortBy(fn ($type) => [$type->parent_id ? 1 : 0, $type->sort_order])->unique('name')
            ->mapWithKeys(fn ($type) => [$type->id => $type->classification_code === 'materials'
                ? __('expense-entry.product_purchase') : ExpenseDimensions::label($type)])->all();
    }

    public function isProductPurchase(mixed $id): bool
    {
        $type = app(ExpenseDimensions::class)->registry()->get($id);

        return $type && ! $type->parent_id && $type->classification_dimension === 'type' && $type->classification_code === 'materials';
    }

    public function createType(string $name): int
    {
        abort_unless(auth()->user()?->canManageOwnerModules(), 403);
        $name = trim($name);
        validator(['name' => $name], ['name' => 'required|string|max:255|not_in:Unclassified,დასაზუსტებელია'])->validate();
        if (app(PurchaseCatalog::class)->isUnclassifiedName($name)) {
            throw ValidationException::withMessages(['name' => __('expense-dimensions.invalid')]);
        }
        $existing = ExpenseCategory::where('classification_dimension', 'type')->whereNull('parent_id')->where('name', $name)->first();
        if ($existing) {
            if (! $existing->active) {
                throw ValidationException::withMessages(['name' => __('expense-dimensions.invalid')]);
            }

            return $existing->id;
        }
        $type = new ExpenseCategory;
        $type->forceFill(['name' => $name, 'active' => true, 'sort_order' => 0, 'classification_dimension' => 'type'])->save();

        return $type->id;
    }

    public function prepare(array $data): array
    {
        $type = $data['entry_expense_type_id'] ?? null;
        if (! array_key_exists($type ?? '', $this->typeOptions())) {
            throw ValidationException::withMessages(['entry_expense_type_id' => __('expense-dimensions.invalid')]);
        }
        $dimensions = app(ExpenseDimensions::class);
        $direction = filled($data['expense_direction_id'] ?? null) ? (int) $data['expense_direction_id'] : $dimensions->id('direction', 'general');
        if (! array_key_exists($direction ?? '', app(PurchaseCatalog::class)->directionOptions())) {
            throw ValidationException::withMessages(['expense_direction_id' => __('expense-dimensions.invalid')]);
        }
        if ($this->isProductPurchase($type)) {
            $product = PurchaseProduct::find($data['purchase_product_id'] ?? null);
            if (! $product) {
                throw ValidationException::withMessages(['purchase_product_id' => __('validation.required', ['attribute' => __('expense-entry.product')])]);
            }
            // Store the group at posting time. Later catalog edits must not reclassify this expense.
            $data['purchase_product_group_id'] = $product->purchase_product_group_id;
            $data['description'] = filled($data['description'] ?? null) ? $data['description'] : $product->name;
        } else {
            $data['purchase_product_id'] = null;
            $data['purchase_product_group_id'] = null;
        }
        $data['expense_direction_id'] = $direction;
        $selected = $dimensions->registry()->get($type);
        if ($selected->parent_id && $selected->parent_id !== $direction) {
            // Reuse an existing custom type for a new direction without moving its old category.
            $root = ExpenseCategory::where('classification_dimension', 'type')->whereNull('parent_id')->where('name', $selected->name)->first();
            if (! $root) {
                $root = new ExpenseCategory;
                $root->forceFill(['name' => $selected->name, 'classification_dimension' => 'type',
                    'classification_code' => $selected->classification_code, 'active' => true, 'sort_order' => $selected->sort_order])->save();
            }
            $type = $root->id;
        }
        $data['expense_type_id'] = $dimensions->childForType($direction, (int) $type);
        $dimensions->validate($data, required: true);
        unset($data['entry_expense_type_id']);

        return $data;
    }
}
