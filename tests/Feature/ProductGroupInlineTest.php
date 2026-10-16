<?php

use App\Filament\Resources\Purchases\Pages\PurchaseGroupProducts;
use App\Filament\Resources\Purchases\Pages\PurchaseProducts;
use App\Filament\Resources\Purchases\Pages\UncategorizedProducts;
use App\Models\FinanceTransaction;
use App\Models\PurchaseProductGroup;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\PurchaseCatalog;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->catalog = app(PurchaseCatalog::class);
    $this->therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    $this->surgery = app(ExpenseDimensions::class)->id('direction', 'surgery');
    $this->product = $this->catalog->createManual(['name' => 'Estelite', 'expense_direction_id' => $this->therapy]);
    $this->therapyGroup = $this->catalog->createGroup(['name' => 'Filling material', 'expense_direction_id' => $this->therapy]);
    $this->surgeryGroup = $this->catalog->createGroup(['name' => 'Sutures', 'expense_direction_id' => $this->surgery]);
});

test('catalog row filters Product Groups reactively by Direction', function () {
    $page = Livewire::test(PurchaseProducts::class);
    $column = $page->instance()->getTable()->getColumn('product_group')->record($this->product);
    expect($column->getOptions())->toBe([$this->therapyGroup->id => 'Filling material']);
    $page->call('updateTableColumnState', 'direction', (string) $this->product->id, (string) $this->surgery);
    $column->record($this->product->fresh());
    expect($column->getOptions())->toBe([$this->surgeryGroup->id => 'Sutures']);
});

test('Owner creates a group beside the row selector with Direction prefilled and immediate selection', function (string $pageClass) {
    $page = Livewire::test($pageClass)->mountTableAction('createGroup', $this->product)
        ->assertTableActionDataSet(['expense_direction_id' => $this->therapy])
        ->setTableActionData(['name' => 'Bond'])
        ->callMountedTableAction()->assertHasNoTableActionErrors()->assertNoRedirect();
    $group = PurchaseProductGroup::where('name', 'Bond')->sole();
    expect($group->expense_direction_id)->toBe($this->therapy)
        ->and($this->product->fresh()->purchase_product_group_id)->toBe($group->id)
        ->and($this->catalog->directionGroupOptions($this->therapy))->toHaveKey($group->id);
    $column = $page->instance()->getTable()->getColumn('product_group')->record($this->product->fresh());
    expect((int) $column->getState())->toBe($group->id);
})->with([[PurchaseProducts::class], [UncategorizedProducts::class]]);

