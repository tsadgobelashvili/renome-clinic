<?php

use App\Filament\Resources\Purchases\Pages\PurchaseAnalysis as AnalysisPage;
use App\Filament\Resources\Purchases\Pages\PurchaseGroupProducts;
use App\Filament\Resources\Purchases\Pages\PurchaseProductGroups;
use App\Filament\Resources\Purchases\Pages\UncategorizedProducts;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceTransaction;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use App\Models\User;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\FinanceUsdUsageService;
use App\Services\PurchaseAnalysis;
use App\Services\PurchaseCashPayment;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
});

function analysisImport(string $document = 'RS-ANALYSIS', string $date = '2026-09-19', array $items = []): Purchase
{
    $items = $items ?: [['Implant A', 'I1', 5, 100, 500], ['Filtek', 'F1', 3, 100, 300], ['Gloves', 'G1', 20, 10, 200]];
    $path = tempnam(sys_get_temp_dir(), 'rs-analysis');
    rename($path, $path .= '.csv');
    $file = fopen($path, 'w');
    fputcsv($file, ['Date', 'Supplier', 'Product', 'RS Product Code', 'Quantity', 'Unit Price', 'Total', 'Document', 'Unit'], escape: '');
    foreach ($items as [$name, $code, $qty, $price, $total]) {
        fputcsv($file, [$date, 'RS supplier', $name, $code, $qty, $price, $total, $document, 'pcs'], escape: '');
    }
    fclose($file);
    try {
        expect(app(PurchaseImportService::class)->import($path)['errors'])->toBeEmpty();
    } finally {
        unlink($path);
    }

    return Purchase::where('document_number', $document)->sole();
}

function mapAnalysisProducts(): array
{
    $groups = [];
    foreach (['I1' => ['იმპლანტები', 'surgery'], 'F1' => ['ბჟენები', 'therapy'], 'G1' => ['ხელთათმანები', 'general']] as $code => [$name, $direction]) {
        $product = PurchaseProduct::where('rs_product_code', $code)->first();
        if (! $product) {
            continue;
        }
        $group = PurchaseProductGroup::firstOrCreate(['name' => $name]);
        $catalog = app(PurchaseCatalog::class);
        $catalog->assignGroup($product, $group->id);
        $catalog->assignDirection($product, app(ExpenseDimensions::class)->id('direction', $direction));
        $groups[$code] = $group;
    }

    return $groups;
}

test('uncategorized mapping saves both dimensions and is reused by later imports', function () {
    analysisImport();
    $product = PurchaseProduct::where('rs_product_code', 'I1')->sole();
    $group = PurchaseProductGroup::create(['name' => 'იმპლანტები']);
    $page = Livewire::test(UncategorizedProducts::class)->assertSuccessful()->assertCanSeeTableRecords([$product]);
    $direction = app(ExpenseDimensions::class)->id('direction', 'surgery');
    $page->call('updateTableColumnState', 'direction', (string) $product->id, (string) $direction)
        ->assertCanSeeTableRecords([$product]);
    $page->call('updateTableColumnState', 'product_group', (string) $product->id, (string) $group->id)
        ->assertCanNotSeeTableRecords([$product]);
    expect($product->fresh()->expense_direction_id)->toBe($direction)
        ->and($product->fresh()->purchase_product_group_id)->toBe($group->id);
    analysisImport('NEXT');
    expect(PurchaseProduct::count())->toBe(3)
        ->and(Purchase::where('document_number', 'NEXT')->sole()->items()->where('purchase_product_id', $product->id)->count())->toBe(1);
    Livewire::test(UncategorizedProducts::class)->assertCanNotSeeTableRecords([$product]);
});

test('groups manage names show products and allow reassignment without changing payment data', function () {
    analysisImport();
    $page = Livewire::test(PurchaseProductGroups::class)->callTableAction('create', data: ['name' => 'ახალი ჯგუფი'])->assertHasNoTableActionErrors();
    $group = PurchaseProductGroup::where('name', 'ახალი ჯგუფი')->sole();
    $page->callTableAction('edit', $group, data: ['name' => 'მასალები'])->assertHasNoTableActionErrors();
    $product = PurchaseProduct::first();
    app(PurchaseCatalog::class)->assignGroup($product, $group->id);
    $other = PurchaseProductGroup::create(['name' => 'სხვა']);
    Livewire::test(PurchaseGroupProducts::class, ['group' => $group->id])->assertCanSeeTableRecords([$product])
        ->call('updateTableColumnState', 'product_group', (string) $product->id, (string) $other->id)
        ->assertCanNotSeeTableRecords([$product]);
    Livewire::test(PurchaseGroupProducts::class, ['group' => $other->id])->assertCanSeeTableRecords([$product]);
    $page->callTableAction('delete', $group)->assertHasNoTableActionErrors();
    expect($group->fresh())->toBeNull()->and(FinanceTransaction::count())->toBe(0);
});

