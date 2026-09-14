<?php

namespace App\Filament\Pages;

use App\Models\BankCategorizationRule;
use App\Models\ExpenseCategory;
use App\Models\ExpenseSubcategory;
use App\Services\Bank\BankClassificationService;
use App\Services\Bank\BankExpenseAssignment;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class BankRules extends Page
{
    protected string $view = 'filament.pages.bank-rules';

    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public ?int $editingId = null;

    public bool $editing = false;

    public string $counterparty = '';

    public string $keyword = '';

    public string $account = '';

    public ?int $categoryId = null;

    public ?int $subcategoryId = null;

    public bool $active = true;

    public bool $confirmCompanyDefault = false;

    public bool $applyExisting = false;

    public static function canAccess(): bool
    {
        return Bank::canAccess();
    }

    public function getTitle(): string
    {
        return __('bank-accounting.rules');
    }

    public function updatedCategoryId(): void
    {
        $this->subcategoryId = null;
    }

    public function edit(?int $id = null): void
    {
        abort_unless(static::canAccess(), 403);
        $record = $id ? BankCategorizationRule::findOrFail($id) : null;
        $this->resetValidation();
        $this->editingId = $id;
        $this->counterparty = $record?->counterparty ?? '';
        $this->keyword = $record?->purpose_keyword ?? '';
        $this->account = $record?->counterparty_account ?? '';
        $this->categoryId = $record?->expense_category_id;
        $this->subcategoryId = $record?->expense_subcategory_id;
        $this->active = $record?->active ?? true;
        $this->confirmCompanyDefault = $this->applyExisting = false;
        $this->editing = true;
    }

    public function save(BankExpenseAssignment $assignment): void
    {
        abort_unless(static::canAccess(), 403);
        DB::transaction(function () use ($assignment) {
            $rule = $assignment->saveRule(['counterparty' => $this->counterparty, 'purpose_keyword' => $this->keyword, 'counterparty_account' => $this->account,
                'expense_category_id' => $this->categoryId, 'expense_subcategory_id' => $this->subcategoryId, 'active' => $this->active,
                'confirm_company_default' => $this->confirmCompanyDefault], auth()->user(), $this->editingId);
            if ($this->applyExisting && $rule->active) {
                app(BankClassificationService::class)->applyToUncategorized($rule->id);
            }
        });
        $this->editing = false;
    }

    public function toggleActive(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $record = BankCategorizationRule::findOrFail($id);
        $record->update(['active' => ! $record->active]);
    }

    public function applyRulesAction(): Action
    {
        return Action::make('applyRules')->label(__('bank-accounting.apply_rules'))->requiresConfirmation()
            ->modalDescription(__('bank-rules.apply_help'))
            ->action(function (BankClassificationService $classifier): void {
                abort_unless(static::canAccess(), 403);
                $count = $classifier->applyToUncategorized();
                Notification::make()->success()->title(__('bank-accounting.applied', ['count' => $count]))->send();
            });
    }

    public function deleteRuleAction(): Action
    {
        return Action::make('deleteRule')->label(__('bank-accounting.delete_rule'))->color('danger')->requiresConfirmation()
            ->action(function (array $arguments): void {
                abort_unless(static::canAccess(), 403);
                BankCategorizationRule::findOrFail($arguments['rule'])->delete();
            });
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);

        return ['rules' => BankCategorizationRule::with(['category', 'subcategory'])->orderBy('id')->get(),
            'categories' => ExpenseCategory::orderBy('sort_order')->orderBy('name')->get(),
            'subcategories' => ExpenseSubcategory::orderBy('sort_order')->orderBy('name')->get()];
    }
}
