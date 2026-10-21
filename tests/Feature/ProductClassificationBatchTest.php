<?php

use App\Filament\Resources\Purchases\Pages\PurchaseProducts;
use App\Models\FinanceTransaction;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseClassificationBatch;
use App\Services\PurchaseImportService;
use Filament\Tables\Columns\ViewColumn;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->catalog = app(PurchaseCatalog::class);
    $this->therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    $this->surgery = app(ExpenseDimensions::class)->id('direction', 'surgery');
    $this->group = $this->catalog->createGroup(['name' => 'Fillings', 'expense_direction_id' => $this->therapy]);
    $this->a = $this->catalog->createManual(['name' => 'Product A']);
    $this->b = $this->catalog->createManual(['name' => 'Product B']);
});

test('multiple dropdown edits are deferred until one explicit Save', function () {
    $page = Livewire::test(PurchaseProducts::class)
        ->set('pendingClassifications.'.$this->a->id.'.expense_direction_id', $this->therapy)
        ->set('pendingClassifications.'.$this->a->id.'.purchase_product_group_id', $this->group->id)
        ->set('pendingClassifications.'.$this->b->id.'.expense_direction_id', $this->therapy)
        ->set('pendingClassifications.'.$this->b->id.'.purchase_product_group_id', $this->group->id);
    expect($this->a->fresh()->expense_direction_id)->toBeNull()->and($this->a->fresh()->purchase_product_group_id)->toBeNull()
        ->and($this->b->fresh()->expense_direction_id)->toBeNull();
    $page->call('saveClassifications')->assertHasNoErrors()->assertNotified(__('catalog-classification.saved', ['count' => 2]));
    foreach ([$this->a, $this->b] as $product) {
        expect($product->fresh()->expense_direction_id)->toBe($this->therapy)->and($product->fresh()->purchase_product_group_id)->toBe($this->group->id);
        expect($page->get('pendingClassifications')[$product->id])->toEqual(PurchaseClassificationBatch::selection($product->fresh()->getAttributes()));
        expect(PurchaseClassificationBatch::selection($page->get('classificationOriginals')[$product->id]))->toEqual($page->get('pendingClassifications')[$product->id]);
    }
});

test('existing classifications load and can be edited from the searchable catalog', function () {
    $this->catalog->assignCatalogDirection($this->a, $this->therapy);
    $this->catalog->assignCatalogGroup($this->a, $this->group->id);
    $page = Livewire::test(PurchaseProducts::class)->searchTable('Product A')
        ->assertSet('pendingClassifications.'.$this->a->id.'.expense_direction_id', $this->therapy)
        ->assertSet('pendingClassifications.'.$this->a->id.'.purchase_product_group_id', $this->group->id)
        ->set('pendingClassifications.'.$this->a->id, ['expense_direction_id' => $this->surgery, 'purchase_product_group_id' => null]);
    expect($this->a->fresh()->purchase_product_group_id)->toBe($this->group->id);
    $page->call('saveClassifications')->assertHasNoErrors();
    expect($this->a->fresh()->expense_direction_id)->toBe($this->surgery)->and($this->a->fresh()->purchase_product_group_id)->toBeNull();
});