test('SQL group totals drilldowns history and filtered weighted prices use invoice lines', function () {
    $document = analysisImport();
    $groups = mapAnalysisProducts();
    analysisImport('LATER', '2026-09-20', [['Implant A', 'I1', 2, 120, 240]]);
    $report = app(PurchaseAnalysis::class);
    $totals = $report->groups()->get();
    expect((float) $totals->sum('purchase_amount'))->toBe(1240.0)->and((float) $totals->sum('purchased_quantity'))->toBe(30.0);
    $products = $report->products([], (string) $groups['I1']->id)->get();
    expect($products)->toHaveCount(1)->and((float) $products->sole()->purchase_amount)->toBe(740.0)
        ->and((float) $products->sole()->average_price)->toEqualWithDelta(740 / 7, 0.00001)
        ->and((float) $products->sole()->latest_price)->toBe(120.0);
    $product = PurchaseProduct::where('rs_product_code', 'I1')->sole();
    $history = $report->history([], (string) $groups['I1']->id, (string) $product->id)->get();
    expect($history)->toHaveCount(2)->and($history->pluck('document_number')->all())->toContain('RS-ANALYSIS', 'LATER');
    $filters = ['from' => '2026-09-19', 'until' => '2026-09-19', 'supplier' => $document->supplier_id,
        'direction' => $product->expense_direction_id, 'group' => $groups['I1']->id, 'product' => $product->id, 'payment' => 'unlinked'];
    expect((float) $report->groups($filters)->get()->sum('purchase_amount'))->toBe(500.0);
    $page = Livewire::test(AnalysisPage::class)->assertSuccessful()->assertSee('იმპლანტები')
        ->call('openGroup', (string) $groups['I1']->id)->assertSee('Implant A')
        ->call('openProduct', (string) $product->id)->assertSee('LATER')->assertSee('RS-ANALYSIS')
        ->call('back')->assertSet('selectedProduct', null)->call('back')->assertSet('selectedGroup', null);
    $page->set('filters.direction', $product->expense_direction_id)->assertSet('selectedGroup', null);
    $groupRow = $page->instance()->getTableRecords()->first();
    $page->callTableAction('drillDown', $groupRow)->assertSet('selectedGroup', (string) $groups['I1']->id);
    $productRow = $page->instance()->getTableRecords()->first();
    $page->callTableAction('drillDown', $productRow)->assertSet('selectedProduct', (string) $product->id)->assertSee('LATER');
    $page->set('filters.until', '2026-09-19 00:00:00')->set('filters.from', '2026-09-19 00:00:00')->assertSet('selectedGroup', null);
    expect((float) $page->instance()->getTableRecords()->sum('purchase_amount'))->toBe(500.0);
});

