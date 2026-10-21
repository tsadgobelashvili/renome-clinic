<?php

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Models\FinanceTransaction;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\FinanceManager;
use App\Services\PurchaseAnalysisEligibility;
use App\Services\PurchaseCashPayment;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use App\Services\PurchaseReconciliation;
use App\Services\RsHistoricalImport;
use App\Services\RsImportReport;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->travelTo('2026-10-20 12:00:00');
});

function fullYearRow(array $changes = []): array
{
    return array_replace(['Date' => '2026-01-08', 'Supplier' => 'Full year supplier', 'Product' => 'Material',
        'Quantity' => 1, 'Unit' => 'pcs', 'Unit Price' => 10, 'Total' => 10, 'Document' => 'YEAR-1',
        'RS Product Code' => 'KNOWN', 'Source Line ID' => 'L1', 'Status' => 'active', 'Document Type' => 'purchase',
        'Document Total' => ''], $changes);
}

function fullYearFile(iterable $rows): string
{
    $path = tempnam(sys_get_temp_dir(), 'rs-year-').'.csv';
    $file = fopen($path, 'w');
    fputcsv($file, array_keys(fullYearRow()), escape: '');
    foreach ($rows as $row) {
        fputcsv($file, array_values($row), escape: '');
    }
    fclose($file);

    return $path;
}

function finishFullYearRun(string $id, User $owner): array
{
    // Resolve a fresh service for each request: no process-local counters can be required.
    $guard = 0;
    do {
        $summary = app(RsHistoricalImport::class)->step($id, $owner);
        expect(++$guard)->toBeLessThan(100);
    } while ($summary['phase'] !== 'completed');

    return $summary;
}

test('one upload resumes across page requests and never validates half a document', function () {
    $rows = (function () {
        for ($i = 0; $i < 525; $i++) {
            yield fullYearRow(['Source Line ID' => 'L'.$i, 'Document Total' => 5250]);
        }
    })();
    $path = fullYearFile($rows);
    try {
        $upload = UploadedFile::fake()->createWithContent('full-year.csv', file_get_contents($path))->mimeType('text/csv');
        $page = Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => $upload])->assertHasNoActionErrors()
            ->assertActionNotMounted('importRs')->assertSet('rsImportProgress.phase', 'preparing')
            ->assertSee('ფაილი შენახულია')->assertSee('0 / 0');
        expect(PurchaseItem::count())->toBe(0);
        $page->call('continueRsImport')
            ->assertSee('500 / 525')->assertSee('დამუშავებულია:');
        expect(PurchaseItem::count())->toBe(500);
        $purchase = Purchase::sole();
        expect($purchase->rs_import_payload['validated'])->toBeFalse();
        expect(app(PurchaseAnalysisEligibility::class)->documents()->count())->toBe(0);
        expect(fn () => app(PurchaseCashPayment::class)->post($purchase->id, $this->owner))->toThrow(ValidationException::class);
        $id = $page->get('rsImportRun');
        unset($page);
        Livewire::test(ListPurchases::class)->assertSet('rsImportRun', $id)->call('continueRsImport')
            ->assertSet('rsImportProgress.phase', 'completed')->assertSee('525 / 525');
        expect(PurchaseItem::count())->toBe(525)->and(Purchase::sole()->total_amount)->toBe('5250.00')
            ->and(Purchase::sole()->rs_import_payload['validated'])->toBeTrue();
        app(PurchaseReconciliation::class)->assertValid(Purchase::sole());
        expect(app(PurchaseAnalysisEligibility::class)->documents()->count())->toBe(1);
        expect(FinanceTransaction::count())->toBe(0);
    } finally {
        unlink($path);
    }
});

test('six decimal quantities survive import storage display and source reconciliation without money changes', function (string $quantity, string $stored, string $amount) {
    $path = fullYearFile([fullYearRow(['Quantity' => $quantity, 'Total' => $amount, 'Document Total' => $amount])]);
    try {
        $summary = app(PurchaseImportService::class)->import($path);
        expect($summary['errors'])->toBe([])->and(PurchaseItem::sole()->quantity)->toBe($stored)
            ->and(PurchaseItem::sole()->line_total)->toBe($amount);
        app(PurchaseReconciliation::class)->assertValid(Purchase::sole());
        expect(app(PurchaseImportService::class)->import($path))->toMatchArray(['imported' => 0, 'skipped' => 1, 'errors' => []]);
    } finally {
        unlink($path);
    }
})->with([['0.3333', '0.333300', '3.33'], ['1.234567', '1.234567', '12.35'], ['0.000001', '0.000001', '0.01']]);

