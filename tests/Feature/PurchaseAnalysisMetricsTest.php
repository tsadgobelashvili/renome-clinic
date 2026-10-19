<?php

use App\Filament\Resources\Purchases\Pages\PurchaseAnalysis as AnalysisPage;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\PurchaseAnalysis;
use App\Services\PurchaseAnalysisMapping;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function metricsImport(array $rows): void
{
    $path = tempnam(sys_get_temp_dir(), 'analysis-metrics');
    rename($path, $path .= '.csv');
    $file = fopen($path, 'w');
    fputcsv($file, ['Date', 'Supplier', 'Product', 'RS Product Code', 'Quantity', 'Unit Price', 'Total', 'Document', 'Unit', 'სტატუსი'], escape: '');
    foreach ($rows as $row) {
        fputcsv($file, $row, escape: '');
    }
    fclose($file);
    try {
        expect(app(PurchaseImportService::class)->import($path)['errors'])->toBeEmpty();
    } finally {
        unlink($path);
    }
}

beforeEach(function () {
    $this->travelTo('2026-10-19 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    metricsImport([
        ['2026-01-01', 'Dental Company', 'Estelite A2', 'SKU1', 2, 10, 20, 'DOC-A', 'pcs', 'დასრულებული'],
        ['2026-01-01', 'Dental Company', 'Original RS variant name', 'SKU1', 3, 10, 30, 'DOC-A', 'pcs', 'დასრულებული'],
        ['2026-01-01', 'Dental Company', 'Bond', 'SKU2', 2, 7, 14, 'DOC-A', 'pcs', 'დასრულებული'],
        ['2026-01-31', 'Dental Company', 'Estelite A2', 'SKU1', 4, 10, 40, 'DOC-B', 'pcs', 'დასრულებული'],
        ['2026-01-31', 'Dental Company', 'Estelite A2', 'SKU1', 1, 10, 10, 'DOC-C', 'pcs', 'აქტიური'],
        ['2026-03-02', 'Dental Company', 'Estelite A2', 'SKU1', 6, 12, 72, 'DOC-D', 'pcs', 'დასრულებული'],
    ]);
    $this->catalog = app(PurchaseCatalog::class);
    $this->therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    $this->group = $this->catalog->createGroup(['name' => 'Filling materials', 'expense_direction_id' => $this->therapy]);
    foreach (PurchaseProduct::all() as $product) {
        $this->catalog->assignCatalogDirection($product, $this->therapy);
        $this->catalog->assignCatalogGroup($product, $this->group->id);
    }
    $this->product = PurchaseProduct::where('rs_product_code', 'SKU1')->sole();
    $this->bond = PurchaseProduct::where('rs_product_code', 'SKU2')->sole();
    $this->report = app(PurchaseAnalysis::class);
});

test('group period totals count distinct documents and have no frequency metric', function () {
    $row = $this->report->groups(['from' => '2026-01-01', 'until' => '2026-01-31'], (string) $this->therapy)->sole();
    expect((int) $row->purchase_count)->toBe(3)->and((float) $row->purchased_quantity)->toBe(12.0)
        ->and((float) $row->purchase_amount)->toBe(114.0)
        ->and(Carbon::parse($row->last_purchase)->toDateString())->toBe('2026-01-31')
        ->and(array_key_exists('average_interval', $row->getAttributes()))->toBeFalse();
});

test('product period totals use document identity while frequency uses full distinct-date history', function () {
    $january = $this->report->products(['from' => '2026-01-01', 'until' => '2026-01-31'], (string) $this->group->id)->get()->firstWhere('product_id', $this->product->id);
    expect((int) $january->purchase_count)->toBe(3)->and((float) $january->purchased_quantity)->toBe(10.0)
        ->and((float) $january->purchase_amount)->toBe(100.0)->and((float) $january->average_interval)->toBe(30.0)
        ->and(Carbon::parse($january->last_purchase)->toDateString())->toBe('2026-03-02');
    $march = $this->report->products(['from' => '2026-03-01', 'until' => '2026-03-31'], (string) $this->group->id)->sole();
    expect((int) $march->purchase_count)->toBe(1)->and((float) $march->purchased_quantity)->toBe(6.0)
        ->and((float) $march->purchase_amount)->toBe(72.0)->and((float) $march->average_interval)->toBe(30.0);
    $page = Livewire::test(AnalysisPage::class)->call('openDirection', (string) $this->therapy)->call('openGroup', (string) $this->group->id);
    $column = $page->instance()->getTable()->getColumn('days_since_last_purchase');
    $column->record($january);
    expect($column->getState())->toBe(231);
});

