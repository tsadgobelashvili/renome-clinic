<?php

use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceTransaction;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\User;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\EmployeeAdvanceService;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\PurchaseAnalysis;
use App\Services\PurchaseAnalysisEligibility;
use App\Services\PurchaseCashPayment;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use App\Services\PurchaseReconciliation;
use App\Services\RsImportReport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->travelTo('2026-10-20 12:00:00');
    $this->importer = app(PurchaseImportService::class);
});

function historicalImportRows(array $rows, ?array $headers = null): array
{
    $path = tempnam(sys_get_temp_dir(), 'historical-').'.csv';
    $file = fopen($path, 'w');
    fputcsv($file, $headers ?? array_keys($rows[0]), escape: '');
    foreach ($rows as $row) {
        fputcsv($file, array_values($row), escape: '');
    }
    fclose($file);
    try {
        return app(PurchaseImportService::class)->import($path);
    } finally {
        unlink($path);
    }
}

function historicalRow(array $changes = []): array
{
    return array_replace(['Date' => '2026-01-08', 'Supplier' => 'Historical supplier', 'Product' => 'Material',
        'Quantity' => 2, 'Unit' => 'pcs', 'Unit Price' => 10, 'Total' => 20, 'Document' => 'DOC-1',
        'RS Product Code' => 'CODE', 'Source Line ID' => 'L1', 'Status' => 'active', 'Document Type' => 'purchase'], $changes);
}

test('overlapping full year preserves classified and alias products enriches analytics and changes no finances', function () {
    $october = historicalRow(['Date' => '2026-10-08', 'Product' => 'Known filling', 'Quantity' => 5, 'Unit Price' => 110,
        'Total' => 550, 'Document' => 'OCT-1', 'RS Product Code' => 'KNOWN', 'VAT' => 0]);
    historicalImportRows([$october]);
    $product = PurchaseProduct::sole();
    $direction = app(ExpenseDimensions::class)->id('direction', 'therapy');
    $group = app(PurchaseCatalog::class)->createGroup(['name' => 'Known group', 'expense_direction_id' => $direction]);
    $product->update(['expense_direction_id' => $direction, 'purchase_product_group_id' => $group->id]);
    DB::table('purchase_product_aliases')->insert(['supplier_id' => $product->supplier_id,
        'identity_key' => hash('sha256', $product->supplier_id.'|rs|ALIAS'), 'purchase_product_id' => $product->id]);
    $tables = ['finance_transactions', 'partner_finance_transactions', 'cashbox_transactions', 'bank_transactions',
        'employee_advances', 'employee_advance_entries', 'bank_purchase_matches', 'payments', 'visit_payments'];
    $tables = array_values(array_filter($tables, fn ($table) => Schema::hasTable($table)));
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $snapshot();
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toArray();
    $result = $this->importer->import(base_path('tests/Fixtures/rs-historical-year.csv'));
    expect($result)->toMatchArray(['imported' => 5, 'skipped' => 1, 'failed_rows' => 3]);
    expect(Purchase::count())->toBe(6)->and(PurchaseItem::count())->toBe(6)
        ->and($product->fresh()->expense_direction_id)->toBe($direction)
        ->and($product->fresh()->purchase_product_group_id)->toBe($group->id)
        ->and(Purchase::where('document_number', 'MAR-1')->sole()->items()->sole()->purchase_product_id)->toBe($product->id)
        ->and(PurchaseProduct::where('rs_product_code', 'NEW')->sole()->expense_direction_id)->toBeNull()
        ->and(PurchaseProduct::where('rs_product_code', 'NEW')->sole()->purchase_product_group_id)->toBeNull()
        ->and($snapshot())->toBe($before)->and(app(AccountingLedger::class)->pnlTotals(null, null)->toArray())->toBe($pnl);
    $row = app(PurchaseAnalysis::class)->products([], (string) $group->id, (string) $direction)->sole();
    expect((float) $row->purchase_amount)->toBe(790.0)->and((string) $row->last_purchase)->toContain('2026-10-08')
        ->and((float) $row->average_interval)->toBe(136.5);
    $retry = $this->importer->import(base_path('tests/Fixtures/rs-historical-year.csv'));
    expect($retry)->toMatchArray(['imported' => 0, 'skipped' => 6, 'failed_rows' => 3])->and(PurchaseItem::count())->toBe(6);
    $report = Storage::disk('local')->get(app(RsImportReport::class)->path($result['error_report_id']));
    expect($report)->toContain('JUN-BAD', 'JUL-BAD', 'AUG-BAD', 'Historical supplier', '60500')->not->toContain('Stack trace', 'SQLSTATE');
});

