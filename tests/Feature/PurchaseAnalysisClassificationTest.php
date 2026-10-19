<?php

use App\Filament\Resources\Purchases\Pages\PurchaseAnalysis as AnalysisPage;
use App\Models\FinanceTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseAnalysis;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $catalog = app(PurchaseCatalog::class);
    // Deliberately offset product IDs from aggregate MIN(purchase_items.id).
    $this->unrelated = $catalog->createManual(['name' => 'Unrelated catalog product']);
    app(PurchaseImportService::class)->import(base_path('tests/fixtures/rs-items-georgian.csv'));
    $this->product = PurchaseProduct::where('rs_product_code', '001')->sole();
    $this->therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    $this->surgery = app(ExpenseDimensions::class)->id('direction', 'surgery');
    $this->old = $catalog->createGroup(['name' => 'Original group', 'expense_direction_id' => $this->therapy]);
    $this->new = $catalog->createGroup(['name' => 'Updated group', 'expense_direction_id' => $this->therapy]);
    $this->surgical = $catalog->createGroup(['name' => 'Surgical group', 'expense_direction_id' => $this->surgery]);
    $catalog->assignCatalogDirection($this->product, $this->therapy);
    $catalog->assignCatalogGroup($this->product, $this->old->id);
    $this->row = app(PurchaseAnalysis::class)->products([], (string) $this->old->id, (string) $this->therapy)->sole();
});

function classificationAnalysisPage(): mixed
{
    return Livewire::test(AnalysisPage::class)->call('openDirection', (string) test()->therapy)
        ->call('openGroup', (string) test()->old->id);
}