test('invalid batches identify the product and retain every pending edit without partial writes', function () {
    $page = Livewire::test(PurchaseProducts::class)
        ->set('pendingClassifications.'.$this->a->id, ['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->group->id])
        ->set('pendingClassifications.'.$this->b->id, ['expense_direction_id' => $this->surgery, 'purchase_product_group_id' => $this->group->id])
        ->call('saveClassifications')->assertHasErrors(['pendingClassifications.'.$this->b->id.'.purchase_product_group_id'])
        ->assertSet('pendingClassifications.'.$this->a->id.'.purchase_product_group_id', $this->group->id);
    expect($this->a->fresh()->expense_direction_id)->toBeNull()->and($this->b->fresh()->expense_direction_id)->toBeNull();
    expect($page->errors()->first('pendingClassifications.'.$this->b->id.'.purchase_product_group_id'))->toContain('Product B');
});

test('concurrent classification changes are not overwritten and cancel the entire batch', function () {
    $page = Livewire::test(PurchaseProducts::class)
        ->set('pendingClassifications.'.$this->a->id, ['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->group->id])
        ->set('pendingClassifications.'.$this->b->id.'.expense_direction_id', $this->therapy);
    $this->a->update(['expense_direction_id' => $this->surgery]);
    $page->call('saveClassifications')->assertHasErrors(['pendingClassifications.'.$this->a->id.'.expense_direction_id']);
    expect($this->a->fresh()->expense_direction_id)->toBe($this->surgery)->and($this->b->fresh()->expense_direction_id)->toBeNull();
});

test('inline group creation uses pending Direction and only its row assignment waits for Save', function () {
    $page = Livewire::test(PurchaseProducts::class)
        ->set('pendingClassifications.'.$this->a->id.'.expense_direction_id', $this->therapy)
        ->callTableAction('createGroup', $this->a, data: ['name' => 'New group', 'expense_direction_id' => $this->therapy])
        ->assertHasNoTableActionErrors()->assertNoRedirect();
    $group = PurchaseProductGroup::where('name', 'New group')->sole();
    $page->assertSet('pendingClassifications.'.$this->a->id.'.purchase_product_group_id', $group->id);
    expect($this->a->fresh()->purchase_product_group_id)->toBeNull()->and($this->a->fresh()->expense_direction_id)->toBeNull();
    $page->call('saveClassifications')->assertHasNoErrors();
    expect($this->a->fresh()->purchase_product_group_id)->toBe($group->id);
});

test('batch uses one UPDATE and a fixed number of read queries for many products', function () {
    $originals = $pending = [];
    foreach (range(1, 12) as $i) {
        $product = $this->catalog->createManual(['name' => 'Batch '.$i]);
        $originals[$product->id] = [...$product->getAttributes(), 'expense_type_id' => null];
        $pending[$product->id] = ['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->group->id];
    }
    app(ExpenseDimensions::class)->reset();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect(app(PurchaseClassificationBatch::class)->save($pending, $originals))->toBe(12);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'update ')))->toHaveCount(1)
        ->and($queries->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'select ')))->toHaveCount(4);
});

test('inline creation publishes options and preserves every unsaved row until one batch save', function () {
    $c = $this->catalog->createManual(['name' => 'Product C']);
    FinanceTransaction::create(['type' => 'expense', 'category' => 'rent', 'transaction_date' => now(), 'amount' => 45, 'currency' => 'GEL', 'payment_method' => 'bank_transfer']);
    $tables = ['finance_transactions', 'cashbox_transactions', 'bank_transactions', 'employee_advance_entries', 'purchase_items'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $snapshot();
    $a = ['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->group->id];
    $b = ['expense_direction_id' => $this->surgery, 'purchase_product_group_id' => null];
    $page = Livewire::test(PurchaseProducts::class)
        ->searchTable('Product')->set('pendingClassifications.'.$this->a->id, $a)
        ->set('pendingClassifications.'.$this->b->id, $b)
        ->set('pendingClassifications.'.$c->id.'.expense_direction_id', $this->therapy)
        ->mountTableAction('createGroup', $c)
        ->assertTableActionDataSet(['expense_direction_id' => $this->therapy])
        ->setTableActionData(['name' => 'Zircon'])
        ->callMountedTableAction()->assertHasNoTableActionErrors()->assertNoRedirect();
    $group = PurchaseProductGroup::where('name', 'Zircon')->sole();
    $page->assertSet('classificationGroups.'.$group->id, ['name' => 'Zircon', 'direction' => $this->therapy, 'selectable' => true])
        ->assertSet('pendingClassifications.'.$c->id.'.purchase_product_group_id', $group->id)
        ->assertSet('pendingClassifications.'.$this->a->id, $a)
        ->assertSet('pendingClassifications.'.$this->b->id, $b)
        ->assertSet('tableSearch', 'Product');
    foreach ([$this->a, $this->b, $c] as $product) {
        expect($product->fresh()->expense_direction_id)->toBeNull()->and($product->fresh()->purchase_product_group_id)->toBeNull();
        expect(PurchaseClassificationBatch::selection($page->get('classificationOriginals')[$product->id]))
            ->not->toEqual($page->get('pendingClassifications')[$product->id]);
    }
    expect($this->catalog->directionGroupOptions($this->therapy))->toHaveKey($group->id)
        ->and($this->catalog->directionGroupOptions($this->surgery))->not->toHaveKey($group->id)
        ->and($snapshot())->toEqual($before);
    $page->call('saveClassifications')->assertHasNoErrors();
    expect($this->a->fresh()->purchase_product_group_id)->toBe($this->group->id)
        ->and($this->b->fresh()->expense_direction_id)->toBe($this->surgery)
        ->and($c->fresh()->purchase_product_group_id)->toBe($group->id)
        ->and($snapshot())->toEqual($before);
});

test('option queries do not grow with visible product rows and the view has no autosave bindings', function () {
    $measure = function (): int {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page = Livewire::test(PurchaseProducts::class)->assertSee(__('catalog-classification.save'))
            ->assertSee('purchaseClassificationState', false)->assertSee(__('catalog-classification.dirty'));
        $count = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'purchase_product_groups'))->count();
        DB::disableQueryLog();
        expect($page->instance()->getTable()->getColumn('direction'))->toBeInstanceOf(ViewColumn::class);

        return $count;
    };
    $before = $measure();
    foreach (range(1, 20) as $i) {
        $this->catalog->createManual(['name' => 'Visible '.$i]);
    }
    expect($measure())->toBe($before);
    $views = file_get_contents(resource_path('views/filament/resources/purchases/classification-direction.blade.php'))
        .file_get_contents(resource_path('views/filament/resources/purchases/classification-group.blade.php'));
    expect($views)->not->toContain('wire:model.live', 'updateTableColumnState', 'wire:change');
});