test('Direction change clears an incompatible group without changing other products', function () {
    $this->catalog->assignCatalogGroup($this->product, $this->therapyGroup->id);
    $other = $this->catalog->createManual(['name' => 'Other', 'expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->therapyGroup->id]);
    $snapshot = $other->fresh()->getRawOriginal();
    Livewire::test(PurchaseProducts::class)->call('updateTableColumnState', 'direction', (string) $this->product->id, (string) $this->surgery);
    expect($this->product->fresh()->purchase_product_group_id)->toBeNull()
        ->and($this->product->fresh()->expense_direction_id)->toBe($this->surgery)
        ->and($other->fresh()->getRawOriginal())->toEqual($snapshot);
});

test('group creation requires a Direction and cannot forge a different row Direction', function () {
    $this->product->update(['expense_direction_id' => null]);
    Livewire::test(PurchaseProducts::class)->assertTableActionHidden('createGroup', $this->product);
    expect(fn () => $this->catalog->createGroup(['name' => 'Orphan']))->toThrow(ValidationException::class);
    expect(fn () => $this->catalog->createGroupForProduct($this->product, ['name' => 'Orphan', 'expense_direction_id' => $this->therapy]))->toThrow(ValidationException::class);
    $this->product->update(['expense_direction_id' => $this->therapy]);
    expect(fn () => $this->catalog->createGroupForProduct($this->product, ['name' => 'Wrong direction', 'expense_direction_id' => $this->surgery]))->toThrow(ValidationException::class);
    expect(PurchaseProductGroup::count())->toBe(2);
});

test('duplicates follow existing unique-name rules and leave the current selection unchanged', function () {
    $this->catalog->assignCatalogGroup($this->product, $this->therapyGroup->id);
    $before = $this->product->fresh()->getRawOriginal();
    expect(fn () => $this->catalog->createGroupForProduct($this->product, ['name' => '  filling  material ', 'expense_direction_id' => $this->therapy]))->toThrow(ValidationException::class);
    expect($this->product->fresh()->getRawOriginal())->toEqual($before)->and(PurchaseProductGroup::count())->toBe(2);
    Livewire::test(PurchaseProducts::class)->callTableAction('createGroup', $this->product, data: [
        'name' => 'Filling material', 'expense_direction_id' => $this->therapy,
    ])->assertHasTableActionErrors(['name']);
});

test('inline group creation does not create financial movements or alter historical classifications', function () {
    FinanceTransaction::create(['type' => 'expense', 'category' => 'rent', 'transaction_date' => now(),
        'amount' => 45, 'currency' => 'GEL', 'payment_method' => 'bank_transfer']);
    $tables = ['finance_transactions', 'partner_finance_transactions', 'cashbox_transactions', 'bank_transactions', 'payments', 'purchase_items'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $snapshot();
    $totals = app(AccountingLedger::class)->pnlTotals(null, null)->toArray();
    $groups = PurchaseProductGroup::orderBy('id')->get()->map->getRawOriginal()->all();
    Livewire::test(PurchaseProducts::class)->callTableAction('createGroup', $this->product, data: ['name' => 'Cement', 'expense_direction_id' => $this->therapy])->assertHasNoTableActionErrors();
    expect($snapshot())->toEqual($before)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toArray())->toEqual($totals)
        ->and(PurchaseProductGroup::orderBy('id')->limit(2)->get()->map->getRawOriginal()->all())->toEqual($groups);
});

test('legacy groups resolve only an unambiguous Direction without rewriting records', function () {
    $legacy = PurchaseProductGroup::create(['name' => 'Legacy']);
    $this->product->update(['purchase_product_group_id' => $legacy->id]);
    $snapshot = $legacy->fresh()->getRawOriginal();
    expect($this->catalog->directionGroupOptions($this->therapy))->toHaveKey($legacy->id)
        ->and($legacy->fresh()->getRawOriginal())->toEqual($snapshot);
    $this->catalog->createManual(['name' => 'Mixed legacy product', 'expense_direction_id' => $this->surgery, 'purchase_product_group_id' => null])
        ->update(['purchase_product_group_id' => $legacy->id]);
    expect($this->catalog->directionGroupOptions($this->therapy))->not->toHaveKey($legacy->id)
        ->and($this->catalog->directionGroupOptions($this->surgery))->not->toHaveKey($legacy->id)
        ->and($legacy->fresh()->getRawOriginal())->toEqual($snapshot);
});

test('forged incompatible group assignment is rejected by the backend', function () {
    expect(fn () => $this->catalog->assignCatalogGroup($this->product, $this->surgeryGroup->id))->toThrow(ValidationException::class);
    expect($this->product->fresh()->purchase_product_group_id)->toBeNull();
});

test('new-product modal uses the same Direction-first inline group selector', function () {
    $page = Livewire::test(PurchaseProducts::class)->mountTableAction('createProduct')
        ->setTableActionData(['name' => 'Filtek', 'expense_direction_id' => $this->therapy]);
    $page->callAction(TestAction::make('createOption')->schemaComponent('purchase_product_group_id'))
        ->assertActionDataSet(['expense_direction_id' => $this->therapy])
        ->setActionData(['name' => 'Inline new group'])->callMountedAction()->assertHasNoActionErrors();
    $group = PurchaseProductGroup::where('name', 'Inline new group')->sole();
    $page->assertTableActionDataSet(['purchase_product_group_id' => $group->id])->assertNoRedirect();
    $page->setTableActionData(['expense_direction_id' => $this->surgery])
        ->assertTableActionDataSet(['purchase_product_group_id' => null]);
});

test('group product listing shares the inline action and stays on its current page', function () {
    $this->catalog->assignCatalogGroup($this->product, $this->therapyGroup->id);
    Livewire::test(PurchaseGroupProducts::class, ['group' => $this->therapyGroup->id])
        ->callTableAction('createGroup', $this->product, data: ['name' => 'Bond', 'expense_direction_id' => $this->therapy])
        ->assertHasNoTableActionErrors()->assertNoRedirect()->assertSet('groupId', $this->therapyGroup->id);
    expect($this->product->fresh()->purchase_product_group_id)->toBe(PurchaseProductGroup::where('name', 'Bond')->sole()->id);
});

test('unauthorized users cannot access catalog or invoke inline group creation', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    Livewire::test(PurchaseProducts::class)->assertForbidden();
    expect(fn () => $this->catalog->createGroup(['name' => 'Forbidden', 'expense_direction_id' => $this->therapy]))->toThrow(AuthorizationException::class);
    expect(fn () => $this->catalog->createGroupForProduct($this->product, ['name' => 'Forbidden', 'expense_direction_id' => $this->therapy]))->toThrow(AuthorizationException::class);
})->with([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);
