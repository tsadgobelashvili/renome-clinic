<?php

namespace App\Filament\Pages;

use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class ExpenseCategories extends Page
{
    protected string $view = 'filament.pages.expense-categories';

    public bool $editing = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $parentId = null;

    public string $name = '';

    public bool $active = true;

    public int $sortOrder = 0;

    public static function canAccess(): bool
    {
        return auth()->user()?->isOwner() ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('expense-categories.title');
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public static function getNavigationGroup(): ?string
    {
        return app()->getLocale() === 'en' ? 'Finance' : 'ფინანსები';
    }

    public function edit(?int $id = null, ?int $parent = null): void
    {
        abort_unless(static::canAccess(), 403);
        $this->resetValidation();
        $record = $id ? ($parent ? ExpenseSubcategory::where('expense_category_id', $parent)->findOrFail($id) : ExpenseCategory::findOrFail($id)) : null;
        if ($parent) {
            ExpenseCategory::findOrFail($parent);
        }
        $this->editingId = $id;
        $this->parentId = $parent;
        $this->name = $record?->name ?? '';
        $this->active = $record?->active ?? true;
        $this->sortOrder = $record?->sort_order ?? 0;
        $this->editing = true;
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->name = trim($this->name);
        $this->validate(['name' => 'required|string|max:255', 'active' => 'boolean', 'sortOrder' => 'integer|min:0|max:100000', 'parentId' => 'nullable|exists:expense_categories,id']);
        $model = $this->parentId ? ExpenseSubcategory::class : ExpenseCategory::class;
        $record = $this->editingId ? $model::findOrFail($this->editingId) : new $model;
        $record->fill(['name' => $this->name, 'active' => $this->active, 'sort_order' => $this->sortOrder]);
        if ($this->parentId) {
            $record->expense_category_id = $this->parentId;
        }
        $record->save();
        $this->editing = false;
    }

    public function toggleActive(int $id, bool $subcategory = false): void
    {
        abort_unless(static::canAccess(), 403);
        $record = ($subcategory ? ExpenseSubcategory::class : ExpenseCategory::class)::findOrFail($id);
        $record->update(['active' => ! $record->active]);
    }

    public function deleteRecord(int $id, bool $subcategory = false): void
    {
        abort_unless(static::canAccess(), 403);
        DB::transaction(function () use ($id, $subcategory): void {
            $record = ($subcategory ? ExpenseSubcategory::class : ExpenseCategory::class)::lockForUpdate()->findOrFail($id);
            if ($record->isUsed()) {
                $record->update(['active' => false]);

                return;
            }
            $record->delete();
        });
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return ['categories' => ExpenseCategory::with('subcategories')->orderBy('sort_order')->orderBy('name')->get()];
    }
}