test('classification batches never modify Finance records or original RS source rows', function () {
    FinanceTransaction::create(['type' => 'expense', 'category' => 'rent', 'transaction_date' => now(), 'amount' => 45, 'currency' => 'GEL', 'payment_method' => 'bank_transfer']);
    app(PurchaseImportService::class)->import(base_path('tests/fixtures/rs-items-georgian.csv'));
    $product = PurchaseProduct::whereHas('items')->firstOrFail();
    $tables = ['purchases', 'purchase_items', 'finance_transactions', 'partner_finance_transactions', 'cashbox_transactions', 'bank_transactions', 'payments'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $snapshot();
    Livewire::test(PurchaseProducts::class)->set('pendingClassifications.'.$product->id, ['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->group->id])
        ->call('saveClassifications')->assertHasNoErrors();
    expect($snapshot())->toEqual($before);
    $supplier = $product->supplier_id;
    expect($this->catalog->resolve($supplier, $product->name, $product->rs_product_code, $product->supplier_product_code)->purchase_product_group_id)->toBe($this->group->id);
    $file = tempnam(sys_get_temp_dir(), 'bulk-rs');
    rename($file, $file .= '.csv');
    try {
        file_put_contents($file, str_replace('RS-TEST-', 'RS-BULK-', file_get_contents(base_path('tests/fixtures/rs-items-georgian.csv'))));
        expect(app(PurchaseImportService::class)->import($file)['errors'])->toBeEmpty();
        $future = PurchaseItem::whereHas('purchase', fn ($q) => $q->where('document_number', 'RS-BULK-1'))
            ->where('purchase_product_id', $product->id)->firstOrFail();
        expect($future->purchaseProduct->expense_direction_id)->toBe($this->therapy)
            ->and($future->purchaseProduct->purchase_product_group_id)->toBe($this->group->id);
    } finally {
        unlink($file);
    }
});

test('unknown rows and unauthorized users cannot invoke batch updates', function () {
    Livewire::test(PurchaseProducts::class)->set('pendingClassifications.99999', ['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => null])
        ->call('saveClassifications')->assertHasErrors(['pendingClassifications']);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    Livewire::test(PurchaseProducts::class)->assertForbidden();
    expect(fn () => app(PurchaseClassificationBatch::class)->save([], []))->toThrow(AuthorizationException::class);
});

test('pending edits survive pagination and saved rows become clean even outside the current page', function () {
    foreach (range(1, 28) as $i) {
        $this->catalog->createManual(['name' => 'Z Product '.$i]);
    }
    $page = Livewire::test(PurchaseProducts::class)
        ->set('pendingClassifications.'.$this->a->id.'.expense_direction_id', $this->therapy)
        ->call('gotoPage', 2)->assertSet('pendingClassifications.'.$this->a->id.'.expense_direction_id', $this->therapy);
    expect($this->a->fresh()->expense_direction_id)->toBeNull();
    $page->call('saveClassifications')->assertHasNoErrors()
        ->assertSet('classificationOriginals.'.$this->a->id.'.expense_direction_id', $this->therapy);
    expect($this->a->fresh()->expense_direction_id)->toBe($this->therapy);
});

test('clients cannot replace the locked original classification snapshot', function () {
    expect(fn () => Livewire::test(PurchaseProducts::class)
        ->set('classificationOriginals.'.$this->a->id.'.expense_direction_id', $this->therapy))
        ->toThrow(CannotUpdateLockedPropertyException::class);
});
