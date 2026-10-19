<?php

use App\Filament\Resources\Purchases\Pages\PurchaseAnalysis as AnalysisPage;
use App\Models\FinanceTransaction;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\PurchaseAnalysis;
use App\Services\PurchaseAnalysisDiagnostics;
use App\Services\PurchaseAnalysisEligibility;
use App\Services\PurchaseAnalysisMapping;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.timezone' => 'Asia/Tbilisi']);
    $this->travelTo('2026-10-19 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    app(PurchaseImportService::class)->import(base_path('tests/fixtures/rs-items-georgian.csv'));
    $this->report = app(PurchaseAnalysis::class);
    $this->catalog = app(PurchaseCatalog::class);
    $this->therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    $this->product = PurchaseProduct::where('rs_product_code', '001')->sole();
    $this->group = $this->catalog->createGroup(['name' => 'Existing classified group', 'expense_direction_id' => $this->therapy]);
    $this->catalog->assignCatalogDirection($this->product, $this->therapy);
    $this->catalog->assignCatalogGroup($this->product, $this->group->id);
    $this->retail = Product::create(['name' => 'Renamed legacy retail identity', 'selling_price' => 0]);
    $this->anchor = $this->product->items()->firstOrFail();
    $this->anchor->update(['product_id' => $this->retail->id]);
    $this->legacy = Purchase::create(['source' => 'rs', 'supplier_id' => $this->product->supplier_id,
        'purchase_date' => '2026-10-05', 'document_number' => 'LEGACY-CLASSIFIED', 'import_batch_id' => $this->anchor->purchase->import_batch_id]);
    $this->legacyLine = $this->legacy->items()->create(['product_id' => $this->retail->id,
        'item_name' => 'Original older RS description', 'quantity' => 6, 'unit' => 'pcs', 'unit_price' => 10, 'line_total' => 60]);
});

test('older imported purchases missing modern raw payload remain eligible and already classified without rewrites', function () {
    $before = $this->legacyLine->fresh()->getAttributes();
    expect($this->legacy->rs_import_payload)->toBeNull()->and($this->legacy->import_batch_id)->not->toBeNull()
        ->and(app(PurchaseAnalysisEligibility::class)->documents()->pluck('eligible.id')->all())->toContain($this->legacy->id);
    $row = $this->report->products(['from' => '2026-09-20', 'until' => '2026-10-19'], (string) $this->group->id, (string) $this->therapy)->sole();
    expect((int) $row->product_id)->toBe($this->product->id)->and((float) $row->purchase_amount)->toBe(60.0)
        ->and((int) $row->purchase_count)->toBe(1)->and((float) $row->average_interval)->toBe(34.0)
        ->and($this->legacyLine->fresh()->getAttributes())->toBe($before);
    $history = $this->report->history([], (string) $this->group->id, (string) $this->product->id, (string) $this->therapy)->get();
    expect($history)->toHaveCount(2)->and($history->pluck('item_name')->all())->toContain('Original older RS description');
});

test('one month uses inclusive canonical document dates not creation import or confirmation timestamps', function () {
    foreach (['2026-09-19', '2026-09-20', '2026-10-19', '2026-10-20'] as $date) {
        $document = Purchase::create(['source' => 'rs', 'supplier_id' => $this->product->supplier_id,
            'purchase_date' => $date, 'document_number' => 'BOUNDARY-'.$date, 'import_batch_id' => $this->anchor->purchase->import_batch_id]);
        $document->items()->create(['product_id' => $this->retail->id, 'quantity' => 1, 'unit' => 'pcs', 'unit_price' => 1]);
    }
    $page = Livewire::test(AnalysisPage::class)->set('filters.period', '1_month');
    expect(Carbon::parse($page->get('filters.from'))->toDateString())->toBe('2026-09-20')
        ->and(Carbon::parse($page->get('filters.until'))->toDateString())->toBe('2026-10-19')
        ->and((float) $page->instance()->getTableRecords()->sum('purchase_amount'))->toBe(62.0);
    $page->call('openDirection', (string) $this->therapy)->call('openGroup', (string) $this->group->id)->assertSee($this->product->name)
        ->call('openProduct', (string) $this->product->id)->assertSee('LEGACY-CLASSIFIED')->assertDontSee('RS-TEST-1');
});

