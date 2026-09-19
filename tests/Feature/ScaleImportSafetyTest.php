<?php

use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Pages\PurchaseItems;
use App\Models\BankTransaction;
use App\Models\BogTransaction;
use App\Models\FinanceTransaction;
use App\Models\Patient;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Services\Bank\BogBankSyncService;
use App\Services\PurchaseImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function safetyRsImport(array $rows): array
{
    $path = tempnam(sys_get_temp_dir(), 'rs-safety-');
    rename($path, $path .= '.csv');
    $file = fopen($path, 'w');
    fputcsv($file, ['Date', 'Supplier', 'Product', 'Quantity', 'Unit', 'Unit Price', 'Total', 'VAT', 'Document', 'RS Product Code'], escape: '');
    foreach ($rows as $row) {
        fputcsv($file, $row, escape: '');
    }
    fclose($file);
    try {
        return app(PurchaseImportService::class)->import($path);
    } finally {
        unlink($path);
    }
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-18 23:59:00', config('app.timezone')));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    config(['services.bog' => ['client_id' => 'safety', 'client_secret' => 'fake-secret', 'account_number' => 'GE00SAFETY', 'account_currency' => 'GEL']]);
    Http::preventStrayRequests();
});

test('RS overlapping old exports skip stored items despite equivalent decimal and whitespace formatting', function () {
    $original = ['2025-01-02', 'Supplier', 'Material', '2', 'pcs', '10', '20', '0', 'OLD-1', '001'];
    $formatted = ['02.01.2025', ' Supplier ', 'Material', '2.000', ' pcs ', '10.00', '20.00', '0.00', 'OLD-1', '001'];
    expect(safetyRsImport([$original]))->toMatchArray(['imported' => 1, 'skipped' => 0]);
    expect(safetyRsImport([$formatted]))->toMatchArray(['documents_imported' => 0, 'imported' => 0, 'skipped' => 1, 'failed_rows' => 0]);
    $new = $original;
    $new[2] = 'New material';
    $new[9] = '002';
    expect(safetyRsImport([$formatted, $new]))->toMatchArray(['documents_imported' => 0, 'imported' => 1, 'skipped' => 1]);
    expect(safetyRsImport([$original, $formatted, $new]))->toMatchArray(['documents_imported' => 0, 'imported' => 1, 'skipped' => 2]);
    expect(safetyRsImport([$original, $formatted, $new]))->toMatchArray(['imported' => 0, 'skipped' => 3]);
    expect(Purchase::count())->toBe(1)->and(PurchaseItem::count())->toBe(3)
        ->and(Purchase::sole()->total_amount)->toBe('60.00')->and(FinanceTransaction::count())->toBe(0);
});

test('RS failed rows can be retried without duplicating earlier successful rows or documents', function () {
    $a = ['2025-01-02', 'Supplier', 'Material A', 2, 'pcs', 10, 20, 0, 'OLD-1', '001'];
    $b = ['not-a-date', 'Supplier', 'Material B', 2, 'pcs', 10, 20, 0, 'OLD-2', '002'];
    expect(safetyRsImport([$a, $b]))->toMatchArray(['documents_imported' => 1, 'imported' => 1, 'skipped' => 0, 'failed_rows' => 1]);
    $b[0] = '2025-01-03';
    expect(safetyRsImport([$a, $b]))->toMatchArray(['documents_imported' => 1, 'imported' => 1, 'skipped' => 1, 'failed_rows' => 0]);
    expect(safetyRsImport([$a, $b]))->toMatchArray(['documents_imported' => 0, 'imported' => 0, 'skipped' => 2, 'failed_rows' => 0]);
    expect(Purchase::count())->toBe(2)->and(PurchaseItem::count())->toBe(2);
});