test('precision greater than six places is rejected with source evidence', function () {
    $path = fullYearFile([fullYearRow(['Quantity' => '0.1234567'])]);
    try {
        $summary = app(PurchaseImportService::class)->import($path);
        expect($summary)->toMatchArray(['imported' => 0, 'failed_rows' => 1]);
        expect(Storage::disk('local')->get(app(RsImportReport::class)->path($summary['error_report_id'])))->toContain('0.1234567', '6 decimal');
    } finally {
        unlink($path);
    }
});

test('legitimate missing-total formats multiply full quantity precision before money rounding', function () {
    $path = tempnam(sys_get_temp_dir(), 'rs-six-decimal-').'.csv';
    $row = fullYearRow(['Quantity' => '0.3333', 'Unit Price' => '3.00']);
    unset($row['Total']);
    $file = fopen($path, 'w');
    fputcsv($file, array_keys($row), escape: '');
    fputcsv($file, array_values($row), escape: '');
    fclose($file);
    try {
        expect(app(PurchaseImportService::class)->import($path))->toMatchArray(['imported' => 1, 'errors' => []]);
        expect(PurchaseItem::sole()->quantity)->toBe('0.333300')->and(PurchaseItem::sole()->line_total)->toBe('1.00');
    } finally {
        unlink($path);
    }
});

test('run checkpoints and work are owner bound and progress cannot be used to grant permissions', function () {
    $path = fullYearFile([fullYearRow()]);
    try {
        $id = app(RsHistoricalImport::class)->start($path, $this->owner);
        $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
        expect(fn () => app(RsHistoricalImport::class)->step($id, $admin))->toThrow(AuthorizationException::class);
        $otherOwner = User::factory()->create(['role' => User::ROLE_OWNER]);
        expect(fn () => app(RsHistoricalImport::class)->step($id, $otherOwner))->toThrow(HttpException::class);
        expect(Purchase::count())->toBe(0);
    } finally {
        unlink($path);
    }
});

test('a request interrupted after a committed batch retries its checkpoint without losing rows', function () {
    $path = fullYearFile((function () {
        for ($i = 0; $i < 525; $i++) {
            yield fullYearRow(['Source Line ID' => 'INTERRUPTED-'.$i]);
        }
    })());
    try {
        $id = app(RsHistoricalImport::class)->start($path, $this->owner);
        $interrupted = new class(app(PurchaseCatalog::class)) extends PurchaseImportService
        {
            public function processInputs(iterable $inputs, array &$summary, ?int $createdBy, string $batchId): void
            {
                parent::processInputs(array_slice($inputs, 0, 100), $summary, $createdBy, $batchId);
                throw new RuntimeException('Synthetic request interruption after 100 committed rows');
            }
        };
        expect(fn () => (new RsHistoricalImport($interrupted))->step($id, $this->owner))->toThrow(RuntimeException::class);
        expect(PurchaseItem::count())->toBe(100)->and(Purchase::sole()->rs_import_payload['validated'])->toBeFalse();
        $summary = finishFullYearRun($id, $this->owner);
        expect($summary)->toMatchArray(['phase' => 'completed', 'imported' => 425, 'skipped' => 100, 'failed_rows' => 0]);
        expect(PurchaseItem::count())->toBe(525)->and(Purchase::sole()->rs_import_payload['validated'])->toBeTrue();
    } finally {
        unlink($path);
    }
});

test('quantity widening leaves historical values money and source hashes unchanged', function () {
    $path = fullYearFile([fullYearRow(['Quantity' => '1.234', 'Total' => '12.34'])]);
    try {
        app(PurchaseImportService::class)->import($path);
        $snapshot = DB::table('purchase_items')->get()->toJson();
        $migration = require database_path('migrations/2026_10_20_130000_widen_rs_purchase_quantity_precision.php');
        $migration->up();
        $migration->down(); // Never shrink newer quantities on rollback.
        expect(DB::table('purchase_items')->get()->toJson())->toBe($snapshot);
        expect(PurchaseItem::sole()->quantity)->toBe('1.234000');
        expect(app(PurchaseImportService::class)->import($path))->toMatchArray(['imported' => 0, 'skipped' => 1]);
    } finally {
        unlink($path);
    }
});

