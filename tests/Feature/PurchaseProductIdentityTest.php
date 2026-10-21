<?php

use App\Filament\Resources\Purchases\Pages\PurchaseProducts;
use App\Models\FinanceTransaction;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseAnalysis;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseClassificationBatch;
use App\Services\PurchaseImportService;
use App\Services\PurchaseProductIdentity;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->catalog = app(PurchaseCatalog::class);
    $this->supplier = Supplier::create(['name' => 'Identity supplier']);
    $this->therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    $this->surgery = app(ExpenseDimensions::class)->id('direction', 'surgery');
    $this->group = $this->catalog->createGroup(['name' => 'Fillings', 'expense_direction_id' => $this->therapy]);
    $this->product = $this->catalog->resolve($this->supplier->id, 'Estelite Sigma Quick A2', 'SKU-A2');
    $this->duplicate = PurchaseProduct::create([...$this->product->getAttributes(), 'id' => null,
        'identity_key' => hash('sha256', 'legacy identity'), 'expense_direction_id' => null]);
    $this->originals = [$this->product->id => $this->product->getAttributes()];
    $this->pending = [$this->product->id => ['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->group->id]];
});

test('stable supplier identities already reuse one canonical product', function () {
    expect($this->catalog->resolve($this->supplier->id, 'Changed display name', 'SKU-A2')->id)->toBe($this->product->id);
    $other = Supplier::create(['name' => 'Different supplier']);
    expect($this->catalog->resolve($other->id, $this->product->name, 'SKU-A2')->id)->not->toBe($this->product->id);
});

test('full normalized names are exact only and never erase shades sizes or models', function () {
    $first = $this->catalog->resolve($this->supplier->id, '  ESTELITE   Sigma Quick A2  ');
    expect($this->catalog->resolve($this->supplier->id, 'estelite sigma quick a2')->id)->toBe($first->id);
    foreach (['Estelite Sigma Quick A3', 'Estelite A2', 'Estelite Sigma Quick A2 5ml', 'Estelite Sigma Quick A2 10ml'] as $name) {
        expect($this->catalog->resolve($this->supplier->id, $name)->id)->not->toBe($first->id);
    }
    expect(app(PurchaseProductIdentity::class)->same($this->product, $first))->toBeFalse();
});

test('supplier code fallback never joins two conflicting RS codes', function () {
    $this->product->update(['supplier_product_code' => 'SAME']);
    $this->duplicate->update(['supplier_product_code' => 'SAME', 'rs_product_code' => 'OTHER']);
    expect(app(PurchaseProductIdentity::class)->same($this->product, $this->duplicate))->toBeFalse();
    $this->duplicate->update(['rs_product_code' => null]);
    expect(app(PurchaseProductIdentity::class)->same($this->product, $this->duplicate))->toBeTrue();
});

test('supplier fallback remembers an explicit RS alias for subsequent imports', function () {
    $old = $this->catalog->resolve($this->supplier->id, 'Supplier-only product', null, 'SUP-1');
    $this->catalog->assignCatalogDirection($old, $this->therapy);
    expect($this->catalog->resolve($this->supplier->id, 'RS label', 'NEW-RS', 'SUP-1')->id)->toBe($old->id)
        ->and($this->catalog->resolve($this->supplier->id, 'Next label', 'NEW-RS')->expense_direction_id)->toBe($this->therapy);
});

test('a code-free bridge cannot consolidate contradictory RS identities', function () {
    $this->product->update(['supplier_product_code' => 'BRIDGE']);
    $this->duplicate->update(['supplier_product_code' => 'BRIDGE', 'rs_product_code' => 'OTHER']);
    $bridge = $this->catalog->resolve($this->supplier->id, 'Bridge', null, 'BRIDGE');
    $pending = [$bridge->id => $this->pending[$this->product->id]];
    $plan = app(PurchaseProductIdentity::class)->preview($pending, [$bridge->id => $bridge->getAttributes()]);
    expect($plan[$bridge->id]['conflict'])->toBeTrue();
});

