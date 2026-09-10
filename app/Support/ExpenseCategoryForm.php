<?php

namespace App\Support;

use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategoryForm
{
    public static function schema(): array
    {
        return [
            Select::make('expense_category_id')->label(__('expense-categories.category'))->required()->searchable()->live()
                ->options(fn (?Model $record): array => self::categories($record?->expense_category_id))
                ->afterStateUpdated(fn (Set $set) => $set('expense_subcategory_id', null)),
            Select::make('expense_subcategory_id')->label(__('expense-categories.subcategory'))->searchable()
                ->options(fn (Get $get, ?Model $record): array => self::subcategories($get('expense_category_id'), $record?->expense_subcategory_id))
                ->disabled(fn (Get $get): bool => ! $get('expense_category_id')),
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