test('one purchase or same-day events produce no invented average interval and no-history stays empty', function () {
    $row = $this->report->products([], (string) $this->group->id)->get()->firstWhere('product_id', $this->bond->id);
    expect($row->average_interval)->toBeNull();
    metricsImport([['2026-01-01', 'Dental Company', 'Bond', 'SKU2', 1, 7, 7, 'SAME-DAY', 'pcs', 'აქტიური']]);
    $row = $this->report->products([], (string) $this->group->id)->get()->firstWhere('product_id', $this->bond->id);
    expect((int) $row->purchase_count)->toBe(2)->and($row->average_interval)->toBeNull();
    $missing = $this->catalog->createManual(['name' => 'Never purchased', 'expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->group->id]);
    expect($this->report->products(['product' => $missing->id], (string) $this->group->id)->get())->toBeEmpty();
});

test('retained cancelled statuses invalid import evidence and deleted documents are excluded everywhere', function () {
    metricsImport([
        ['2026-10-01', 'Dental Company', 'Estelite A2', 'SKU1', 99, 99, 9801, 'CANCELLED', 'pcs', 'გაუქმებული'],
        ['2026-10-02', 'Dental Company', 'Estelite A2', 'SKU1', 99, 99, 9801, 'INVALID', 'pcs', 'დასრულებული'],
        ['2026-10-03', 'Dental Company', 'Estelite A2', 'SKU1', 99, 99, 9801, 'DELETED', 'pcs', 'დასრულებული'],
    ]);
    $invalid = Purchase::where('document_number', 'INVALID')->sole();
    $payload = $invalid->rs_import_payload;
    $payload['validated'] = false;
    $payload['validation_errors'] = ['Invalid authoritative total'];
    $invalid->forceFill(['rs_import_payload' => $payload])->saveQuietly();
    Purchase::where('document_number', 'DELETED')->sole()->delete();
    $row = $this->report->products([], (string) $this->group->id)->get()->firstWhere('product_id', $this->product->id);
    expect((int) $row->purchase_count)->toBe(4)->and((float) $row->purchase_amount)->toBe(172.0)
        ->and((float) $row->average_interval)->toBe(30.0)
        ->and(Carbon::parse($row->last_purchase)->toDateString())->toBe('2026-03-02')
        ->and((float) $this->report->groups()->sole()->purchase_amount)->toBe(186.0)
        ->and($this->report->history([], (string) $this->group->id, (string) $this->product->id)->count())->toBe(5);
});

test('existing signed credit lines remain intact rather than being excluded by amount sign', function () {
    $document = Purchase::create(['source' => 'rs', 'supplier_id' => $this->product->supplier_id, 'purchase_date' => '2026-04-01', 'document_number' => 'LEGACY-CREDIT']);
    $document->items()->create(['purchase_product_id' => $this->product->id, 'quantity' => -1, 'unit' => 'pcs', 'unit_price' => 10, 'line_total' => -10]);
    expect((float) $this->report->products([], (string) $this->group->id)->get()->firstWhere('product_id', $this->product->id)->purchase_amount)->toBe(162.0)
        ->and($this->report->history([], (string) $this->group->id, (string) $this->product->id)->where('document_number', 'LEGACY-CREDIT')->sole()->line_total)->toBe('-10.00');
});

test('history exposes canonical source dates supplier document quantities unit prices and original descriptions newest first', function () {
    $page = Livewire::test(AnalysisPage::class)->call('openDirection', (string) $this->therapy)->call('openGroup', (string) $this->group->id)
        ->call('openProduct', (string) $this->product->id)->assertSee('Dental Company')->assertSee('DOC-D')->assertSee('Original RS variant name');
    $rows = $page->instance()->getTableRecords();
    $latest = $rows->first();
    expect($rows)->toHaveCount(5)->and(Carbon::parse($latest->purchase_date)->toDateString())->toBe('2026-03-02')
        ->and($latest->document_number)->toBe('DOC-D')->and($latest->supplier_name)->toBe('Dental Company')
        ->and($latest->quantity)->toBe('6.000')->and($latest->unit)->toBe('pcs')
        ->and($latest->unit_price)->toBe('12.00')->and($latest->line_total)->toBe('72.00');
});

test('legacy missing supplier relations and missing document numbers stay empty rather than fabricated', function () {
    // Ephemeral SQLite fixture only: model a legacy orphan without relaxing application constraints.
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $document = Purchase::where('document_number', 'DOC-D')->sole();
    DB::table('purchases')->where('id', $document->id)->update(['supplier_id' => 999999, 'document_number' => null]);
    $row = $this->report->history([], (string) $this->group->id, (string) $this->product->id)->where('purchase_items.purchase_id', $document->id)->sole();
    expect($row->supplier_name)->toBeNull()->and($row->document_number)->toBeNull()->and($row->unit_price)->toBe('12.00');
});

test('missing modern verification evidence alone does not hide historical or unconfirmed imports', function () {
    $document = Purchase::where('document_number', 'DOC-D')->sole();
    $payload = $document->rs_import_payload;
    $payload['validated'] = false;
    $document->forceFill(['rs_import_payload' => $payload])->saveQuietly();
    expect($this->report->products([], (string) $this->group->id)->get()->firstWhere('product_id', $this->product->id)->last_purchase)->toStartWith('2026-03-02');
    $payload['validated'] = true;
    $payload['source_rows'] = [];
    $document->forceFill(['rs_import_payload' => $payload])->saveQuietly();
    expect($this->report->history([], (string) $this->group->id, (string) $this->product->id)->count())->toBe(5);
    $document->forceFill(['rs_import_payload' => null])->saveQuietly();
    expect($document->import_batch_id)->not->toBeNull()
        ->and($this->report->history([], (string) $this->group->id, (string) $this->product->id)->count())->toBe(5);
});

test('mixed units are not summed or compared in the product UI and stable identities do not merge by name', function () {
    metricsImport([['2026-04-01', 'Dental Company', 'Estelite A2', 'SKU1', 1, 100, 100, 'PACKAGE', 'box', 'აქტიური'],
        ['2026-04-01', 'Another supplier', 'Estelite A2', 'SKU1', 1, 10, 10, 'OTHER-IDENTITY', 'pcs', 'აქტიური']]);
    $other = PurchaseProduct::where('rs_product_code', 'SKU1')->whereKeyNot($this->product->id)->sole();
    $this->catalog->assignCatalogDirection($other, $this->therapy);
    $this->catalog->assignCatalogGroup($other, $this->group->id);
    $rows = $this->report->products([], (string) $this->group->id)->get();
    expect($rows)->toHaveCount(3)->and((int) $rows->firstWhere('product_id', $this->product->id)->unit_count)->toBe(2);
    $page = Livewire::test(AnalysisPage::class)->call('openDirection', (string) $this->therapy)->call('openGroup', (string) $this->group->id);
    $column = $page->instance()->getTable()->getColumn('purchased_quantity');
    $column->record($rows->firstWhere('product_id', $this->product->id));
    expect($column->getState())->toBeNull()->and($page->instance()->getTable()->getColumn('average_price'))->toBeNull();
});

test('period presets and custom dates use compact breadcrumb navigation and keep existing filters combined', function () {
    $page = Livewire::test(AnalysisPage::class)->assertSeeHtml('data-purchase-analysis-compact');
    foreach (['7_days' => '2026-10-13', '1_month' => '2026-09-20', '3_months' => '2026-07-20', '6_months' => '2026-04-20', '1_year' => '2025-10-20'] as $period => $from) {
        $page->set('filters.period', $period)
            ->assertSet('filters.from', fn ($date) => Carbon::parse($date)->toDateString() === $from)
            ->assertSet('filters.until', fn ($date) => Carbon::parse($date)->toDateString() === '2026-10-19');
    }
    $page->set('filters.period', 'all')->assertSet('filters.from', null)->assertSet('filters.until', null)
        ->set('filters.period', 'custom')->set('filters.from', '2026-01-01')->set('filters.until', '2026-01-31')
        ->set('filters.supplier', $this->product->supplier_id)->set('filters.product', $this->product->id)->set('filters.payment', 'unlinked')
        ->set('filters.direction', $this->therapy)->set('filters.group', $this->group->id);
    expect((float) $page->instance()->getTableRecords()->sole()->purchase_amount)->toBe(100.0);
    $page->call('openDirection', (string) $this->therapy)->call('openGroup', (string) $this->group->id)
        ->assertSee('Filling materials')->assertSeeHtml('w-auto shrink-0')->call('back')->assertSet('selectedGroup', null);
});

test('product frequency queries stay bounded as product count grows and reports write nothing', function () {
    $tables = ['purchases', 'purchase_items', 'purchase_products', 'purchase_product_groups', 'suppliers', 'finance_transactions', 'cashbox_transactions', 'bank_transactions', 'partner_finance_transactions', 'payments'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toJson();
    $before = $snapshot();
    // Optional-schema capabilities are checked once per request, outside data retrieval.
    app(PurchaseAnalysisMapping::class)->capabilities();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->report->products([], (string) $this->group->id)->get();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1)->and($snapshot())->toBe($before)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toJson())->toBe($pnl);
    $document = Purchase::where('document_number', 'DOC-A')->sole();
    for ($i = 0; $i < 30; $i++) {
        $product = $this->catalog->createManual(['name' => 'Extra '.$i, 'expense_direction_id' => $this->therapy, 'purchase_product_group_id' => $this->group->id]);
        $document->items()->create(['purchase_product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1, 'unit' => 'pcs']);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = $this->report->products([], (string) $this->group->id)->paginate(25);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(2)->and($rows->count())->toBe(25)->and($rows->total())->toBe(32);
});