test('exact full-name legacy duplicates can share classification after confirmation', function () {
    $this->product->update(['rs_product_code' => null, 'name' => ' Estelite   Sigma Quick A2 ']);
    $this->duplicate->update(['rs_product_code' => null, 'name' => 'ESTELITE SIGMA QUICK A2']);
    $originals = [$this->product->id => $this->product->fresh()->getAttributes()];
    $plan = app(PurchaseProductIdentity::class)->preview($this->pending, $originals);
    expect($plan[$this->product->id]['conflict'])->toBeFalse();
    app(PurchaseClassificationBatch::class)->save($this->pending, $originals, true, $plan);
    expect(app(PurchaseProductIdentity::class)->canonical($this->duplicate)->purchase_product_group_id)->toBe($this->group->id);
});

test('a classified product can propagate to an unclassified counterpart', function () {
    $this->product->update(['expense_direction_id' => $this->therapy]);
    $originals = [$this->product->id => $this->product->fresh()->getAttributes()];
    $plan = app(PurchaseProductIdentity::class)->preview($this->pending, $originals);
    app(PurchaseClassificationBatch::class)->save($this->pending, $originals, true, $plan);
    expect(app(PurchaseProductIdentity::class)->canonical($this->duplicate)->expense_direction_id)->toBe($this->therapy);
});

test('non-owners cannot preview or apply identity propagation', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    Livewire::test(PurchaseProducts::class)->assertForbidden();
    expect(fn () => app(PurchaseProductIdentity::class)->preview($this->pending, $this->originals))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(PurchaseClassificationBatch::class)->save($this->pending, $this->originals, true, []))
        ->toThrow(AuthorizationException::class);
});

test('batch save asks before aliasing separate identical catalog records', function () {
    $page = Livewire::test(PurchaseProducts::class)->set('pendingClassifications.'.$this->product->id, $this->pending[$this->product->id])
        ->call('saveClassifications')->assertActionMounted('confirmIdentical')->assertNoRedirect();
    expect($this->product->fresh()->expense_direction_id)->toBeNull()->and(DB::table('purchase_product_aliases')->count())->toBe(0);
    $page->callMountedAction()->assertHasNoErrors();
    expect($this->product->fresh()->purchase_product_group_id)->toBe($this->group->id)
        ->and($this->duplicate->fresh()->expense_direction_id)->toBeNull()
        ->and(app(PurchaseProductIdentity::class)->canonical($this->duplicate)->id)->toBe($this->product->id);
    expect(app(PurchaseProductIdentity::class)->visible(PurchaseProduct::query())->count())->toBe(1);
    expect($this->catalog->resolve($this->supplier->id, 'Next purchase', 'SKU-A2')->id)->toBe($this->product->id);
});

test('only this product retains separate records and no aliases', function () {
    Livewire::test(PurchaseProducts::class)->set('pendingClassifications.'.$this->product->id, $this->pending[$this->product->id])
        ->call('saveClassifications')->callMountedAction(['onlySelected' => true])->assertHasNoErrors();
    expect($this->product->fresh()->expense_direction_id)->toBe($this->therapy)
        ->and($this->duplicate->fresh()->expense_direction_id)->toBeNull()->and(DB::table('purchase_product_aliases')->count())->toBe(0);
});

test('no redundant confirmation for many occurrences of one canonical product', function () {
    $this->duplicate->delete();
    Livewire::test(PurchaseProducts::class)->set('pendingClassifications.'.$this->product->id, $this->pending[$this->product->id])
        ->call('saveClassifications')->assertHasNoErrors()->assertActionNotMounted('confirmIdentical');
    expect($this->product->fresh()->expense_direction_id)->toBe($this->therapy);
});

test('existing conflicting classifications block propagation atomically', function () {
    $this->product->update(['expense_direction_id' => $this->therapy]);
    $this->duplicate->update(['expense_direction_id' => $this->surgery]);
    $originals = [$this->product->id => $this->product->fresh()->getAttributes()];
    $plan = app(PurchaseProductIdentity::class)->preview($this->pending, $originals);
    expect($plan[$this->product->id]['conflict'])->toBeTrue();
    expect(fn () => app(PurchaseClassificationBatch::class)->save($this->pending, $originals, true, $plan))->toThrow(ValidationException::class);
    expect($this->product->fresh()->purchase_product_group_id)->toBeNull()
        ->and($this->duplicate->fresh()->expense_direction_id)->toBe($this->surgery)->and(DB::table('purchase_product_aliases')->count())->toBe(0);
});