test('unknown source enums are retained for review and included in the complete private report', function () {
    $path = fullYearFile([fullYearRow(['Document Type' => 'unverified enum'])]);
    try {
        $summary = app(PurchaseImportService::class)->import($path);
        expect($summary)->toMatchArray(['review_rows' => 1, 'imported' => 1, 'failed_rows' => 0]);
        $report = Storage::disk('local')->get(app(RsImportReport::class)->path($summary['error_report_id']));
        expect($report)->toContain('unverified enum', 'Owner review', 'KNOWN');
    } finally {
        unlink($path);
    }
});

test('blank optional IDs still scope rejected rows to their known document and report supplier codes', function () {
    $bad = fullYearRow(['Quantity' => 'bad', 'RS Product Code' => '', 'RS Document ID' => '', 'Supplier Item Code' => 'S-CODE']);
    $valid = array_replace($bad, ['Quantity' => 1, 'Document' => 'UNRELATED']);
    $path = tempnam(sys_get_temp_dir(), 'rs-scoped-error-').'.csv';
    $file = fopen($path, 'w');
    fputcsv($file, array_keys($bad), escape: '');
    fputcsv($file, array_values($bad), escape: '');
    fputcsv($file, array_values($valid), escape: '');
    fclose($file);
    try {
        $summary = app(PurchaseImportService::class)->import($path);
        expect($summary)->toMatchArray(['imported' => 1, 'failed_rows' => 1]);
        app(PurchaseReconciliation::class)->assertValid(Purchase::sole());
        expect(app(PurchaseAnalysisEligibility::class)->documents()->count())->toBe(1);
        $error = json_decode(trim(Storage::disk('local')->get(app(RsImportReport::class)->path($summary['error_report_id']))), true);
        expect($error['document'])->toBe('YEAR-1')->and($error['code'])->toBe('S-CODE');
    } finally {
        unlink($path);
    }
});

