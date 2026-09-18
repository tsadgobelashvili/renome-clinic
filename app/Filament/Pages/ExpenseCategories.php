<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Models\ExpenseCategory;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class ExpenseCategories extends Page
{
    use AuthorizesPageAccess;

    protected string $view = 'filament.pages.expense-categories';

    protected static ?int $navigationSort = 10;

    public bool $editing = false;

    #[Locked]
    public ?int $editingId = null;

    #[Locked]
    public ?int $parentId = null;

    public string $name = '';

    public string $dimension = 'direction';

    public bool $active = true;

    public int $sortOrder = 0;

    public static function getNavigationLabel(): string
    {
        return __('expense-categories.navigation');
    }

    public function getTitle(): string
    {
        return __('expense-categories.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return 'პარამეტრები';
    }

    public function edit(?int $id = null, ?int $parent = null): void
    {
        abort_unless(static::canAccess(), 403);
        $this->resetValidation();
        $record = $id ? ExpenseCategory::where('classification_dimension', $parent ? 'type' : 'direction')->where('parent_id', $parent)->findOrFail($id) : null;
        if ($parent) {
            ExpenseCategory::where('classification_dimension', 'direction')->whereNull('parent_id')->findOrFail($parent);
        }
        $this->editingId = $id;
        $this->parentId = $parent;
        $this->name = $record?->name ?? '';
        $this->dimension = $parent ? 'type' : 'direction';
        $this->active = $record?->active ?? true;
        $this->sortOrder = $record?->sort_order ?? 0;
        $this->editing = true;
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->name = trim($this->name);
        $this->validate(['name' => 'required|string|max:255', 'active' => 'boolean', 'sortOrder' => 'integer|min:0|max:100000', 'parentId' => 'nullable|exists:expense_categories,id']);
        $record = $this->editingId ? ExpenseCategory::where('parent_id', $this->parentId)->findOrFail($this->editingId) : new ExpenseCategory;
        $record->fill(['name' => $this->name, 'active' => $this->active, 'sort_order' => $this->sortOrder]);
        $record->classification_dimension = $this->parentId ? 'type' : 'direction';
        $record->parent_id = $this->parentId;
        $record->save();
        $this->editing = false;
    }

    public function toggleActive(int $id, bool $subcategory = false): void
    {
        abort_unless(static::canAccess(), 403);
        $record = ExpenseCategory::where('classification_dimension', $subcategory ? 'type' : 'direction')
            ->when($subcategory, fn ($q) => $q->whereNotNull('parent_id'))->findOrFail($id);
        $record->update(['active' => ! $record->active]);
    }

    public function deleteRecord(int $id, bool $subcategory = false): void
    {
        abort_unless(static::canAccess(), 403);
        DB::transaction(function () use ($id, $subcategory): void {
            $record = ExpenseCategory::where('classification_dimension', $subcategory ? 'type' : 'direction')
                ->when($subcategory, fn ($q) => $q->whereNotNull('parent_id'))->lockForUpdate()->findOrFail($id);
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

        return ['categories' => ExpenseCategory::where('classification_dimension', 'direction')->whereNull('parent_id')->with('children')->orderBy('sort_order')->orderBy('name')->get()];
    }
}