test('product row opens a modal with current values and changes persist only on explicit Save', function () {
    $page = classificationAnalysisPage()->mountTableAction('editClassification', $this->row)
        ->assertSet('mountedActions.0.name', 'editClassification')
        ->assertTableActionDataSet(['name' => $this->product->name, 'expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->old->id])
        ->assertFormFieldIsDisabled('name');
    expect((string) $page->instance()->getMountedAction()->getModalSubmitActionLabel())->toBe('შენახვა');
    $page->setTableActionData(['purchase_product_group_id' => $this->new->id]);
    expect($this->product->fresh()->purchase_product_group_id)->toBe($this->old->id);
    $page->callMountedTableAction()->assertHasNoTableActionErrors()->assertSet('mountedActions', []);
    expect($this->product->fresh()->purchase_product_group_id)->toBe($this->new->id)
        ->and($this->unrelated->fresh()->purchase_product_group_id)->toBeNull();
    expect($page->instance()->getTableRecords())->toHaveCount(0);
});

test('cancel leaves the product classification unchanged', function () {
    classificationAnalysisPage()->mountTableAction('editClassification', $this->row)
        ->setTableActionData(['purchase_product_group_id' => $this->new->id])->call('unmountAction');
    expect($this->product->fresh()->purchase_product_group_id)->toBe($this->old->id);
});

test('editor is offered for mapped product rows rather than aggregate headings or unmapped lines', function () {
    $report = app(PurchaseAnalysis::class);
    $direction = $report->directions()->get()->firstWhere('direction_id', $this->therapy);
    $page = Livewire::test(AnalysisPage::class)->assertTableActionHidden('editClassification', $direction)
        ->call('openDirection', (string) $this->therapy);
    $group = $report->groups([], (string) $this->therapy)->sole();
    $page->assertTableActionHidden('editClassification', $group)->call('openGroup', (string) $this->old->id)
        ->assertTableActionVisible('editClassification', $this->row);
    $document = Purchase::where('document_number', 'RS-TEST-1')->sole();
    $document->items()->create(['item_name' => 'Unmapped line', 'quantity' => 1, 'unit_price' => 2, 'unit' => 'pcs']);
    $unmapped = $report->products([], 'ungrouped', 'uncategorized')->get()->firstWhere('product_id', null);
    Livewire::test(AnalysisPage::class)->call('openDirection', 'uncategorized')->call('openGroup', 'ungrouped')
        ->assertTableActionHidden('editClassification', $unmapped);
});

test('Category change filters groups clears incompatible state and updates analysis aggregation', function () {
    $page = classificationAnalysisPage()->mountTableAction('editClassification', $this->row);
    $index = array_key_last($page->get('mountedActions'));
    $page->set('mountedActions.'.$index.'.data.expense_direction_id', $this->surgery)
        ->assertTableActionDataSet(['purchase_product_group_id' => null])
        ->assertFormFieldExists('purchase_product_group_id', fn ($field) => array_keys($field->getOptions()) === [$this->surgical->id]);
    $page->setTableActionData(['purchase_product_group_id' => $this->surgical->id])->callMountedTableAction()->assertHasNoTableActionErrors();
    $report = app(PurchaseAnalysis::class);
    expect($report->products([], (string) $this->old->id, (string) $this->therapy)->count())->toBe(0)
        ->and((float) $report->directions()->get()->firstWhere('direction_id', $this->surgery)->purchase_amount)->toBe(300.0)
        ->and((float) $report->groups([], (string) $this->surgery)->sole()->purchase_amount)->toBe(300.0)
        ->and($this->product->fresh()->expense_direction_id)->toBe($this->surgery);
    $page->call('back')->call('back');
    expect((float) $page->instance()->getTableRecords()->firstWhere('direction_id', $this->surgery)->purchase_amount)->toBe(300.0);
});

test('history rows edit the shared catalog product without changing RS or financial records', function () {
    $path = tempnam(sys_get_temp_dir(), 'analysis-edit');
    rename($path, $path .= '.csv');
    try {
        file_put_contents($path, str_replace('RS-TEST-', 'RS-ANALYSIS-EDIT-', file_get_contents(base_path('tests/fixtures/rs-items-georgian.csv'))));
        app(PurchaseImportService::class)->import($path);
    } finally {
        unlink($path);
    }
    $line = $this->product->items()->latest('id')->firstOrFail();
    $retail = Product::create(['name' => 'Legacy retail identity', 'selling_price' => 5]);
    $line->update(['product_id' => $retail->id]);
    FinanceTransaction::create(['type' => 'expense', 'transaction_date' => '2026-09-01', 'category' => 'materials',
        'amount' => 17, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'withdrawn_cash',
        'purchase_product_id' => $this->product->id, 'purchase_product_group_id' => $this->old->id, 'expense_direction_id' => $this->therapy]);
    $tables = ['purchases', 'purchase_items', 'suppliers', 'products', 'finance_transactions', 'cashbox_transactions', 'bank_transactions',
        'bank_purchase_matches', 'partner_finance_transactions', 'payments', 'payment_splits'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $otherProducts = PurchaseProduct::whereKeyNot($this->product->id)->orderBy('id')->get()->toJson();
    $identity = $this->product->only(['name', 'normalized_name', 'rs_product_code', 'supplier_product_code', 'supplier_id', 'identity_key']);
    $page = classificationAnalysisPage()->call('openProduct', (string) $this->product->id);
    $page->callTableAction('editClassification', $line, data: ['name' => 'FORGED NAME', 'expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->new->id])
        ->assertHasNoTableActionErrors();
    expect($snapshot())->toBe($before)->and($this->product->fresh()->only(array_keys($identity)))->toBe($identity)
        ->and(PurchaseProduct::whereKeyNot($this->product->id)->orderBy('id')->get()->toJson())->toBe($otherProducts)
        ->and($this->product->items()->count())->toBe(2)
        ->and(app(PurchaseAnalysis::class)->history([], (string) $this->new->id, (string) $this->product->id, (string) $this->therapy)->count())->toBe(2);
});

test('save preserves date supplier payment and product-search filters', function () {
    $document = Purchase::where('document_number', 'RS-TEST-1')->sole();
    $filters = ['from' => '2026-09-01', 'until' => '2026-09-02', 'supplier' => $document->supplier_id,
        'product' => $this->product->id, 'payment' => 'unlinked', 'direction' => $this->therapy, 'group' => $this->old->id];
    $page = classificationAnalysisPage()->set('filters', $filters)->call('openDirection', (string) $this->therapy)->call('openGroup', (string) $this->old->id);
    $page->callTableAction('editClassification', $this->row, data: ['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->new->id])->assertHasNoTableActionErrors()
        ->assertSet('filters', $filters)->assertSet('selectedDirection', (string) $this->therapy)->assertSet('selectedGroup', (string) $this->old->id);
    expect($page->instance()->getTableRecords())->toHaveCount(0);
});

test('saving on page two preserves pagination and search state', function () {
    $document = Purchase::where('document_number', 'RS-TEST-1')->sole();
    for ($i = 0; $i < 35; $i++) {
        $product = app(PurchaseCatalog::class)->createManual(['name' => 'Pagination '.$i, 'expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->old->id]);
        $document->items()->create(['purchase_product_id' => $product->id, 'item_name' => $product->name, 'quantity' => 1, 'unit_price' => 10, 'unit' => 'pcs']);
    }
    $page = classificationAnalysisPage()->call('gotoPage', 2);
    $row = $page->instance()->getTableRecords()->first();
    $search = $page->get('tableSearch');
    $page->callTableAction('editClassification', $row, data: ['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->new->id])->assertHasNoTableActionErrors()
        ->assertSet('tableSearch', $search);
    expect($page->instance()->getTableRecords()->currentPage())->toBe(2);
});

test('incompatible groups fail atomically with modal errors', function () {
    classificationAnalysisPage()->callTableAction('editClassification', $this->row,
        data: ['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->surgical->id])->assertHasTableActionErrors(['purchase_product_group_id']);
    expect($this->product->fresh()->purchase_product_group_id)->toBe($this->old->id);
});

test('stale classifications fail atomically with modal errors', function () {
    $page = classificationAnalysisPage()->mountTableAction('editClassification', $this->row);
    $type = array_key_first(app(PurchaseCatalog::class)->subcategoryOptions($this->therapy));
    $this->product->update(['expense_type_id' => $type]);
    $page->setTableActionData(['expense_direction_id' => $this->surgery, 'purchase_product_group_id' => $this->surgical->id])
        ->callMountedTableAction()->assertHasTableActionErrors(['expense_direction_id']);
    expect($this->product->fresh()->expense_direction_id)->toBe($this->therapy)
        ->and($this->product->fresh()->purchase_product_group_id)->toBe($this->old->id)
        ->and($this->product->fresh()->expense_type_id)->toBe($type);
});

test('nonowners cannot invoke the analysis editor', function () {
    $page = classificationAnalysisPage()->mountTableAction('editClassification', $this->row);
    auth()->user()->update(['role' => User::ROLE_ADMINISTRATOR]);
    $this->actingAs(auth()->user()->fresh());
    $page->callMountedTableAction()->assertForbidden();
    Livewire::test(AnalysisPage::class)->assertForbidden();
    expect($this->product->fresh()->purchase_product_group_id)->toBe($this->old->id);
});