test('new direct mappings and existing group Direction defaults remain usable', function () {
    $this->product->update(['expense_direction_id' => null]);
    $row = $this->report->groups([], (string) $this->therapy)->sole();
    expect((int) $row->group_id)->toBe($this->group->id)->and((float) $row->purchase_amount)->toBe(360.0);
    $this->group->update(['expense_direction_id' => null]);
    $other = PurchaseProduct::where('rs_product_code', '002')->sole();
    $other->update(['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->group->id]);
    expect((float) $this->report->groups([], (string) $this->therapy)->sole()->purchase_amount)->toBe(396.0);
});

test('retained exact RS codes resolve missing links and existing catalog aliases without name matching', function () {
    $other = PurchaseProduct::where('rs_product_code', '002')->sole();
    $other->update(['expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->group->id]);
    $line = $other->items()->firstOrFail();
    $line->update(['purchase_product_id' => null, 'item_name' => 'Different historical spelling']);
    expect($this->report->history([], (string) $this->group->id, (string) $other->id)->count())->toBe(1);
    $manual = $this->catalog->createManual(['name' => 'Explicit alias identity', 'expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->group->id]);
    $other->update(['rs_product_code' => null]);
    DB::table('purchase_product_aliases')->insert(['supplier_id' => $other->supplier_id,
        'identity_key' => hash('sha256', $other->supplier_id.'|rs|002'), 'purchase_product_id' => $manual->id]);
    $row = $this->report->history([], (string) $this->group->id, (string) $manual->id)->sole();
    expect((int) $row->resolved_product_id)->toBe($manual->id)->and($line->fresh()->purchase_product_id)->toBeNull();
});

test('legacy taxonomy codes preserve Direction-only spending and unresolved products have no frequency or drilldown', function () {
    $legacyOnly = Product::create(['name' => 'Legacy direction-only product', 'product_category_id' => ProductCategory::where('slug', 'therapy')->value('id'), 'selling_price' => 0]);
    $line = $this->legacy->items()->create(['product_id' => $legacyOnly->id, 'quantity' => 2, 'unit' => 'pcs', 'unit_price' => 10]);
    expect((float) $this->report->groups(['from' => '2026-10-01'], (string) $this->therapy)->get()->sum('purchase_amount'))->toBe(80.0);
    $row = $this->report->products(['from' => '2026-10-01'], 'ungrouped', (string) $this->therapy)->sole();
    expect($row->product_id)->toBeNull()->and($row->average_interval)->toBeNull()->and($row->last_purchase)->toBeNull()
        ->and((float) $row->purchase_amount)->toBe(20.0)->and($line->fresh()->purchase_product_id)->toBeNull();
    Livewire::test(AnalysisPage::class)->call('openDirection', (string) $this->therapy)->call('openGroup', 'ungrouped')->assertTableActionHidden('drillDown', $row);
});

test('ambiguous legacy links remain explicitly unresolved without removing spending from top-level totals', function () {
    $other = PurchaseProduct::where('rs_product_code', '002')->sole();
    $other->items()->firstOrFail()->update(['product_id' => $this->retail->id]);
    $row = $this->report->products(['from' => '2026-10-01'], 'ungrouped', 'uncategorized')->sole();
    expect($row->product_id)->toBeNull()->and((float) $row->purchase_amount)->toBe(60.0)
        ->and((float) $this->report->directions(['from' => '2026-10-01'])->get()->sum('purchase_amount'))->toBe(60.0);
});

test('known cancellations and explicit validation errors remain excluded but unconfirmed evidence is not cancellation', function () {
    $this->legacy->forceFill(['rs_import_payload' => ['validated' => false, 'source_rows' => []]])->saveQuietly();
    expect($this->report->lines(['from' => '2026-10-01'])->count())->toBe(1);
    $this->legacy->forceFill(['rs_import_payload' => ['validated' => true, 'validation_errors' => ['Invalid source'], 'source_rows' => []]])->saveQuietly();
    expect($this->report->lines(['from' => '2026-10-01'])->count())->toBe(0);
    foreach (['გაუქმებული', 'cancelled', 'deleted', 'invalid'] as $status) {
        $this->legacy->forceFill(['rs_import_payload' => ['source_rows' => ['row' => ['raw' => ['headers' => ['სტატუსი'], 'cells' => [$status]]]]]])->saveQuietly();
        expect($this->report->lines(['from' => '2026-10-01'])->count())->toBe(0);
    }
});

test('analysis and diagnostics preserve all documents classifications and financial data', function () {
    FinanceTransaction::create(['type' => 'expense', 'category' => 'materials', 'amount' => 15, 'currency' => 'GEL',
        'payment_method' => 'cash', 'cash_source' => 'withdrawn_cash', 'transaction_date' => today()]);
    $tables = ['purchases', 'purchase_items', 'purchase_products', 'purchase_product_groups', 'purchase_product_aliases', 'products',
        'finance_transactions', 'cashbox_transactions', 'bank_transactions', 'partner_finance_transactions', 'payments'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toJson();
    $counts = app(PurchaseAnalysisDiagnostics::class)->snapshot('2026-09-20', '2026-10-19');
    expect($counts['included_documents'])->toBe(1)->and($counts['excluded_by_status_or_validation'])->toBe(0)
        ->and($counts['mapping']['resolved_legacy_lines'])->toBe(1)->and($counts['mapping']['unclassified_direction_lines'])->toBe(0)
        ->and($counts['date_field'])->toBe('purchases.purchase_date')->and($counts['timezone'])->toBe('Asia/Tbilisi')
        ->and($snapshot())->toBe($before)->and(app(AccountingLedger::class)->pnlTotals(null, null)->toJson())->toBe($pnl);
});

test('empty results use Georgian purchasing language rather than internal model terminology', function () {
    Livewire::test(AnalysisPage::class)->set('filters.from', '2030-01-01')->assertSee('შესყიდვები ვერ მოიძებნა')
        ->assertSee('არჩეული პერიოდისა და ფილტრების მიხედვით შესყიდვები ვერ მოიძებნა.')
        ->assertDontSee('არა purchase item');
});

test('older optional schemas do not require alias tables or a new Product Group direction column', function () {
    $this->partialMock(PurchaseAnalysisMapping::class, fn ($mock) => $mock->shouldReceive('capabilities')
        ->andReturn(['aliases' => false, 'group_direction' => false]));
    $query = $this->report->products(['from' => '2026-10-01'], (string) $this->group->id, (string) $this->therapy);
    expect($query->toSql())->not->toContain('purchase_product_aliases', 'product_group.expense_direction_id')
        ->and((float) $query->sole()->purchase_amount)->toBe(60.0);
});