test('confirmed propagation rechecks concurrent counterpart edits', function () {
    $plan = app(PurchaseProductIdentity::class)->preview($this->pending, $this->originals);
    $this->duplicate->update(['expense_direction_id' => $this->surgery]);
    expect(fn () => app(PurchaseClassificationBatch::class)->save($this->pending, $this->originals, true, $plan))->toThrow(ValidationException::class);
    expect($this->product->fresh()->expense_direction_id)->toBeNull()->and(DB::table('purchase_product_aliases')->count())->toBe(0);
});

test('confirmation cannot be reused for a different pending classification', function () {
    $plan = app(PurchaseProductIdentity::class)->preview($this->pending, $this->originals);
    $pending = [$this->product->id => ['expense_direction_id' => $this->surgery, 'purchase_product_group_id' => null]];
    expect(fn () => app(PurchaseClassificationBatch::class)->save($pending, $this->originals, true, $plan))->toThrow(ValidationException::class);
    expect($this->product->fresh()->expense_direction_id)->toBeNull()->and(DB::table('purchase_product_aliases')->count())->toBe(0);
});

test('conflict is visible in the modal and only-selected does not overwrite the counterpart', function () {
    $this->duplicate->update(['expense_direction_id' => $this->surgery]);
    $page = Livewire::test(PurchaseProducts::class)->set('pendingClassifications.'.$this->product->id, $this->pending[$this->product->id])
        ->call('saveClassifications')->assertActionMounted('confirmIdentical');
    expect($page->instance()->getMountedAction()->getModalDescription())->toContain(__('catalog-classification.identity_conflict'));
    expect($page->instance()->getMountedAction()->getModalSubmitAction()->isDisabled())->toBeTrue();
    $page->callMountedAction(['onlySelected' => true])->assertHasNoErrors();
    expect($this->product->fresh()->expense_direction_id)->toBe($this->therapy)
        ->and($this->duplicate->fresh()->expense_direction_id)->toBe($this->surgery)->and(DB::table('purchase_product_aliases')->count())->toBe(0);
});

test('canonical analysis combines legacy history and frequency without changing source or Finance', function () {
    $path = tempnam(sys_get_temp_dir(), 'identity');
    rename($path, $path .= '.csv');
    $file = fopen($path, 'w');
    fputcsv($file, ['Date', 'Supplier', 'Product', 'RS Product Code', 'Quantity', 'Unit Price', 'Total', 'Document'], escape: '');
    foreach (['2026-01-01', '2026-01-31', '2026-03-02'] as $date) {
        fputcsv($file, [$date, $this->supplier->name, $this->product->name, 'SKU-A2', 2, 10, 20, 'IDENTITY-'.$date], escape: '');
    }
    fclose($file);
    try {
        expect(app(PurchaseImportService::class)->import($path)['errors'])->toBeEmpty();
    } finally {
        unlink($path);
    }
    DB::table('purchase_items')->where('id', DB::table('purchase_items')->min('id'))->update(['purchase_product_id' => $this->duplicate->id]);
    FinanceTransaction::create(['type' => 'expense', 'category' => 'rent', 'transaction_date' => now(), 'amount' => 45, 'currency' => 'GEL', 'payment_method' => 'bank_transfer']);
    $tables = ['purchases', 'purchase_items', 'finance_transactions', 'cashbox_transactions', 'bank_transactions', 'payments'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->get()->toJson()])->all();
    $before = $snapshot();
    $plan = app(PurchaseProductIdentity::class)->preview($this->pending, $this->originals);
    app(PurchaseClassificationBatch::class)->save($this->pending, $this->originals, true, $plan);
    expect($snapshot())->toBe($before);
    $analysis = app(PurchaseAnalysis::class);
    $row = $analysis->products(['from' => '2026-03-01'], (string) $this->group->id)->sole();
    expect((int) $row->product_id)->toBe($this->product->id)->and((float) $row->average_interval)->toBe(30.0);
    $row = $analysis->products([], (string) $this->group->id)->sole();
    expect((int) $row->purchase_count)->toBe(3)->and((float) $row->purchase_amount)->toBe(60.0)
        ->and($analysis->history([], (string) $this->group->id, (string) $this->product->id)->count())->toBe(3);
});