test('cancelled and explicitly typed credit documents are retained signed but never normal purchases or payable', function (array $changes, string $kind, string $total) {
    $result = historicalImportRows([historicalRow($changes)]);
    expect($result['errors'])->toBe([]);
    $purchase = Purchase::sole();
    expect($purchase->rs_import_payload['document_kind'])->toBe($kind)->and($purchase->total_amount)->toBe($total)
        ->and(app(PurchaseAnalysisEligibility::class)->documents()->count())->toBe(0);
    expect(fn () => app(PurchaseReconciliation::class)->assertValid($purchase))->toThrow(ValidationException::class)
        ->and(fn () => app(PurchaseCashPayment::class)->post($purchase->id, $this->owner))->toThrow(ValidationException::class);
    $bank = BankTransaction::create(['transaction_date' => '2026-01-08', 'direction' => 'outflow', 'amount' => 20,
        'currency' => 'GEL', 'operation_type' => 'PMD', 'bank_category_id' => BankCategory::where('code', 'uncategorized')->value('id'),
        'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64)]);
    expect(fn () => app(BankPurchaseMatching::class)->confirm($bank->id, [['purchase_id' => $purchase->id, 'amount' => 20]], $this->owner))->toThrow(ValidationException::class);
    $employee = Employee::create(['first_name' => 'Buyer', 'last_name' => 'Test', 'is_active' => true]);
    $advance = app(EmployeeAdvanceService::class)->issue(['employee_id' => $employee->id, 'source' => 'other',
        'amount' => 100, 'date' => '2026-01-08', 'posting_key' => (string) Str::uuid()], $this->owner);
    expect(fn () => app(EmployeeAdvanceService::class)->settlePurchase($advance->id, $purchase->id, '2026-10-20', (string) Str::uuid(), $this->owner))->toThrow(ValidationException::class);
    expect(FinanceTransaction::count())->toBe(0)->and(DB::table('employee_advance_entries')->count())->toBe(0);
})->with([
    [['Status' => 'გაუქმებული'], 'cancelled', '20.00'],
    [['Status' => 'deleted'], 'cancelled', '20.00'],
    [['Document Type' => 'credit note', 'Quantity' => -2, 'Total' => -20], 'return', '-20.00'],
    [['Document Type' => 'correction'], 'return', '20.00'],
    [['Status' => '999'], 'review', '20.00'],
    [['Document Type' => 'unverified type'], 'review', '20.00'],
]);

test('old raw cancellation and return evidence is blocked without rewriting historical payloads', function (array $changes) {
    historicalImportRows([historicalRow($changes)]);
    $purchase = Purchase::sole();
    $payload = $purchase->rs_import_payload;
    unset($payload['document_kind']);
    $purchase->forceFill(['rs_import_payload' => $payload])->saveQuietly();
    expect(fn () => app(PurchaseReconciliation::class)->assertValid($purchase))->toThrow(ValidationException::class)
        ->and(app(PurchaseAnalysisEligibility::class)->documents()->count())->toBe(0);
})->with([[['Status' => 'cancelled']], [['Document Type' => 'return']]]);

test('invalid historical rows are reported with original values and never silently mapped', function (array $changes) {
    $result = historicalImportRows([historicalRow($changes)]);
    expect($result['failed_rows'])->toBe(1)->and(PurchaseItem::count())->toBe(0)->and(Purchase::count())->toBe(0);
    $entry = json_decode(trim(Storage::disk('local')->get(app(RsImportReport::class)->path($result['error_report_id']))), true);
    expect($entry['row'])->toBe(2)->and($entry['supplier'])->toBe('Historical supplier')
        ->and($entry['raw']['cells'])->not->toBeEmpty()->and($entry['reason'])->not->toBeEmpty();
})->with([
    [['Date' => '']], [['Date' => '2026-02-31']], [['Date' => 'today']], [['Quantity' => -2]],
    [['Unit' => 5, 'Quantity' => 110, 'Unit Price' => 550, 'Total' => 60500]],
    [['Total' => '']], [['Unit Price' => '']], [['Document' => '']], [['Total' => 'bad']],
]);

test('row width rejects numeric shifted cells and multiplication is allowed only without a total header', function () {
    $row = historicalRow();
    $result = historicalImportRows([[...array_values($row), 999]], array_keys($row));
    expect($result['failed_rows'])->toBe(1)->and(Purchase::count())->toBe(0);
    unset($row['Total']);
    expect(historicalImportRows([$row]))->toMatchArray(['imported' => 1, 'errors' => []]);
    expect(PurchaseItem::sole()->line_total)->toBe('20.00');
});

test('unchanged partial or interrupted committed batches can retry without duplicating', function () {
    $rows = [];
    for ($index = 1; $index <= 130; $index++) {
        $rows[] = historicalRow(['Document' => 'RETRY-'.$index]);
    }
    expect(historicalImportRows(array_slice($rows, 0, 100))['imported'])->toBe(100);
    expect(historicalImportRows($rows))->toMatchArray(['imported' => 30, 'skipped' => 100, 'errors' => []]);
    expect(historicalImportRows($rows))->toMatchArray(['imported' => 0, 'skipped' => 130, 'errors' => []]);
    expect(Purchase::count())->toBe(130)->and(PurchaseItem::count())->toBe(130);
});

test('document number fallback reuses a deterministic stable id and different supplier requires review', function () {
    historicalImportRows([historicalRow()]);
    expect(historicalImportRows([historicalRow(['RS Document ID' => 'ID-123'])]))->toMatchArray(['imported' => 0, 'skipped' => 1]);
    expect(historicalImportRows([historicalRow(['Supplier' => 'Changed supplier spelling'])]))->toMatchArray(['imported' => 0, 'failed_rows' => 1]);
    expect(Purchase::count())->toBe(1)->and(PurchaseItem::count())->toBe(1);
});

test('accounted duplicates skip and changed or added lines cannot rewrite accounted history', function () {
    historicalImportRows([historicalRow()]);
    FinanceOpeningBalance::create(['source' => 'cash', 'account_identifier' => 'cash', 'currency' => 'GEL', 'effective_date' => '2026-01-01', 'amount' => 1000]);
    $purchase = Purchase::sole();
    app(PurchaseCashPayment::class)->post($purchase->id, $this->owner);
    $before = $purchase->fresh()->getAttributes();
    expect(historicalImportRows([historicalRow()]))->toMatchArray(['skipped' => 1, 'errors' => []]);
    expect(historicalImportRows([historicalRow(['Source Line ID' => 'L2'])]))->toMatchArray(['imported' => 0, 'failed_rows' => 1]);
    expect(historicalImportRows([historicalRow(['Status' => 'cancelled'])]))->toMatchArray(['imported' => 0, 'failed_rows' => 1]);
    expect($purchase->fresh()->getAttributes())->toBe($before)->and(FinanceTransaction::count())->toBe(1)->and(PurchaseItem::count())->toBe(1);
});

test('a cancellation observed on an unaccounted duplicate is retained without changing line identity', function () {
    historicalImportRows([historicalRow()]);
    $item = PurchaseItem::sole();
    $before = $item->getAttributes();
    expect(historicalImportRows([historicalRow(['Status' => 'cancelled'])]))->toMatchArray(['imported' => 0, 'skipped' => 1, 'errors' => []]);
    $purchase = Purchase::sole();
    expect($purchase->rs_import_payload['document_kind'])->toBe('cancelled')
        ->and($purchase->rs_import_payload['state_evidence']['cancelled']['status'])->toBe('cancelled')
        ->and($item->fresh()->getAttributes())->toBe($before)
        ->and(app(PurchaseAnalysisEligibility::class)->documents()->count())->toBe(0);
    expect(fn () => app(PurchaseReconciliation::class)->assertValid($purchase))->toThrow(ValidationException::class);
});

test('all errors have private downloadable reports with existing owner authorization', function () {
    $result = historicalImportRows(array_fill(0, 12, historicalRow(['Quantity' => 'invalid'])));
    $response = $this->get(route('purchases.import-report', $result['error_report_id']))->assertOk();
    expect(substr_count($response->streamedContent(), '"reason"'))->toBe(12);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $this->get(route('purchases.import-report', $result['error_report_id']))->assertForbidden();
});

test('missing existing alias migration blocks import clearly instead of SQL failures', function () {
    Schema::shouldReceive('hasTable')->once()->with('purchase_product_aliases')->andReturn(false);
    expect(fn () => historicalImportRows([historicalRow()]))->toThrow(ValidationException::class);
    expect(Purchase::count())->toBe(0);
});

test('document identity unique migration refuses conflicts without merging any rows', function () {
    $migration = require database_path('migrations/2026_10_20_120000_protect_rs_document_identity.php');
    $migration->down();
    historicalImportRows([historicalRow()]);
    $purchase = Purchase::sole();
    Purchase::create(['source' => 'rs', 'supplier_id' => $purchase->supplier_id, 'source_document_id' => $purchase->source_document_id,
        'purchase_date' => '2026-01-08', 'document_number' => 'Duplicate for migration test']);
    expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'Duplicate purchase source identities require review');
    expect(Purchase::count())->toBe(2)->and(PurchaseItem::count())->toBe(1);
});

test('database enforces stable document uniqueness while nullable legacy identities remain valid', function () {
    historicalImportRows([historicalRow()]);
    $purchase = Purchase::sole();
    expect(fn () => Purchase::create(['source' => 'rs', 'supplier_id' => $purchase->supplier_id,
        'source_document_id' => $purchase->source_document_id, 'purchase_date' => '2026-01-08']))
        ->toThrow(QueryException::class);
    foreach ([1, 2] as $number) {
        Purchase::create(['source' => 'rs', 'supplier_id' => $purchase->supplier_id, 'purchase_date' => '2026-01-08', 'document_number' => 'LEGACY-'.$number]);
    }
    expect(Purchase::count())->toBe(3);
});

test('errors follow deterministic document ID fallback and block incomplete confirmation', function () {
    historicalImportRows([historicalRow()]);
    $first = historicalRow(['RS Document ID' => 'NEW-ID']);
    $bad = historicalRow(['RS Document ID' => 'NEW-ID', 'Source Line ID' => 'L2', 'Quantity' => 'bad']);
    expect(historicalImportRows([$first, $bad]))->toMatchArray(['skipped' => 1, 'failed_rows' => 1]);
    $purchase = Purchase::sole();
    expect($purchase->rs_import_payload['validated'])->toBeFalse();
    expect(fn () => app(PurchaseReconciliation::class)->assertValid($purchase))->toThrow(ValidationException::class);
});

test('an actual parser interruption leaves committed batches safe to retry', function () {
    $path = tempnam(sys_get_temp_dir(), 'rs-interruption-').'.csv';
    $file = fopen($path, 'w');
    fputcsv($file, array_keys(historicalRow()), escape: '');
    for ($index = 0; $index < 130; $index++) {
        fputcsv($file, array_values(historicalRow(['Document' => 'INTERRUPT-'.$index])), escape: '');
    }
    fclose($file);
    $parser = $this->importer;
    $interrupted = Mockery::mock(PurchaseImportService::class, [app(PurchaseCatalog::class)])->makePartial();
    $interrupted->shouldReceive('sourceRows')->andReturnUsing(function ($path) use ($parser) {
        $seen = 0;
        foreach ($parser->sourceRows($path) as $input) {
            if (++$seen === 101) {
                throw new RuntimeException('Synthetic interruption after committed batch');
            }
            yield $input;
        }
    });
    try {
        expect(fn () => $interrupted->import($path))->toThrow(RuntimeException::class, 'Synthetic interruption');
        expect(Purchase::count())->toBe(100)->and(PurchaseItem::count())->toBe(100);
        expect($this->importer->import($path))->toMatchArray(['imported' => 30, 'skipped' => 100, 'errors' => []]);
        expect(PurchaseItem::count())->toBe(130);
    } finally {
        unlink($path);
    }
});

test('historical source files continue past the former 1000 row boundary', function () {
    $rows = [];
    for ($index = 0; $index < 1100; $index++) {
        $rows[] = historicalRow(['Document' => 'NO-LIMIT-'.intdiv($index, 10), 'Source Line ID' => 'L'.($index % 10)]);
    }
    expect(historicalImportRows($rows))->toMatchArray(['imported' => 1100, 'failed_rows' => 0, 'errors' => []]);
    expect(historicalImportRows($rows))->toMatchArray(['imported' => 0, 'skipped' => 1100, 'errors' => []]);
    expect(PurchaseItem::count())->toBe(1100)->and(FinanceTransaction::count())->toBe(0);
})->group('rs-scale');