test('RS equivalent rows in the same new document represent separate occurrences', function () {
    $original = ['2025-01-02', 'Supplier', 'Material', '2', 'pcs', '10', '20', '0', 'OLD-1', '001'];
    $formatted = ['02.01.2025', ' Supplier ', 'Material', '2.000', ' pcs ', '10.00', '20.00', '0.00', 'OLD-1', '001'];
    expect(safetyRsImport([$original, $formatted]))->toMatchArray(['documents_imported' => 1, 'imported' => 2, 'skipped' => 0, 'failed_rows' => 0]);
    expect(safetyRsImport([$formatted, $original]))->toMatchArray(['imported' => 0, 'skipped' => 2]);
    expect(Purchase::count())->toBe(1)->and(PurchaseItem::count())->toBe(2);
});

test('BOG successful checkpoint survives API and publication failures then overlap retries safely', function () {
    $a = ['entryId' => 'A', 'entryDate' => '2026-09-18', 'entryAmount' => 20];
    $b = ['entryId' => 'B', 'entryDate' => '2026-09-19', 'entryAmount' => -5];
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'fake-token', 'expires_in' => 300]),
        '*statement/*' => Http::sequence()->push(['records' => [$a]])->push([], 503)
            ->push(['records' => [$b, [...$a, 'entryAmount' => 99]]])
            ->push(['records' => [$a, $b]])->push(['records' => [$a, $b]]),
    ]);
    $sync = app(BogBankSyncService::class);
    expect($sync->sync())->toMatchArray(['inserted' => 1, 'duplicates' => 0, 'errors' => []]);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/2026-09-12/2026-09-18/true/true/10000'));
    $checkpoint = $sync->lastSuccessfulSync()->toDateTimeString();
    $this->travelTo(CarbonImmutable::parse('2026-09-21 00:01:00', config('app.timezone')));
    expect(fn () => $sync->sync())->toThrow(RuntimeException::class);
    expect($sync->lastSuccessfulSync()->toDateTimeString())->toBe($checkpoint);
    expect(fn () => $sync->sync())->toThrow(DomainException::class);
    expect($sync->lastSuccessfulSync()->toDateTimeString())->toBe($checkpoint)
        ->and(BogTransaction::count())->toBe(1)->and(BankTransaction::count())->toBe(1);
    expect($sync->sync())->toMatchArray(['inserted' => 1, 'duplicates' => 1, 'errors' => []]);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/2026-09-17/2026-09-21/true/true/10000'));
    expect($sync->lastSuccessfulSync()->toDateTimeString())->toBe('2026-09-21 00:01:00');
    expect($sync->sync())->toMatchArray(['inserted' => 0, 'duplicates' => 2, 'errors' => []]);
    expect(BogTransaction::count())->toBe(2)->and(BankTransaction::count())->toBe(2)->and(FinanceTransaction::count())->toBe(0);
});

test('a BOG response at the requested cap cannot silently advance the successful checkpoint', function () {
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'fake-token']),
        '*statement/*' => Http::response(array_fill(0, 10000, ['entryId' => 'capped', 'entryDate' => '2026-09-18', 'entryAmount' => 1])),
    ]);
    expect(fn () => app(BogBankSyncService::class)->sync())->toThrow(RuntimeException::class)
        ->and(DB::table('bog_sync_states')->count())->toBe(0)->and(BogTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(0);
});

test('patient and RS lists remain paginated as fixture history grows', function () {
    foreach (range(1, 150) as $i) {
        Patient::create(['first_name' => 'Scale', 'last_name' => 'Patient '.$i]);
    }
    $rows = array_map(fn ($i) => ['2026-09-18', 'Scale supplier', 'Material', $i, 'pcs', 1, $i, 0, 'DOC-'.$i, '001'], range(1, 150));
    expect(safetyRsImport($rows))->toMatchArray(['documents_imported' => 150, 'imported' => 150, 'failed_rows' => 0]);
    foreach ([ListPatients::class, ListPurchases::class, PurchaseItems::class] as $pageClass) {
        $page = Livewire::test($pageClass)->assertSuccessful();
        $records = $page->instance()->getTableRecords();
        expect($page->instance()->getTable()->isPaginated())->toBeTrue()
            ->and($records->count())->toBeLessThanOrEqual(50)->and($records->total())->toBe(150);
    }
});
