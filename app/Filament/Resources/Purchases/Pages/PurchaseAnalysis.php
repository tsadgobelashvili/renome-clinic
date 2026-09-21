<?php

namespace App\Filament\Resources\Purchases\Pages;

use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use App\Models\Supplier;
use App\Services\PurchaseAnalysis as Report;
use App\Services\PurchaseCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Livewire\Attributes\Locked;

class PurchaseAnalysis extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = PurchaseResource::class;

    protected string $view = 'filament.resources.purchases.analysis';

    public ?array $filters = [];

    #[Locked]
    public ?string $selectedGroup = null;

    #[Locked]
    public ?string $selectedProduct = null;

    public function getTitle(): string
    {
        return 'შესყიდვების ანალიზი';
    }

    public function mount(): void
    {
        static::authorizeResourceAccess();
        $this->form->fill(['payment' => 'all']);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('filters')->columns(['default' => 2, 'lg' => 4])->components([
            DatePicker::make('from')->label('დან')->live(),
            DatePicker::make('until')->label('მდე')->live(),
            Select::make('direction')->label('მიმართულება')->placeholder('ყველა')->options(fn () => app(PurchaseCatalog::class)->directionOptions())->live(),
            Select::make('group')->label('პროდუქციის ჯგუფი')->placeholder('ყველა')->options(fn () => app(PurchaseCatalog::class)->groupOptions())->live(),
            Select::make('product')->label('პროდუქტი')->placeholder('ყველა')->searchable()
                ->getSearchResultsUsing(fn ($search) => PurchaseProduct::whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => PurchaseProduct::find($value)?->name)->live(),
            Select::make('supplier')->label('მომწოდებელი')->placeholder('ყველა')->searchable()
                ->getSearchResultsUsing(fn ($search) => Supplier::whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])->orderBy('name')->limit(30)->pluck('name', 'id')->all())
                ->getOptionLabelUsing(fn ($value) => Supplier::find($value)?->name)->live(),
            Select::make('payment')->label('გადახდა')->options(['all' => 'ყველა', 'bank' => 'ბანკი', 'cash' => 'ქეში', 'unlinked' => 'მიუბმელი'])->selectablePlaceholder(false)->live(),
        ]);
    }

    public function updatedFilters(): void
    {
        $this->selectedGroup = $this->selectedProduct = null;
        $this->resetTable();
    }

    public function openGroup(string $group): void
    {
        static::authorizeResourceAccess();
        if ($group !== 'ungrouped') {
            PurchaseProductGroup::findOrFail($group);
        }
        $this->selectedGroup = $group;
        $this->selectedProduct = null;
        $this->resetTable();
    }

    public function openProduct(string $product): void
    {
        static::authorizeResourceAccess();
        abort_if($this->selectedGroup === null, 404);
        if ($product !== 'unmapped') {
            PurchaseProduct::findOrFail($product);
        }
        $this->selectedProduct = $product;
        $this->resetTable();
    }

    public function back(): void
    {
        static::authorizeResourceAccess();
        if ($this->selectedProduct !== null) {
            $this->selectedProduct = null;
        } else {
            $this->selectedGroup = null;
        }
        $this->resetTable();
    }

    public function table(Table $table): Table
    {
        $report = app(Report::class);
        $history = $this->selectedProduct !== null;
        $query = $history ? $report->history($this->filters ?? [], $this->selectedGroup, $this->selectedProduct)
            : ($this->selectedGroup !== null ? $report->products($this->filters ?? [], $this->selectedGroup) : $report->groups($this->filters ?? []));

        return $table->query($query)->striped()->columns($history ? [
            TextColumn::make('purchase_date')->label('თარიღი')->date('d.m.Y'),
            TextColumn::make('supplier_name')->label('მომწოდებელი')->limit(30),
            TextColumn::make('item_name')->label('პროდუქტი')->limit(40)->tooltip(fn ($record) => $record->item_name),
            TextColumn::make('quantity')->label('რაოდენობა')->numeric(decimalPlaces: 3)->alignEnd(),
            TextColumn::make('unit')->label('ერთეული')->placeholder('—'),
            TextColumn::make('unit_price')->label('ერთეულის ფასი')->money('GEL')->alignEnd(),
            TextColumn::make('line_total')->label('თანხა')->money('GEL')->alignEnd(),
            TextColumn::make('document_number')->label('დოკუმენტი')->placeholder('—')
                ->url(fn ($record) => PurchaseResource::getUrl('edit', ['record' => $record->purchase_id])),
        ] : [
            TextColumn::make('name')->label($this->selectedGroup === null ? 'ჯგუფი' : 'პროდუქტი')->placeholder('ჯგუფის / პროდუქტის გარეშე')->limit(45),
            TextColumn::make('purchased_quantity')->label('რაოდენობა')->numeric(decimalPlaces: 3)->alignEnd(),
            TextColumn::make('unit')->label('ერთეული')->state(fn ($record) => $record->unit_count > 1 ? 'შერეული' : ($record->unit ?? '—')),
            TextColumn::make('purchase_amount')->label('თანხა')->money('GEL')->alignEnd(),
            TextColumn::make('average_price')->label('საშუალო ფასი')->money('GEL')->alignEnd()
                ->state(fn ($record) => $record->unit_count > 1 ? null : $record->average_price)->placeholder('—')->visible($this->selectedGroup !== null),
            TextColumn::make('latest_price')->label('ბოლო ფასი')->money('GEL')->alignEnd()->visible($this->selectedGroup !== null),
        ])->recordAction($history ? null : 'drillDown')
            ->recordActions($history ? [] : [Action::make('drillDown')->label('გახსნა')->icon('heroicon-o-chevron-right')->iconButton()
                ->action(function ($record): void {
                    $this->selectedGroup === null ? $this->openGroup((string) ($record->group_id ?? 'ungrouped'))
                        : $this->openProduct((string) ($record->product_id ?? 'unmapped'));
                })])
            ->defaultKeySort(false)
            ->defaultSort(fn ($query) => $history ? $query->orderByDesc('document.purchase_date')->orderByDesc('purchase_items.id') : $query->orderByDesc('purchase_amount')->orderByRaw('MIN(purchase_items.id)'))
            ->paginated([25, 50])->defaultPaginationPageOption(25);
    }
}
