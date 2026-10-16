<?php

use App\Filament\Resources\Purchases\Pages\PurchaseProducts;
use App\Models\ExpenseCategory;
use App\Models\PurchaseProductGroup;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    app()->setLocale('ka');
    $this->catalog = app(PurchaseCatalog::class);
    $this->lab = app(ExpenseDimensions::class)->id('direction', 'laboratory');
    $this->therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    $direction = new ExpenseCategory;
    $direction->forceFill(['name' => 'სტერილიზაცია', 'classification_dimension' => 'direction', 'active' => true, 'sort_order' => 0])->save();
    $this->sterilization = $direction->id;
});

test('same Georgian group name is valid in three Directions but not twice within one', function () {
    foreach ([$this->lab, $this->sterilization, $this->therapy] as $direction) {
        $group = $this->catalog->createGroup(['name' => 'ტექნიკა', 'expense_direction_id' => $direction]);
        expect($group->fresh()->normalized_name)->toBe('ტექნიკა');
    }
    expect(PurchaseProductGroup::count())->toBe(3);
    try {
        $this->catalog->createGroup(['name' => ' ტექნიკა ', 'expense_direction_id' => $this->sterilization]);
        $this->fail('A true duplicate must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['name'])->toBe(['ამ მიმართულებაში ასეთი პროდუქციის ჯგუფი უკვე არსებობს.']);
    }
    expect(PurchaseProductGroup::count())->toBe(3);
});

test('inline creation selects the same-named group from the current Direction without finance writes', function () {
    $labGroup = $this->catalog->createGroup(['name' => 'ტექნიკა', 'expense_direction_id' => $this->lab]);
    $labProduct = $this->catalog->createManual(['name' => 'Lab equipment', 'expense_direction_id' => $this->lab, 'purchase_product_group_id' => $labGroup->id]);
    $product = $this->catalog->createManual(['name' => 'Sterilizer', 'expense_direction_id' => $this->sterilization]);
    $snapshot = $labProduct->fresh()->getRawOriginal();
    $tables = ['finance_transactions', 'partner_finance_transactions', 'cashbox_transactions', 'bank_transactions', 'payments'];
    $finance = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $finance();
    $page = Livewire::test(PurchaseProducts::class)
        ->callTableAction('createGroup', $product, data: ['name' => 'ტექნიკა', 'expense_direction_id' => $this->sterilization])
        ->assertHasNoTableActionErrors()->assertNoRedirect();
    $group = PurchaseProductGroup::where('expense_direction_id', $this->sterilization)->sole();
    expect($product->fresh()->purchase_product_group_id)->toBe($group->id)
        ->and($group->id)->not->toBe($labGroup->id)
        ->and($labProduct->fresh()->getRawOriginal())->toEqual($snapshot)->and($finance())->toEqual($before);
    $page->callTableAction('createGroup', $product, data: ['name' => 'ტექნიკა', 'expense_direction_id' => $this->sterilization])
        ->assertHasTableActionErrors(['name' => 'ამ მიმართულებაში ასეთი პროდუქციის ჯგუფი უკვე არსებობს.']);
    $page->call('updateTableColumnState', 'direction', (string) $product->id, (string) $this->lab);
    expect($product->fresh()->purchase_product_group_id)->toBeNull()
        ->and($this->catalog->directionGroupOptions($this->lab))->toBe([$labGroup->id => 'ტექნიკა'])
        ->and($this->catalog->directionGroupOptions($this->sterilization))->toBe([$group->id => 'ტექნიკა']);
});

test('database composite constraint enforces normalized same-Direction uniqueness', function () {
    PurchaseProductGroup::create(['name' => ' BOND ', 'expense_direction_id' => $this->therapy]);
    PurchaseProductGroup::create(['name' => 'bond', 'expense_direction_id' => $this->lab]);
    expect(fn () => PurchaseProductGroup::create(['name' => 'bond', 'expense_direction_id' => $this->therapy]))->toThrow(QueryException::class);
});

test('group edits ignore themselves and validate against their new Direction', function () {
    $a = $this->catalog->createGroup(['name' => 'ტექნიკა', 'expense_direction_id' => $this->lab]);
    $b = $this->catalog->createGroup(['name' => 'ტექნიკა', 'expense_direction_id' => $this->therapy]);
    $this->catalog->updateGroup($a, ['name' => 'ტექნიკა', 'expense_direction_id' => $this->lab]);
    expect(fn () => $this->catalog->updateGroup($a, ['name' => 'ტექნიკა', 'expense_direction_id' => $this->therapy]))->toThrow(ValidationException::class);
    expect($a->fresh()->expense_direction_id)->toBe($this->lab)->and($b->fresh()->expense_direction_id)->toBe($this->therapy);
});

test('schema migration replaces global uniqueness while preserving groups and product references', function () {
    $migration = require database_path('migrations/2026_10_16_180000_scope_product_group_names_to_direction.php');
    $migration->down();
    $id = DB::table('purchase_product_groups')->insertGetId(['name' => ' ტექნიკა ', 'expense_direction_id' => $this->lab, 'created_at' => now(), 'updated_at' => now()]);
    $product = $this->catalog->createManual(['name' => 'Historical equipment', 'expense_direction_id' => $this->lab, 'purchase_product_group_id' => $id]);
    $original = DB::table('purchase_product_groups')->where('id', $id)->first();
    $productBefore = $product->fresh()->getRawOriginal();
    $migration->up();
    $after = DB::table('purchase_product_groups')->where('id', $id)->first();
    expect(array_diff_key((array) $after, ['normalized_name' => true]))->toEqual((array) $original)
        ->and($after->normalized_name)->toBe('ტექნიკა')->and($product->fresh()->getRawOriginal())->toEqual($productBefore);
    $indexes = collect(Schema::getIndexes('purchase_product_groups'))->keyBy('name');
    expect($indexes)->toHaveKey('purchase_groups_direction_name_unique')->not->toHaveKey('purchase_product_groups_name_unique');
    expect($indexes['purchase_groups_direction_name_unique']['columns'])->toBe(['expense_direction_id', 'normalized_name']);
    $this->catalog->createGroup(['name' => 'ტექნიკა', 'expense_direction_id' => $this->therapy]);
});

test('migration aborts before schema changes if normalized duplicates share a Direction', function () {
    $migration = require database_path('migrations/2026_10_16_180000_scope_product_group_names_to_direction.php');
    $migration->down();
    DB::table('purchase_product_groups')->insert([
        ['name' => 'BOND', 'expense_direction_id' => $this->therapy],
        ['name' => ' bond ', 'expense_direction_id' => $this->therapy],
    ]);
    $before = DB::table('purchase_product_groups')->orderBy('id')->get()->toJson();
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Conflicting Product Groups');
    expect(Schema::hasColumn('purchase_product_groups', 'normalized_name'))->toBeFalse()
        ->and(DB::table('purchase_product_groups')->orderBy('id')->get()->toJson())->toBe($before);
});

test('migration also checks legacy inferred Directions without reassigning those groups', function () {
    $migration = require database_path('migrations/2026_10_16_180000_scope_product_group_names_to_direction.php');
    $migration->down();
    $a = DB::table('purchase_product_groups')->insertGetId(['name' => 'Bond', 'expense_direction_id' => null]);
    $b = DB::table('purchase_product_groups')->insertGetId(['name' => ' bond ', 'expense_direction_id' => null]);
    foreach ([$a, $b] as $id) {
        $this->catalog->createManual(['name' => 'Legacy '.$id, 'expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $id]);
    }
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Conflicting Product Groups');
    expect(DB::table('purchase_product_groups')->whereNotNull('expense_direction_id')->count())->toBe(0);
});

test('rollback refuses to restore global uniqueness when valid cross-Direction duplicates exist', function () {
    foreach ([$this->lab, $this->therapy] as $direction) {
        $this->catalog->createGroup(['name' => 'ტექნიკა', 'expense_direction_id' => $direction]);
    }
    $before = DB::table('purchase_product_groups')->orderBy('id')->get()->toJson();
    $migration = require database_path('migrations/2026_10_16_180000_scope_product_group_names_to_direction.php');
    expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'Cannot restore global');
    expect(DB::table('purchase_product_groups')->orderBy('id')->get()->toJson())->toBe($before)
        ->and(Schema::hasColumn('purchase_product_groups', 'normalized_name'))->toBeTrue();
});