test('bank linkage filters invoices without multiplying purchases or creating another expense', function () {
    $purchase = analysisImport();
    mapAnalysisProducts();
    $bank = BankTransaction::create(['transaction_date' => today(), 'direction' => 'outflow', 'amount' => 1000, 'currency' => 'GEL',
        'operation_type' => 'PMD', 'source' => 'api', 'bank_category_id' => BankCategory::where('code', 'uncategorized')->value('id'),
        'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64)]);
    app(BankPurchaseMatching::class)->confirm($bank->id, [['purchase_id' => $purchase->id, 'amount' => 1000]], auth()->user());
    $before = DB::table('bank_transactions')->get()->toJson();
    $report = app(PurchaseAnalysis::class);
    expect((float) $report->groups(['payment' => 'bank'])->get()->sum('purchase_amount'))->toBe(1000.0)
        ->and($report->groups(['payment' => 'unlinked'])->get())->toHaveCount(0);
    Livewire::test(AnalysisPage::class)->set('filters.payment', 'bank')->assertSuccessful();
    expect(DB::table('bank_transactions')->get()->toJson())->toBe($before)->and(FinanceTransaction::count())->toBe(0)
        ->and((float) app(AccountingLedger::class)->pnlTotals(null, null, 'bank')->sole()->expenses)->toBe(1000.0);
});

test('cash linked invoice keeps one deduction while mapping and purchase analysis remain metadata only', function () {
    FinanceOpeningBalance::create(['source' => 'cash', 'account_identifier' => 'cash', 'currency' => 'GEL', 'effective_date' => today(), 'amount' => 5000]);
    $purchase = analysisImport();
    $before = app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'];
    app(PurchaseCashPayment::class)->post($purchase->id, auth()->user());
    mapAnalysisProducts();
    app(PurchaseCashPayment::class)->post($purchase->id, auth()->user());
    expect((float) app(PurchaseAnalysis::class)->groups(['payment' => 'cash'])->get()->sum('purchase_amount'))->toBe(1000.0)
        ->and((float) app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe((float) $before - 1000)
        ->and(FinanceTransaction::where('purchase_id', $purchase->id)->count())->toBe(1)
        ->and((float) app(AccountingLedger::class)->dimensionEntries(null, null, 'cash')->sum('amount'))->toBe(1000.0);
});

test('RS navigation keeps documents first and new pages retain owner only backend access', function () {
    $this->get(PurchaseResource::getUrl())->assertOk()->assertSeeInOrder(['დოკუმენტები', 'უკატეგორიო', 'პროდუქციის ჯგუფები', 'შესყიდვების ანალიზი']);
    foreach (['uncategorized', 'groups', 'analysis'] as $page) {
        $this->get(PurchaseResource::getUrl($page))->assertOk();
    }
    foreach ([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN] as $role) {
        $this->actingAs(User::factory()->create(['role' => $role]));
        foreach ([UncategorizedProducts::class, PurchaseProductGroups::class, AnalysisPage::class] as $page) {
            Livewire::test($page)->assertForbidden();
        }
    }
});

test('used product groups cannot be deleted and forged invalid mappings do not change existing classification', function () {
    analysisImport();
    $groups = mapAnalysisProducts();
    $product = PurchaseProduct::where('rs_product_code', 'I1')->sole();
    expect(fn () => $groups['I1']->delete())->toThrow(ValidationException::class);
    expect(fn () => app(PurchaseCatalog::class)->assignGroup($product, 999999))->toThrow(ValidationException::class);
    expect($product->fresh()->purchase_product_group_id)->toBe($groups['I1']->id);
    $component = Livewire::test(PurchaseGroupProducts::class, ['group' => $groups['I1']->id]);
    Livewire::actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $component->call('updateTableColumnState', 'product_group', (string) $product->id, null)->assertForbidden();
    expect($product->fresh()->purchase_product_group_id)->toBe($groups['I1']->id);
});

test('unknown groups remain in analysis and multiple bank links never multiply invoice lines', function () {
    $purchase = analysisImport();
    $report = app(PurchaseAnalysis::class);
    expect($report->groups()->get())->toHaveCount(1)
        ->and((float) $report->groups()->first()->purchase_amount)->toBe(1000.0);
    foreach ([1, 2] as $ignored) {
        $bank = BankTransaction::create(['transaction_date' => today(), 'direction' => 'outflow', 'amount' => 500, 'currency' => 'GEL',
            'operation_type' => 'PMD', 'source' => 'api', 'bank_category_id' => BankCategory::where('code', 'uncategorized')->value('id'),
            'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64)]);
        app(BankPurchaseMatching::class)->confirm($bank->id, [['purchase_id' => $purchase->id, 'amount' => 500]], auth()->user());
    }
    expect((float) $report->groups(['payment' => 'bank'])->first()->purchase_amount)->toBe(1000.0);
    Livewire::test(AnalysisPage::class)->call('openGroup', 'ungrouped')->assertSee('Implant A');
});

test('reversed cash posting becomes unlinked for purchase filtering without changing the invoice', function () {
    FinanceOpeningBalance::create(['source' => 'cash', 'account_identifier' => 'cash', 'currency' => 'GEL', 'effective_date' => today(), 'amount' => 5000]);
    $purchase = analysisImport();
    $service = app(PurchaseCashPayment::class);
    $posting = $service->post($purchase->id, auth()->user());
    $service->reverse($purchase->id, $posting->id, auth()->user());
    $report = app(PurchaseAnalysis::class);
    expect($report->groups(['payment' => 'cash'])->get())->toHaveCount(0)
        ->and((float) $report->groups(['payment' => 'unlinked'])->first()->purchase_amount)->toBe(1000.0)
        ->and($purchase->fresh()->total_amount)->toBe('1000.00');
});

test('purchase analytics is bounded and its query count does not grow with product groups', function () {
    analysisImport();
    mapAnalysisProducts();
    $measure = function (): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $page = Livewire::test(AnalysisPage::class)->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return [$page, count($queries), $queries];
    };
    [$first, $before] = $measure();
    $purchase = Purchase::first();
    foreach (range(1, 30) as $i) {
        $product = app(PurchaseCatalog::class)->resolve($purchase->supplier_id, 'Extra '.$i);
        app(PurchaseCatalog::class)->assignGroup($product, PurchaseProductGroup::create(['name' => 'Group '.$i])->id);
        $purchase->items()->create(['purchase_product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1]);
    }
    [$page, $after, $queries] = $measure();
    expect($after)->toBeLessThanOrEqual($before + 2)
        ->and($page->instance()->getTableRecords()->count())->toBe(25)
        ->and($page->instance()->getTableRecords()->total())->toBe(33);
    // Aggregate initial render never loads the raw line history or per-product latest-price window.
    expect(collect($queries)->pluck('query')->implode(' '))->not->toContain('ROW_NUMBER()', 'select "purchase_items".*');
});
