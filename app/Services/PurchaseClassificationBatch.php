<?php

namespace App\Services;

use App\Models\PurchaseProduct;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Only catalog classifications are written. Validation precedes one bulk UPDATE. */
class PurchaseClassificationBatch
{
    public static function selection(array $row): array
    {
        return [
            'expense_direction_id' => filled($row['expense_direction_id'] ?? null) ? (int) $row['expense_direction_id'] : null,
            'purchase_product_group_id' => filled($row['purchase_product_group_id'] ?? null) ? (int) $row['purchase_product_group_id'] : null,
        ];
    }

    public function save(array $pending, array $originals, bool $applyIdentical = false, ?array $confirmedPlan = null): int
    {
        Gate::authorize('update', new PurchaseProduct);
        validator(['pendingClassifications' => $pending], [
            'pendingClassifications' => 'array',
            'pendingClassifications.*' => 'array:expense_direction_id,purchase_product_group_id',
            'pendingClassifications.*.expense_direction_id' => 'nullable|integer|min:1',
            'pendingClassifications.*.purchase_product_group_id' => 'nullable|integer|min:1',
        ])->validate();
        $changes = [];
        foreach ($pending as $id => $row) {
            if (! ctype_digit((string) $id) || ! isset($originals[$id])) {
                throw ValidationException::withMessages(['pendingClassifications' => __('catalog-classification.unknown_row')]);
            }
            if (self::selection($row) !== self::selection($originals[$id])) {
                $changes[(int) $id] = self::selection($row);
            }
        }
        if ($changes === []) {
            return 0;
        }

        return DB::transaction(function () use ($changes, $originals, $applyIdentical, $confirmedPlan): int {
            $identities = app(PurchaseProductIdentity::class);
            $plan = $identities->preview($changes, $originals, true);
            if ($applyIdentical && ($confirmedPlan === null || $plan !== $confirmedPlan)) {
                throw ValidationException::withMessages(['pendingClassifications' => __('catalog-classification.conflict')]);
            }
            $products = PurchaseProduct::whereKey(array_keys($changes))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $groups = app(PurchaseCatalog::class)->classificationGroups();
            $dimensions = app(ExpenseDimensions::class);
            $errors = [];
            $updates = [];
            foreach ($changes as $id => $row) {
                $product = $products->get($id);
                $field = 'pendingClassifications.'.$id.'.';
                $label = ($originals[$id]['name'] ?? '#'.$id).' (#'.$id.')';
                if (! $product) {
                    $errors[$field.'expense_direction_id'] = $label.': '.__('catalog-classification.conflict');

                    continue;
                }
                Gate::authorize('update', $product);
                if (self::selection($product->getAttributes()) !== self::selection($originals[$id])
                    || $product->expense_type_id != ($originals[$id]['expense_type_id'] ?? null)) {
                    $errors[$field.'expense_direction_id'] = $label.': '.__('catalog-classification.conflict');

                    continue;
                }
                $type = $row['expense_direction_id'] === self::selection($product->getAttributes())['expense_direction_id'] ? $product->expense_type_id : null;
                try {
                    $dimensions->validate(['expense_direction_id' => $row['expense_direction_id'], 'expense_type_id' => $type], $product);
                    $direction = $row['expense_direction_id'] ? $dimensions->registry()->get($row['expense_direction_id']) : null;
                    if ($direction && $direction->parent_id) {
                        throw ValidationException::withMessages(['expense_direction_id' => __('expense-dimensions.invalid')]);
                    }
                } catch (ValidationException $exception) {
                    foreach ($exception->errors() as $key => $messages) {
                        $errors[$field.($key === 'expense_type_id' ? 'expense_direction_id' : $key)] = $label.': '.implode(' ', $messages);
                    }
                }
                $group = $row['purchase_product_group_id'];
                if ($group !== null && (! $row['expense_direction_id'] || ! ($groups[$group]['selectable'] ?? false)
                    || ($groups[$group]['direction'] ?? null) !== $row['expense_direction_id'])) {
                    $errors[$field.'purchase_product_group_id'] = $label.': '.__('product-groups.incompatible');
                }
                $updates[$id] = [...$row, 'expense_type_id' => $type];
            }
            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
            if ($applyIdentical) {
                $identities->link($plan);
            }
            $columns = [];
            foreach (['expense_direction_id', 'purchase_product_group_id', 'expense_type_id'] as $column) {
                // Only validated integer IDs/NULL enter SQL. ELSE preserves the column's
                // native type, including PostgreSQL batches where every value is NULL.
                $cases = [];
                foreach ($updates as $id => $row) {
                    $cases[] = 'WHEN '.$id.' THEN '.($row[$column] === null ? 'NULL' : (int) $row[$column]);
                }
                $columns[$column] = DB::raw('CASE id '.implode(' ', $cases).' ELSE '.$column.' END');
            }
            DB::table('purchase_products')->whereIn('id', array_keys($updates))->update([...$columns, 'updated_at' => now()]);

            return count($updates);
        });
    }
}
