<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseClassificationBatch;
use App\Services\PurchaseProductIdentity;
use App\Support\FinanceExpenseForm;
use App\Support\PurchaseGroupForm;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

abstract class PurchaseProductMappings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PurchaseResource::class;

    protected string $view = 'filament.resources.purchases.classification-table';

    public array $pendingClassifications = [];

    #[Locked]
    public array $classificationOriginals = [];

    #[Locked]
    public array $classificationGroups = [];

    #[Locked]
    public array $classificationDirections = [];

    private bool $classificationOptionsLoaded = false;

    #[Locked]
    public array $identityConfirmation = [];

    #[Locked]
    public array $groupCreationOrigin = [];

    public function confirmIdenticalAction(): Action
    {
        return Action::make('confirmIdentical')->modalHeading(__('catalog-classification.identical_heading'))
            ->modalDescription(fn () => collect($this->identityConfirmation)->map(fn ($entry) => $entry['name'].': '.
                ($entry['conflict'] ? __('catalog-classification.identity_conflict') : __('catalog-classification.identical_prompt', ['count' => count($entry['members'])])))->implode("\n"))
            ->modalSubmitActionLabel(__('catalog-classification.apply_all'))
            ->modalSubmitAction(fn (Action $action) => $action->disabled(collect($this->identityConfirmation)->contains('conflict', true)))
            ->extraModalFooterActions(fn (Action $action) => [$action->makeModalSubmitAction('onlySelected', arguments: ['onlySelected' => true])->label(__('catalog-classification.only_selected'))])
            ->action(fn (array $arguments) => $this->persistClassifications(! ($arguments['onlySelected'] ?? false)));
    }

    abstract protected function productQuery(): Builder;

    public function prepareVisibleClassificationRows(): void
    {
        static::authorizeResourceAccess();
        if (! $this->classificationOptionsLoaded) {
            $this->classificationGroups = app(PurchaseCatalog::class)->classificationGroups();
            $this->classificationDirections = app(ExpenseDimensions::class)->registry()
                ->filter(fn ($row) => $row->classification_dimension === 'direction' && ! $row->parent_id)
                ->sortBy('sort_order')
                ->mapWithKeys(fn ($row) => [$row->id => ['name' => ExpenseDimensions::label($row), 'active' => (bool) $row->active]])->all();
            $this->classificationOptionsLoaded = true;
        }
        foreach ($this->getTableRecords() as $record) {
            $id = $record->id;
            $this->classificationOriginals[$id] ??= [...PurchaseClassificationBatch::selection($record->getAttributes()),
                'expense_type_id' => $record->expense_type_id, 'name' => $record->name];
            $this->pendingClassifications[$id] ??= PurchaseClassificationBatch::selection($this->classificationOriginals[$id]);
        }
    }

    public function saveClassifications(): void
    {
        static::authorizeResourceAccess();
        $this->identityConfirmation = app(PurchaseProductIdentity::class)->preview($this->pendingClassifications, $this->classificationOriginals);
        if ($this->identityConfirmation !== []) {
            $this->mountAction('confirmIdentical');

            return;
        }
        $this->persistClassifications();
    }

    protected function persistClassifications(bool $applyIdentical = false): void
    {
        static::authorizeResourceAccess();
        try {
            $count = app(PurchaseClassificationBatch::class)->save($this->pendingClassifications, $this->classificationOriginals, $applyIdentical, $this->identityConfirmation);
        } catch (ValidationException $exception) {
            Notification::make()->title(__('catalog-classification.invalid_batch'))
                ->body(collect($exception->errors())->flatten()->take(5)->implode("\n"))->danger()->send();
            throw $exception;
        }
        // Retain clean row state across pagination/filtering. Rows can disappear from
        // Uncategorized after saving; their Alpine bindings stay valid during the morph.
        foreach ($this->classificationOriginals as $id => $original) {
            $selection = PurchaseClassificationBatch::selection($this->pendingClassifications[$id] ?? $original);
            $this->classificationOriginals[$id] = [...$original, ...$selection,
                'expense_type_id' => $selection['expense_direction_id'] === PurchaseClassificationBatch::selection($original)['expense_direction_id']
                    ? ($original['expense_type_id'] ?? null) : null];
            $this->pendingClassifications[$id] = $selection;
        }
        $this->resetValidation();
        $this->identityConfirmation = [];
        $this->flushCachedTableRecords();
        Notification::make()->title(__('catalog-classification.saved', ['count' => $count]))->success()->send();
    }

    public function table(Table $table): Table
    {
        return $table->query(app(PurchaseProductIdentity::class)->visible($this->productQuery())->with('supplier'))->striped()->columns([
            TextColumn::make('name')->label('პროდუქტი')->searchable()->limit(50)->tooltip(fn ($record) => $record->name),
            TextColumn::make('supplier.name')->label('მომწოდებელი')->limit(30),
            TextColumn::make('rs_product_code')->label('კოდი')->placeholder('—'),
            ViewColumn::make('direction')->label('მიმართულება')->view('filament.resources.purchases.classification-direction'),
            ViewColumn::make('product_group')->label('პროდუქციის ჯგუფი')->view('filament.resources.purchases.classification-group'),
        ])->recordActions([
            // The adjacent custom + button can react locally to an unsaved Direction.
            Action::make('createGroup')->label(__('product-groups.create'))->icon('heroicon-m-plus')->iconButton()
                ->extraAttributes(['class' => 'hidden'])
                ->modalHeading(__('product-groups.create'))->modalWidth('sm')
                ->visible(fn (PurchaseProduct $record) => filled($this->pendingClassifications[$record->id]['expense_direction_id'] ?? null)
                    && Gate::allows('update', $record) && Gate::allows('create', PurchaseProductGroup::class))
                ->fillForm(function (PurchaseProduct $record): array {
                    $direction = $this->pendingClassifications[$record->id]['expense_direction_id'] ?? null;
                    $this->groupCreationOrigin = ['rowId' => $record->id, 'direction' => $direction,
                        'statePath' => 'pendingClassifications.'.$record->id.'.purchase_product_group_id'];

                    return ['expense_direction_id' => $direction];
                })
                ->schema(PurchaseGroupForm::schema(true))
                ->action(function (PurchaseProduct $record, array $data): void {
                    Gate::authorize('update', $record);
                    $direction = $this->pendingClassifications[$record->id]['expense_direction_id'] ?? null;
                    if (($this->groupCreationOrigin['rowId'] ?? null) !== $record->id
                        || ($this->groupCreationOrigin['direction'] ?? null) != $direction
                        || ! isset($this->classificationOriginals[$record->id]) || ! $direction || ($data['expense_direction_id'] ?? null) != $direction) {
                        throw ValidationException::withMessages(['expense_direction_id' => __('product-groups.direction_first')]);
                    }
                    $group = app(PurchaseCatalog::class)->createGroup($data);
                    // Modal actions may render only their own partial. Publish the
                    // option now instead of waiting for the page Blade to reload it.
                    $this->classificationGroups[$group->id] = ['name' => $group->name,
                        'direction' => (int) $direction, 'selectable' => true];
                    $this->pendingClassifications[$record->id]['purchase_product_group_id'] = $group->id;
                    $this->dispatch('purchase-classification-group-created', ...$this->groupCreationOrigin,
                        group: ['id' => $group->id, ...$this->classificationGroups[$group->id]]);
                    $this->groupCreationOrigin = [];
                }),
        ])->headerActions([
            Action::make('createProduct')->label(__('expense-entry.add_product'))
                ->visible(fn () => Gate::allows('create', PurchaseProduct::class))
                ->schema(FinanceExpenseForm::productForm())
                ->action(fn (array $data) => app(PurchaseCatalog::class)->createManual($data)),
        ])->defaultSort('name')->paginated([25, 50])->defaultPaginationPageOption(25);
    }
}
