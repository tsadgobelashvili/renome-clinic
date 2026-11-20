<?php

use App\Filament\Pages\Cashbox;
use App\Filament\Pages\Finance;
use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Filament\Resources\Purchases\Pages\PurchaseAnalysis as AnalysisPage;
use App\Models\BankTransaction;
use App\Models\CashboxTransaction;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\FinanceManager;
use App\Services\FinanceUsdUsageService;
use App\Services\PurchaseAnalysis;
use App\Services\PurchaseCashPayment;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-12 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'en']));
    Http::preventStrayRequests();
    $this->direction = app(ExpenseDimensions::class)->id('direction', 'general');
    $this->group = PurchaseProductGroup::create(['name' => 'Manual material test', 'expense_direction_id' => $this->direction]);
    $this->report = app(PurchaseAnalysis::class);
    $this->manager = app(FinanceManager::class);
});

function manualMaterialData(array $extra = []): array
{
    return array_replace(['type' => 'expense', 'category' => 'office', 'description' => 'Bond material',
        'amount' => 125.50, 'currency' => 'GEL', 'payment_method' => 'bank_transfer', 'transaction_date' => now()], $extra);
}

test('unclassified expenses retain their original accounting and are absent from purchasing analysis', function () {
    $expense = $this->manager->create(manualMaterialData(['material_product_group_id' => null]));
    expect($expense->material_quantity)->toBeNull()->and($expense->purchase_product_group_id)->toBeNull()
        ->and((float) $expense->amount)->toBe(125.50)->and($this->report->groups()->count())->toBe(0)
        ->and(FinanceTransaction::count())->toBe(1)->and(Purchase::count())->toBe(0)
        ->and(BankTransaction::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
});

test('each expense category can optionally record a group and quantity using its description', function (string $type) {
    Livewire::test(Finance::class)->callAction('add_expense', [
        ...manualMaterialData(), 'entry_expense_type_id' => app(ExpenseDimensions::class)->id('type', $type),
        'material_product_group_id' => $this->group->id, 'material_quantity' => '2.500001', 'material_unit' => 'pcs',
    ])->assertHasNoActionErrors();
    $expense = FinanceTransaction::sole();
    expect($expense->description)->toBe('Bond material')->and($expense->material_quantity)->toBe('2.500001')
        ->and($expense->material_unit)->toBe('pcs')->and($expense->purchase_product_group_id)->toBe($this->group->id)
        ->and($expense->purchase_product_id)->toBeNull()->and((float) $expense->amount)->toBe(125.50)
        ->and(Purchase::count())->toBe(0)->and(PurchaseItem::count())->toBe(0)->and(PurchaseProduct::count())->toBe(0);
})->with(['rent', 'services', 'materials']);

test('the new fields are optional and quantity is only displayed and required after selecting a group', function () {
    Livewire::test(Finance::class)->mountAction('add_expense')
        ->assertFormFieldIsVisible('material_product_group_id')->assertFormFieldIsHidden('material_quantity')
        ->set('mountedActions.0.data.material_product_group_id', $this->group->id)
        ->assertFormFieldIsVisible('material_quantity')
        ->fillForm([...manualMaterialData(), 'entry_expense_type_id' => app(ExpenseDimensions::class)->id('type', 'rent'),
            'material_product_group_id' => $this->group->id])->callMountedAction()
        ->assertHasFormErrors(['material_quantity' => 'required']);
    expect(FinanceTransaction::count())->toBe(0);
});

test('cashbox expense workflow posts one financial expense and its existing drawer movement only', function () {
    $type = app(ExpenseDimensions::class)->childForType($this->direction, app(ExpenseDimensions::class)->id('type', 'materials'));
    Livewire::test(Cashbox::class)->callAction('expense', [
        'expense_direction_id' => $this->direction, 'expense_type_id' => $type,
        'description' => 'Cashbox material', 'amount' => 50, 'currency' => 'USD', 'transaction_date' => now(),
        'material_product_group_id' => $this->group->id, 'material_quantity' => 2, 'material_unit' => 'kg',
    ])->assertHasNoActionErrors();
    $expense = FinanceTransaction::sole();
    expect($expense->currency)->toBe('USD')->and($expense->cash_source)->toBe('current_cashier')
        ->and(CashboxTransaction::where('finance_transaction_id', $expense->id)->count())->toBe(1)
        ->and((float) $this->report->groups()->sole()->purchase_amount)->toBe(50.0)
        ->and((float) $this->report->groups()->sole()->purchased_quantity)->toBe(2.0)
        ->and(Purchase::count())->toBe(0)->and(BankTransaction::count())->toBe(0);
});

test('expense sources currencies timestamps and amounts remain unchanged', function (string $method, ?string $source, string $currency) {
    $expense = $this->manager->create(manualMaterialData(['payment_method' => $method, 'cash_source' => $source,
        'currency' => $currency, 'transaction_date' => '2026-11-12 09:17:26',
        'material_product_group_id' => $this->group->id, 'material_quantity' => 5]));
    expect($expense->payment_method)->toBe($method)->and($expense->cash_source)->toBe($source)
        ->and($expense->currency)->toBe($currency)->and($expense->transaction_date->format('Y-m-d H:i:s'))->toBe('2026-11-12 09:17:26')
        ->and((float) $expense->amount)->toBe(125.50)->and(FinanceTransaction::count())->toBe(1)
        ->and(CashboxTransaction::count())->toBe($source === 'current_cashier' ? 1 : 0)->and(BankTransaction::count())->toBe(0);
})->with([['bank_transfer', null, 'GEL'], ['cash', 'withdrawn_cash', 'USD'], ['cash', 'current_cashier', 'GEL']]);

test('Israeli funding creates only the existing partner expense and is counted once', function () {
    $this->partialMock(FinanceUsdUsageService::class)->shouldReceive('cashBalances')->andReturn(['GEL' => 1000, 'USD' => 1000]);
    $expense = $this->manager->create(manualMaterialData(['payment_method' => 'cash', 'cash_source' => 'israeli',
        'material_product_group_id' => $this->group->id, 'material_quantity' => 5]));
    expect($expense)->toBeInstanceOf(PartnerFinanceTransaction::class)
        ->and($expense->recipient)->toBe('Bond material')->and($expense->currency)->toBe('GEL')
        ->and($expense->material_quantity)->toBe('5.000000')->and(FinanceTransaction::count())->toBe(0)
        ->and(PartnerFinanceTransaction::count())->toBe(1)->and(CashboxTransaction::count())->toBe(0)
        ->and((float) $this->report->groups()->sole()->purchase_amount)->toBe(125.50);
    $expense->update(['amount' => 150, 'recipient' => 'Edited partner material', 'material_quantity' => 6]);
    expect((float) $this->report->groups()->sole()->purchase_amount)->toBe(150.0);
    $expense->delete();
    expect($this->report->groups()->count())->toBe(0);
});

test('classified expenses require a real group description and positive six decimal quantity before posting', function (array $invalid) {
    expect(fn () => $this->manager->create(manualMaterialData(array_replace([
        'material_product_group_id' => $this->group->id, 'material_quantity' => 1,
    ], $invalid))))->toThrow(ValidationException::class);
    expect(FinanceTransaction::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
})->with([
    [['material_quantity' => null]], [['material_quantity' => 0]], [['material_quantity' => -1]],
    [['material_quantity' => '1.1234567']], [['description' => '']], [['material_product_group_id' => 999999]],
]);

test('Israeli material operations retain their existing exchange and one expense and roll back invalid metadata', function () {
    $this->partialMock(FinanceUsdUsageService::class)->shouldReceive('cashBalances')->andReturn(['GEL' => 1000, 'USD' => 1000]);
    $data = ['operation_type' => 'materials', 'payment_mode' => 'exchange_usd_gel', 'actual_amount' => 125.50,
        'usd_amount' => 100, 'exchange_rate' => 3, 'transacted_at' => now(), 'recipient' => 'Partner material',
        'material_product_group_id' => $this->group->id, 'material_quantity' => 5];
    expect(fn () => app(FinanceUsdUsageService::class)->recordIsraeliOperation(array_replace($data, ['material_quantity' => 0])))
        ->toThrow(ValidationException::class);
    expect(PartnerFinanceTransaction::count())->toBe(0);
    $result = app(FinanceUsdUsageService::class)->recordIsraeliOperation($data);
    expect(PartnerFinanceTransaction::where('type', 'expense')->count())->toBe(1)
        ->and(PartnerFinanceTransaction::where('type', 'currency_exchange')->count())->toBe(1)
        ->and($result['movement']->material_quantity)->toBe('5.000000')
        ->and($result['movement']->recipient)->toBe('Partner material')
        ->and((float) $this->report->groups()->sole()->purchase_amount)->toBe(125.50)
        ->and(FinanceTransaction::count())->toBe(0)->and(Purchase::count())->toBe(0);
});

test('analysis never modifies source financial records and does not sum incompatible units', function () {
    foreach (['pcs', 'kg'] as $unit) {
        $this->manager->create(manualMaterialData(['material_product_group_id' => $this->group->id,
            'material_quantity' => '0.123456', 'material_unit' => $unit]));
    }
    $before = FinanceTransaction::orderBy('id')->get()->map->getAttributes()->all();
    $group = $this->report->groups()->sole();
    expect((int) $group->unit_count)->toBe(2)->and((float) $group->purchase_amount)->toBe(251.0);
    $this->report->products([], (string) $this->group->id)->get();
    $this->report->history([], (string) $this->group->id, 'manual:Bond material')->get();
    expect(FinanceTransaction::orderBy('id')->get()->map->getAttributes()->all())->toBe($before)
        ->and(CashboxTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(0);
});

test('Israeli form uses its existing material name field and requires no new catalog product', function () {
    $this->partialMock(FinanceUsdUsageService::class)->shouldReceive('cashBalances')->andReturn(['GEL' => 1000, 'USD' => 1000]);
    $type = app(ExpenseDimensions::class)->childForType($this->direction, app(ExpenseDimensions::class)->id('type', 'materials'));
    Livewire::test(ListPartnerFinance::class)->callAction('useIsraeliFunds', [
        'operation_type' => 'materials', 'payment_mode' => 'direct_gel', 'actual_amount' => 100,
        'transacted_at' => now(), 'other_recipient' => 'Israeli manual material', 'notes' => 'Original note',
        'expense_direction_id' => $this->direction, 'expense_type_id' => $type,
        'material_product_group_id' => $this->group->id, 'material_quantity' => '1.234567', 'material_unit' => 'pcs',
    ])->assertHasNoActionErrors();
    $expense = PartnerFinanceTransaction::sole();
    expect($expense->recipient)->toBe('Israeli manual material')->and($expense->notes)->toBe('Original note')
        ->and($expense->material_quantity)->toBe('1.234567')->and(FinanceTransaction::count())->toBe(0)
        ->and((float) $this->report->groups()->sole()->purchase_amount)->toBe(100.0);
});

test('RS and manual rows aggregate together without including the RS payment or changing RS identity', function () {
    $path = tempnam(sys_get_temp_dir(), 'manual-material-rs');
    rename($path, $path .= '.csv');
    $file = fopen($path, 'w');
    fputcsv($file, ['Date', 'Supplier', 'Product', 'RS Product Code', 'Quantity', 'Unit Price', 'Total', 'Document', 'Unit'], escape: '');
    fputcsv($file, ['2026-11-12', 'Synthetic supplier', 'RS bond', 'BOND1', 3, 100, 300, 'MANUAL-RS-COMBINATION', 'pcs'], escape: '');
    fclose($file);
    try {
        $importer = app(PurchaseImportService::class);
        expect($importer->import($path)['errors'])->toBeEmpty();
        $rs = Purchase::where('document_number', 'MANUAL-RS-COMBINATION')->sole();
        $item = $rs->items()->sole();
        app(PurchaseCatalog::class)->assignDirection($item->purchaseProduct, $this->direction);
        app(PurchaseCatalog::class)->assignGroup($item->purchaseProduct, $this->group->id);
        FinanceOpeningBalance::create(['source' => 'cash', 'account_identifier' => 'cash', 'currency' => 'GEL', 'effective_date' => today(), 'amount' => 5000]);
        app(PurchaseCashPayment::class)->post($rs->id, auth()->user());
        $before = [$rs->fresh()->getAttributes(), $item->fresh()->getAttributes()];
        $expense = $this->manager->create(manualMaterialData(['expense_direction_id' => $this->direction,
            'material_product_group_id' => $this->group->id, 'material_quantity' => 2]));
        $group = $this->report->groups()->sole();
        expect((float) $group->purchased_quantity)->toBe(5.0)->and((float) $group->purchase_amount)->toBe(425.50)
            ->and((int) $group->purchase_count)->toBe(2)->and(FinanceTransaction::count())->toBe(2)
            ->and(Purchase::count())->toBe(1)->and(PurchaseItem::count())->toBe(1);
        $manual = $this->report->history([], (string) $this->group->id, 'manual:Bond material')->sole();
        expect($manual->origin)->toBe('manual')->and($manual->purchase_id)->toBeNull()->and($manual->item_name)->toBe('Bond material');
        $history = $this->report->history([], (string) $this->group->id, (string) $item->purchase_product_id)->sole();
        expect($history->origin)->toBe('rs')->and($history->document_number)->toBe('MANUAL-RS-COMBINATION');
        $importer->import($path);
        expect(Purchase::count())->toBe(1)->and(PurchaseItem::count())->toBe(1)
            ->and((float) $this->report->groups()->sole()->purchase_amount)->toBe(425.50)
            ->and([$rs->fresh()->getAttributes(), $item->fresh()->getAttributes()])->toBe($before);
        $page = Livewire::test(AnalysisPage::class)->call('openDirection', (string) $this->direction)->assertOk()
            ->call('openGroup', (string) $this->group->id)->assertOk();
        $manualRow = $page->instance()->getTableRecords()->firstWhere('product_id', 'manual:Bond material');
        expect($manualRow)->not->toBeNull();
        $page->callTableAction('drillDown', $manualRow)->assertSet('selectedProduct', 'manual:Bond material')->assertOk()
            ->assertSee('Bond material')->assertSee(__('expense-entry.manual'))->assertDontSee('MANUAL-RS-COMBINATION');
        $page->assertTableActionHidden('editClassification', $page->instance()->getTableRecords()->sole());
    } finally {
        unlink($path);
    }
});

test('analysis respects inclusive expense dates group direction payment and currency filters', function () {
    foreach ([['2026-11-11 23:59:59', 'GEL', 10], ['2026-11-12 00:00:00', 'GEL', 20],
        ['2026-11-12 23:59:59', 'USD', 30], ['2026-11-13 00:00:00', 'GEL', 40]] as [$date, $currency, $amount]) {
        $this->manager->create(manualMaterialData(['transaction_date' => $date, 'currency' => $currency, 'amount' => $amount,
            'expense_direction_id' => $this->direction, 'material_product_group_id' => $this->group->id, 'material_quantity' => 1]));
    }
    $filters = ['from' => '2026-11-12', 'until' => '2026-11-12', 'group' => $this->group->id, 'direction' => $this->direction];
    $groups = $this->report->groups($filters)->get()->keyBy('currency');
    expect($groups)->toHaveCount(2)->and((float) $groups['GEL']->purchase_amount)->toBe(20.0)
        ->and((float) $groups['USD']->purchase_amount)->toBe(30.0)
        ->and($this->report->groups($filters + ['currency' => 'USD'])->get()->count())->toBe(1)
        ->and($this->report->groups($filters + ['payment' => 'cash'])->get()->count())->toBe(0)
        ->and($this->report->groups($filters + ['payment' => 'bank'])->get()->count())->toBe(2)
        ->and($this->report->groups($filters + ['supplier' => 999])->count())->toBe(0)
        ->and($this->report->groups(array_replace($filters, ['group' => 999]))->count())->toBe(0);
});

test('editing declassifying and deleting expenses updates analysis without duplicate movements', function () {
    $expense = $this->manager->create(manualMaterialData(['payment_method' => 'cash', 'cash_source' => 'current_cashier',
        'material_product_group_id' => $this->group->id, 'material_quantity' => 2]));
    $movement = $expense->cashboxTransaction->id;
    $other = PurchaseProductGroup::create(['name' => 'Other material group', 'expense_direction_id' => $this->direction]);
    $this->manager->update($expense, ['description' => 'Renamed material', 'amount' => 200,
        'material_product_group_id' => $other->id, 'material_quantity' => 4]);
    $group = $this->report->groups()->sole();
    expect((int) $group->group_id)->toBe($other->id)->and((float) $group->purchase_amount)->toBe(200.0)
        ->and((float) $group->purchased_quantity)->toBe(4.0)->and(FinanceTransaction::count())->toBe(1)
        ->and(CashboxTransaction::sole()->id)->toBe($movement)->and((float) CashboxTransaction::sole()->amount)->toBe(200.0);
    $this->manager->update($expense, ['material_product_group_id' => null]);
    expect($this->report->groups()->count())->toBe(0)->and($expense->fresh()->material_quantity)->toBeNull();
    $this->manager->update($expense, ['material_product_group_id' => $this->group->id, 'description' => 'Restored material', 'material_quantity' => 1]);
    expect($this->report->groups()->count())->toBe(1);
    $this->manager->delete($expense);
    expect($this->report->groups()->count())->toBe(0)->and(FinanceTransaction::count())->toBe(0)
        ->and(CashboxTransaction::count())->toBe(0)->and(Purchase::count())->toBe(0);
});

test('a failed existing cashbox posting rolls back classified expense metadata with the expense', function () {
    $day = app(\App\Support\CashboxManager::class)->today();
    $day->update(['status' => 'closed']);
    expect(fn () => $this->manager->create(manualMaterialData(['payment_method' => 'cash', 'cash_source' => 'current_cashier',
        'material_product_group_id' => $this->group->id, 'material_quantity' => 1])))->toThrow(ValidationException::class);
    expect(FinanceTransaction::count())->toBe(0)->and($this->report->groups()->count())->toBe(0);
});

test('the additive migration preserves existing financial values and leaves old quantities unknown', function () {
    $expense = $this->manager->create(manualMaterialData());
    $migration = require database_path('migrations/2026_11_12_120000_add_manual_material_quantity_to_expenses.php');
    $migration->down(); // Isolated in-memory test schema only; represents the pre-release schema.
    $before = (array) DB::table('finance_transactions')->where('id', $expense->id)->first();
    $migration->up();
    $after = (array) DB::table('finance_transactions')->where('id', $expense->id)->first();
    expect($after['material_quantity'])->toBeNull()->and($after['material_unit'])->toBeNull();
    unset($after['material_quantity'], $after['material_unit']);
    expect($after)->toBe($before)->and(Purchase::count())->toBe(0)->and(BankTransaction::count())->toBe(0);
});

test('the existing six decimal convention accepts the smallest positive quantity without rounding', function () {
    $expense = $this->manager->create(manualMaterialData(['material_product_group_id' => $this->group->id, 'material_quantity' => 0.000001]));
    expect($expense->material_quantity)->toBe('0.000001');
    expect(fn () => $expense->update(['material_quantity' => '1.1234567']))->toThrow(ValidationException::class);
    expect($expense->fresh()->material_quantity)->toBe('0.000001');
});

test('partial classification edits retain the existing description quantity unit and accounting fields', function () {
    $expense = $this->manager->create(manualMaterialData(['material_product_group_id' => $this->group->id,
        'material_quantity' => '1.234567', 'material_unit' => 'kg']));
    $other = PurchaseProductGroup::create(['name' => 'Edited group', 'expense_direction_id' => $this->direction]);
    $this->manager->update($expense, ['material_product_group_id' => $other->id]);
    expect($expense->fresh()->description)->toBe('Bond material')->and($expense->material_quantity)->toBe('1.234567')
        ->and($expense->material_unit)->toBe('kg')->and($expense->purchase_product_group_id)->toBe($other->id)
        ->and((float) $expense->amount)->toBe(125.50)->and($expense->currency)->toBe('GEL')
        ->and(FinanceTransaction::count())->toBe(1)->and(BankTransaction::count())->toBe(0);
});

test('analysis keeps overlapping RS and manual identifiers separate across all five queries', function (bool $manual, bool $rs) {
    $supplier = \App\Models\Supplier::create(['name' => 'Synthetic identifier supplier']);
    $product = PurchaseProduct::create(['supplier_id' => $supplier->id, 'name' => '1', 'normalized_name' => '1',
        'identity_key' => hash('sha256', 'synthetic-numeric-material'),
        'purchase_product_group_id' => $this->group->id, 'expense_direction_id' => $this->direction]);
    $document = $line = null;
    if ($rs) {
        $document = Purchase::create(['source' => 'rs', 'supplier_id' => $supplier->id,
            'document_number' => 'SYNTHETIC-ID-COLLISION', 'purchase_date' => today()]);
        $line = $document->items()->create(['purchase_product_id' => $product->id, 'item_name' => 'Original RS material',
            'quantity' => 3, 'unit' => 'pcs', 'unit_price' => 100, 'line_total' => 300]);
    }
    if ($manual) {
        $expense = $this->manager->create(manualMaterialData(['description' => (string) $product->id,
            'expense_direction_id' => $this->direction, 'material_product_group_id' => $this->group->id, 'material_quantity' => 2]));
        $this->partialMock(FinanceUsdUsageService::class)->shouldReceive('cashBalances')->andReturn(['GEL' => 1000, 'USD' => 1000]);
        $partner = $this->manager->create(manualMaterialData(['description' => (string) $product->id, 'amount' => 50,
            'expense_direction_id' => $this->direction, 'material_product_group_id' => $this->group->id, 'material_quantity' => 3,
            'payment_method' => 'cash', 'cash_source' => 'israeli']));
        expect($expense->id)->toBe($partner->id);
        if ($rs) { expect($expense->id)->toBe($document->id)->and($expense->id)->toBe($line->id); }
    }
    $stored = fn () => collect(['finance_transactions', 'partner_finance_transactions', 'purchases', 'purchase_items'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all();
    $before = $stored();
    $amount = (float) (($manual ? 175.50 : 0) + ($rs ? 300 : 0));
    $quantity = ($manual ? 5 : 0) + ($rs ? 3 : 0);
    $events = ($manual ? 2 : 0) + ($rs ? 1 : 0);
    $filters = ['from' => today()->toDateString(), 'until' => today()->toDateString(),
        'group' => $this->group->id, 'direction' => $this->direction];
    foreach ([$this->report->directions($filters)->sole(), $this->report->groups($filters)->sole()] as $row) {
        expect((float) $row->purchase_amount)->toBe($amount)->and((float) $row->purchased_quantity)->toBe((float) $quantity)
            ->and((int) $row->purchase_count)->toBe($events);
    }
    $products = $this->report->products($filters, (string) $this->group->id)->get();
    expect($products)->toHaveCount((int) $manual + (int) $rs);
    $manualKey = 'manual:'.$product->id;
    $manualHistory = $this->report->history($filters, (string) $this->group->id, $manualKey)->get();
    $rsHistory = $this->report->history($filters, (string) $this->group->id, (string) $product->id)->get();
    expect($manualHistory)->toHaveCount($manual ? 2 : 0)->and($rsHistory)->toHaveCount($rs ? 1 : 0)
        ->and($manualHistory->concat($rsHistory)->pluck('id')->unique())->toHaveCount($events)
        ->and($this->report->groups(array_replace($filters, ['group' => 999999]))->count())->toBe(0);
    if ($manual) {
        expect((float) $products->firstWhere('product_id', $manualKey)->purchase_amount)->toBe(175.50)
            ->and($manualHistory->pluck('item_name')->unique()->all())->toBe([(string) $product->id])
            ->and($manualHistory->pluck('purchase_id')->all())->toBe([null, null])
            ->and($manualHistory->pluck('origin')->unique()->all())->toBe(['manual']);
    }
    if ($rs) {
        expect((float) $products->firstWhere('product_id', $product->id)->purchase_amount)->toBe(300.0)
            ->and($rsHistory->sole()->item_name)->toBe('Original RS material')->and($rsHistory->sole()->origin)->toBe('rs');
    }
    $page = Livewire::test(AnalysisPage::class)->call('openDirection', (string) $this->direction)
        ->call('openGroup', (string) $this->group->id)->assertCountTableRecords((int) $manual + (int) $rs);
    $page->call('openProduct', $manual ? $manualKey : (string) $product->id)->assertCountTableRecords($manual ? 2 : 1);
    expect($stored())->toEqual($before)->and((float) FinanceTransaction::sum('amount'))->toBe($manual ? 125.50 : 0.0)
        ->and((float) PartnerFinanceTransaction::sum('amount'))->toBe($manual ? 50.0 : 0.0)
        ->and(CashboxTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(0);
})->with(['manual only' => [true, false], 'RS only' => [false, true], 'combined' => [true, true]]);
