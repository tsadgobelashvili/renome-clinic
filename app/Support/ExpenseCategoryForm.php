<?php

namespace App\Support;

use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Services\ExpenseDimensions;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategoryForm
{
    public static function schema(bool $requireSubcategory = false): array
    {
        return [
            Select::make('expense_direction_id')->label(__('expense-dimensions.direction'))->required()->searchable()
                ->live()->afterStateUpdated(fn (Set $set) => $set('expense_type_id', null))
                ->options(fn (?Model $record): array => app(ExpenseDimensions::class)->options('direction', $record?->expense_direction_id)),
            Select::make('expense_type_id')->label(__('expense-dimensions.type'))->required()->searchable()
                ->options(fn (Get $get, ?Model $record): array => app(ExpenseDimensions::class)->childOptions(
                    filled($get('expense_direction_id')) ? (int) $get('expense_direction_id') : null, $record?->expense_type_id)),
        ];
    }

    public static function categories(?int $selected = null): array
    {
        return ExpenseCategory::query()->where(fn ($query) => $query->where('active', true)->orWhere('id', $selected))
            ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function subcategories(?int $category, ?int $selected = null): array
    {
        if (! $category) {
            return [];
        }

        return ExpenseSubcategory::where('expense_category_id', $category)
            ->where(fn ($query) => $query->where('active', true)->orWhere('id', $selected))
            ->orderBy('sort_order')->orderBy('name')->pluck('name', 'id')->all();
    }

    public static function label(?string $key): ?string
    {
        if (! str_starts_with($key ?? '', 'managed_')) {
            return null;
        }
        $labels = once(fn () => ExpenseCategory::pluck('name', 'id')->all());

        return $labels[(int) substr($key, 8)] ?? $key;
    }

    public static function subcategoryLabel(?int $id): ?string
    {
        if (! $id) {
            return null;
        }
        $labels = once(fn () => ExpenseSubcategory::pluck('name', 'id')->all());

        return $labels[$id] ?? null;
    }
}
