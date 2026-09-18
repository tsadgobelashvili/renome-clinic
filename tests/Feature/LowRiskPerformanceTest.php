<?php

use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Models\CashboxDay;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Finance\LiquidityReport;
use App\Services\FinanceUsdUsageService;
use App\Support\CashboxManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function performanceQueries(callable $callback): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $result = $callback();

        return [$result, collect(DB::getQueryLog())];
    } finally {
        DB::disableQueryLog();
    }
}

test('RS edit reuses eager loaded labels on mount and refresh regardless of document size', function (int $size) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $supplier = Supplier::create(['name' => 'Label supplier']);
    $purchase = Purchase::create(['supplier_id' => $supplier->id, 'purchase_date' => today(), 'source' => 'rs']);
    $items = collect();
    for ($i = 0; $i < $size; $i++) {
        $product = PurchaseProduct::create(['supplier_id' => $supplier->id, 'name' => 'Material '.$i,
            'normalized_name' => 'material '.$i, 'identity_key' => hash('sha256', 'label-'.$i)]);
        $items->push($purchase->items()->create(['purchase_product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]));
    }
    $singleLookups = fn ($queries) => $queries->filter(fn ($q) => str_contains($q['query'], 'from "purchase_products" where "purchase_products"."id" ='));
    [$page, $mountQueries] = performanceQueries(fn () => Livewire::test(EditPurchase::class, ['record' => $purchase->id])->assertSuccessful());
    expect($singleLookups($mountQueries))->toHaveCount(0);
    [, $refreshQueries] = performanceQueries(fn () => $page->call('$refresh')->assertSuccessful());
    expect($singleLookups($refreshQueries))->toHaveCount(0);

    // Compare the old label callback to the current one on the actual mounted fields.
    $fields = $items->map(fn ($item) => $page->instance()->form->getComponentByStatePath('items.record-'.$item->id.'.purchase_product_id'));
    [, $before] = performanceQueries(function () use ($fields): void {
        foreach ($fields as $field) {
            (clone $field)->getOptionLabelUsing(fn ($value) => PurchaseProduct::find($value)?->name)->getOptionLabel();
        }
    });
    [$labels, $after] = performanceQueries(fn () => $fields->map(fn ($field) => $field->getOptionLabel())->all());
    expect($labels)->toBe(array_map(fn ($i) => 'Material '.$i, range(0, $size - 1)))
        ->and($singleLookups($before))->toHaveCount($size)
        ->and($after)->toHaveCount(0);
})->with([1, 20]);

test('RS product search and changed selection retain supplier scoped labels and IDs', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $supplier = Supplier::create(['name' => 'Selected supplier']);
    $other = Supplier::create(['name' => 'Other supplier']);
    $products = collect([$supplier, $supplier, $other])->map(fn ($s, $i) => PurchaseProduct::create([
        'supplier_id' => $s->id, 'name' => 'Material '.$i, 'normalized_name' => 'material '.$i, 'identity_key' => hash('sha256', 'selection-'.$i),
    ]));
    $purchase = Purchase::create(['supplier_id' => $supplier->id, 'purchase_date' => today(), 'source' => 'rs']);
    $item = $purchase->items()->create(['purchase_product_id' => $products[0]->id, 'quantity' => 1, 'unit_price' => 10]);
    $path = 'items.record-'.$item->id.'.purchase_product_id';
    $page = Livewire::test(EditPurchase::class, ['record' => $purchase->id]);
    $page->assertFormFieldExists($path, fn ($field) => $field->getSearchResults('MATERIAL') === $products->take(2)->pluck('name', 'id')->all());
    $page->set('data.'.$path, $products[1]->id)
        ->assertFormFieldExists($path, fn ($field) => $field->getOptionLabel() === 'Material 1')
        ->call('save')->assertHasNoFormErrors();
    expect($item->fresh()->purchase_product_id)->toBe($products[1]->id);
    Livewire::test(EditPurchase::class, ['record' => $purchase->id])
        ->assertFormFieldExists($path, fn ($field) => $field->getOptionLabel() === 'Material 1');
});

test('unsaved RS rows batch their selected product labels without loading the supplier catalog', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $supplier = Supplier::create(['name' => 'New rows supplier']);
    $products = collect(range(1, 21))->map(fn ($i) => PurchaseProduct::create([
        'supplier_id' => $supplier->id, 'name' => 'New material '.$i, 'normalized_name' => 'new material '.$i,
        'identity_key' => hash('sha256', 'new-label-'.$i),
    ]));
    $page = Livewire::test(CreatePurchase::class);
    [, $queries] = performanceQueries(fn () => $page->fillForm([
        'supplier_id' => $supplier->id,
        'items' => $products->take(20)->map(fn ($product) => [
            'purchase_product_id' => $product->id, 'item_name' => $product->name,
            'quantity' => 1, 'unit_price' => 10, 'line_total' => 10,
        ])->all(),
    ]));
    $labels = $queries->filter(fn ($q) => str_starts_with($q['query'], 'select "name", "id" from "purchase_products"'));
    expect($labels)->toHaveCount(1)
        ->and($labels->first()['query'])->toContain('"supplier_id" =', '"purchase_products"."id" in');
    foreach (array_keys($page->get('data.items')) as $index => $key) {
        $page->assertFormFieldExists('items.'.$key.'.purchase_product_id', fn ($field) => $field->getOptionLabel() === 'New material '.($index + 1));
    }
});