test('5000 source rows complete after 970 already imported lines with bounded request state and safe retries', function () {
    $makeRows = function (int $count): Generator {
        $previous = null;
        for ($i = 0; $i < $count; $i++) {
            $doc = intdiv($i, 20);
            $row = fullYearRow(['Date' => sprintf('2026-%02d-%02d', ($doc % 10) + 1, ($doc % 28) + 1),
                'Supplier' => 'Year supplier '.(intdiv($i, 500) % 4), 'Document' => 'DOC-'.$doc,
                'Source Line ID' => 'L'.$i, 'RS Product Code' => $i < 1000 ? 'KNOWN' : 'CODE-'.($i % 10)]);
            if ($i >= 1200 && $i < 1800) {
                $row = array_replace($row, ['Date' => '2026-04-15', 'Supplier' => 'Boundary supplier', 'Document' => 'CROSS-600', 'Document Total' => 6000]);
            }
            if ($i === 1000 || $i === 1001) {
                $row['Quantity'] = $i === 1000 ? '0.3333' : '1.234567';
                $row['Total'] = $i === 1000 ? '3.33' : '12.35';
            }
            if ($i === 2200) {
                $row = array_replace($row, ['Document Type' => 'credit note', 'Quantity' => -1, 'Total' => -10]);
            }
            if ($i === 2400) {
                $row['Status'] = 'cancelled';
            }
            if (in_array($i, [1100, 2100, 3100, 4100, 4900], true)) {
                match ($i) {
                    1100 => $row['Quantity'] = 'bad',
                    2100 => $row['Unit'] = '5',
                    3100 => $row['Quantity'] = -2,
                    4100 => $row['Date'] = '2026-02-31',
                    4900 => $row['Total'] = 'bad',
                };
            }
            if (in_array($i, [2001, 3001, 4001, 4801], true)) {
                $row = $previous;
            }
            yield $row;
            $previous = $row;
        }
    };
    $partial = fullYearFile($makeRows(970));
    $full = fullYearFile($makeRows(5000));
    try {
        expect(app(PurchaseImportService::class)->import($partial))->toMatchArray(['imported' => 970, 'errors' => []]);
        $product = PurchaseProduct::where('rs_product_code', 'KNOWN')->orderBy('id')->firstOrFail();
        $direction = app(ExpenseDimensions::class)->id('direction', 'therapy');
        $group = app(PurchaseCatalog::class)->createGroup(['name' => 'Preserved year group', 'expense_direction_id' => $direction]);
        $product->update(['expense_direction_id' => $direction, 'purchase_product_group_id' => $group->id]);
        // Match the old partial-import validation marker without changing quantities/hashes.
        Purchase::query()->each(function ($purchase) {
            $payload = $purchase->rs_import_payload;
            $payload['validated'] = false;
            $payload['validation_errors'] = ['Old 1000 row limit'];
            $purchase->forceFill(['rs_import_payload' => $payload])->saveQuietly();
        });
        app(FinanceManager::class)->create(['type' => 'income', 'transaction_date' => '2026-01-01', 'category' => 'other',
            'amount' => 7, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier',
            'funding_source' => 'clinic', 'created_by' => $this->owner->id, 'description' => 'Existing unrelated receipt']);
        $tables = ['finance_transactions', 'partner_finance_transactions', 'cashbox_transactions', 'bank_transactions', 'employee_advances', 'employee_advance_entries', 'bank_purchase_matches'];
        $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
        $before = $snapshot();
        $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toArray();
        $beforeDocs = Purchase::count();
        memory_reset_peak_usage();
        $started = hrtime(true);
        expect(filesize($full))->toBeLessThanOrEqual(ListPurchases::rsUploadLimitKiB() * 1024);
        $id = app(RsHistoricalImport::class)->start($full, $this->owner, deferStaging: true);
        expect(app(RsHistoricalImport::class)->status($id, $this->owner)['phase'])->toBe('preparing');
        $steps = $maxStep = 0;
        do {
            $stepStart = hrtime(true);
            $summary = app(RsHistoricalImport::class)->step($id, $this->owner);
            $maxStep = max($maxStep, (hrtime(true) - $stepStart) / 1e9);
            $steps++;
            expect(strlen(json_encode($summary)))->toBeLessThan(20000);
            expect($steps)->toBeLessThan(50);
        } while ($summary['phase'] !== 'completed');
        $metrics = ['source_rows' => 5000, 'bytes' => filesize($full), 'already_present_lines' => 970,
            'seconds' => round((hrtime(true) - $started) / 1e9, 3), 'max_step_seconds' => round($maxStep, 3),
            'peak_php_bytes' => memory_get_peak_usage(true), 'requests' => $steps,
            'documents_imported' => $summary['documents_imported'], 'lines_imported' => $summary['imported'],
            'duplicates' => $summary['skipped'], 'errors' => $summary['failed_rows'], 'database' => 'isolated SQLite in-memory'];
        file_put_contents(storage_path('logs/rs-full-year-scale.json'), json_encode($metrics, JSON_PRETTY_PRINT));
        expect($summary)->toMatchArray(['processed' => 5000, 'total' => 5000, 'imported' => 4021, 'skipped' => 974, 'failed_rows' => 5]);
        expect(PurchaseItem::count())->toBe(4991)->and(Purchase::count())->toBe($beforeDocs + $summary['documents_imported']);
        $cross = Purchase::where('document_number', 'CROSS-600')->sole();
        expect($cross->items()->count())->toBe(600)->and($cross->total_amount)->toBe('6000.00')
            ->and($cross->rs_import_payload['validated'])->toBeTrue();
        expect($product->fresh()->purchase_product_group_id)->toBe($group->id)->and($product->fresh()->expense_direction_id)->toBe($direction);
        expect($snapshot())->toBe($before)->and(app(AccountingLedger::class)->pnlTotals(null, null)->toArray())->toEqual($pnl);
        $errors = trim(Storage::disk('local')->get(app(RsImportReport::class)->path($summary['error_report_id'])));
        expect(explode("\n", $errors))->toHaveCount(5);
        foreach (explode("\n", $errors) as $line) {
            $error = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
            expect($error)->toHaveKeys(['row', 'supplier', 'document', 'product', 'code', 'reason', 'raw']);
        }
        $retryId = app(RsHistoricalImport::class)->start($full, $this->owner);
        expect(finishFullYearRun($retryId, $this->owner))->toMatchArray(['imported' => 0, 'skipped' => 4995, 'failed_rows' => 5]);
        expect(PurchaseItem::count())->toBe(4991)->and($snapshot())->toBe($before);
    } finally {
        unlink($partial);
        unlink($full);
    }
})->group('rs-full-year');
