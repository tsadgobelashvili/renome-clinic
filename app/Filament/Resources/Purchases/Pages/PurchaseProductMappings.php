<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseClassificationBatch;
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
        try {
            $count = app(PurchaseClassificationBatch::class)->save($this->pendingClassifications, $this->classificationOriginals);
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
        $this->flushCachedTableRecords();
        Notification::make()->title(__('catalog-classification.saved', ['count' => $count]))->success()->send();
    }

    public function table(Table $table): Table
    {
        return $table->query($this->productQuery()->with('supplier'))->striped()->columns([
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
                ->fillForm(fn (PurchaseProduct $record) => ['expense_direction_id' => $this->pendingClassifications[$record->id]['expense_direction_id'] ?? null])
                ->schema(PurchaseGroupForm::schema(true))
                ->action(function (PurchaseProduct $record, array $data): void {
                    Gate::authorize('update', $record);
                    $direction = $this->pendingClassifications[$record->id]['expense_direction_id'] ?? null;
                    if (! isset($this->classificationOriginals[$record->id]) || ! $direction || ($data['expense_direction_id'] ?? null) != $direction) {
                        throw ValidationException::withMessages(['expense_direction_id' => __('product-groups.direction_first')]);
                    }
                    $group = app(PurchaseCatalog::class)->createGroup($data);
                    $this->pendingClassifications[$record->id]['purchase_product_group_id'] = $group->id;
                    $this->classificationOptionsLoaded = false;
                }),
        ])->headerActions([
            Action::make('createProduct')->label(__('expense-entry.add_product'))
                ->visible(fn () => Gate::allows('create', PurchaseProduct::class))
                ->schema(FinanceExpenseForm::productForm())
                ->action(fn (array $data) => app(PurchaseCatalog::class)->createManual($data)),
        ])->defaultSort('name')->paginated([25, 50])->defaultPaginationPageOption(25);
    }
}