test('liquidity reuses physical cash snapshot without changing source currencies or freshness', function () {
    $this->travelTo('2026-09-18 12:00:00');
    CashboxDay::create(['date' => today(), 'opening_balance' => 1000, 'opening_balance_usd' => 100, 'status' => 'open']);
    $patient = Patient::create(['first_name' => 'Liquidity', 'last_name' => 'Test', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    foreach (['GEL' => 200, 'USD' => 50] as $currency => $amount) {
        $patient->partnerPayments()->create(['currency' => $currency, 'amount' => $amount, 'payment_method' => 'cash', 'paid_at' => today()]);
    }
    DB::table('partner_finance_transactions')->insert([
        ['source' => 'clinic', 'type' => 'transfer', 'currency' => 'USD', 'amount' => 10, 'from_account' => 'cash', 'to_account' => 'bank', 'transacted_at' => today()],
        ['source' => 'israeli', 'type' => 'expense', 'currency' => 'GEL', 'amount' => 20, 'from_account' => 'cash', 'to_account' => null, 'transacted_at' => today()],
    ]);
    [$expected, $before] = performanceQueries(function () {
        app(CashboxManager::class)->physicalCashSnapshot();
        $service = app(FinanceUsdUsageService::class);

        return ['clinic' => $service->cashBalances('clinic'), 'israeli' => $service->cashBalances('israeli')];
    });
    [$result, $after] = performanceQueries(fn () => app(LiquidityReport::class)->current());
    expect($before)->toHaveCount(10)->and($after)->toHaveCount(7)
        ->and($result['totals']['GEL']['cash'])->toBe(1180.0)
        ->and($result['totals']['USD']['cash'])->toBe(140.0)
        ->and($result['totals']['USD']['bank'])->toBeNull();
    $physical = $after->filter(fn ($q) => str_contains($q['query'], 'AS received'));
    expect($physical)->toHaveCount(1);
    foreach ($expected as $source => $balances) {
        $filtered = app(LiquidityReport::class)->current($source);
        foreach ($balances as $currency => $amount) {
            expect($filtered['totals'][$currency]['cash'])->toBe($amount);
        }
    }
    $patient->partnerPayments()->create(['currency' => 'USD', 'amount' => 5, 'payment_method' => 'cash', 'paid_at' => today()]);
    expect(app(LiquidityReport::class)->current()['totals']['USD']['cash'])->toBe(145.0);
});

test('purchase document index is reversible and preserves existing purchase leading indexes', function () {
    $migration = require database_path('migrations/2026_09_18_150000_add_purchase_item_document_lookup_index.php');
    expect(Schema::hasIndex('purchase_items', 'purchase_items_purchase_lookup_idx'))->toBeTrue();
    $migration->up();
    $migration->down();
    expect(Schema::hasIndex('purchase_items', 'purchase_items_purchase_lookup_idx'))->toBeFalse();
    $migration->up();
    expect(Schema::hasIndex('purchase_items', ['purchase_id']))->toBeTrue();
    $migration->down();
    Schema::table('purchase_items', fn (Blueprint $table) => $table->index(['purchase_id', 'id'], 'existing_purchase_leading'));
    $migration->up();
    expect(Schema::hasIndex('purchase_items', 'purchase_items_purchase_lookup_idx'))->toBeFalse();
    $migration->down();
    expect(Schema::hasIndex('purchase_items', 'existing_purchase_leading'))->toBeTrue();
});
