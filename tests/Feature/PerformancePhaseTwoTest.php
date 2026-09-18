<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Finance;
use App\Filament\Pages\FinanceReports;
use App\Models\BankTransaction;
use App\Models\BogTransaction;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Bank\BankIngestionService;
use App\Services\Bank\BogBankSyncService;
use App\Services\BogBusinessApiService;
use App\Services\BogStatementSyncService;
use App\Services\PurchaseImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function phaseTwoMeasure(callable $work): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $start = hrtime(true);
    try {
        $result = $work();
        $queries = collect(DB::getQueryLog());

        return [$result, $queries, round((hrtime(true) - $start) / 1e6, 2)];
    } finally {
        DB::disableQueryLog();
    }
}

beforeEach(function () {
    $this->travelTo('2026-09-18 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    config(['services.bog' => ['client_id' => 'test', 'client_secret' => 'test-secret', 'account_number' => 'GE00PERF', 'account_currency' => 'GEL']]);
    Http::preventStrayRequests();
});

test('BOG historical publication work stays bounded and pending CLI rows are still published', function () {
    $records = array_map(fn ($i) => ['entryId' => 'old-'.$i, 'entryDate' => '2026-09-01', 'entryAmount' => 12.5], range(1, 300));
    app(BogStatementSyncService::class)->import($records, 'GE00PERF', 'GEL');
    $sync = new class extends BogBankSyncService
    {
        public int $processed = 0;

        public function toBankData(BogTransaction $row): BankTransactionData
        {
            $this->processed++;

            return parent::toBankData($row);
        }
    };
    app(BankIngestionService::class)->ingest(BogTransaction::all()->map(fn ($row) => $sync->toBankData($row)), 'api');
    app(BogStatementSyncService::class)->import([['entryId' => 'pending', 'entryDate' => '2026-09-02', 'entryAmount' => -7]], 'GE00PERF', 'GEL');
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'token', 'expires_in' => 300]),
        '*statement/*' => Http::response(['records' => []]),
        '*api/accounts/*' => Http::response(['CurrentBalance' => 120]),
    ]);
    $sync->processed = 0;
    [$first, $firstQueries] = phaseTwoMeasure(fn () => $sync->sync());
    $firstProcessed = $sync->processed;
    $sync->processed = 0;
    [$again, $queries] = phaseTwoMeasure(fn () => $sync->sync());
    expect($first['inserted'])->toBe(1)->and($again['inserted'])->toBe(0)
        ->and(BankTransaction::count())->toBe(301)->and(BogTransaction::count())->toBe(301)
        ->and($firstProcessed)->toBe(1)->and($sync->processed)->toBe(0)
        ->and($firstQueries->count())->toBeLessThanOrEqual(9)->and($queries->count())->toBeLessThanOrEqual(6);
    fwrite(STDOUT, '\nBOG '.json_encode(['pending_rows' => $firstProcessed, 'pending_queries' => $firstQueries->count(), 'noop_rows' => $sync->processed, 'noop_queries' => $queries->count()])."\n");
});

test('RS batch keeps document item totals and duplicate outcomes', function () {
    $path = tempnam(sys_get_temp_dir(), 'phase2-');
    rename($path, $path .= '.csv');
    $handle = fopen($path, 'w');
    fputcsv($handle, ['Date', 'Supplier', 'Product', 'Quantity', 'Unit Price', 'Total', 'Document', 'RS Product Code'], escape: '');
    foreach (range(1, 60) as $i) {
        fputcsv($handle, ['2026-09-18', 'Perf supplier', 'Material '.($i % 5), $i, 2, $i * 2, 'DOC-'.(int) ceil($i / 20), 'CODE-'.($i % 5)], escape: '');
    }
    fclose($handle);
    try {
        [$result, $queries, $ms] = phaseTwoMeasure(fn () => app(PurchaseImportService::class)->import($path));
        [$repeat, $repeatQueries] = phaseTwoMeasure(fn () => app(PurchaseImportService::class)->import($path));
        expect($result)->toMatchArray(['imported' => 60, 'documents_imported' => 3, 'errors' => []])
            ->and($repeat)->toMatchArray(['imported' => 0, 'skipped' => 60, 'errors' => []])
            ->and(PurchaseItem::count())->toBe(60)->and((float) Purchase::sum('total_amount'))->toBe(3660.0);
        expect($queries->count())->toBeLessThanOrEqual(265)->and($repeatQueries->count())->toBeLessThanOrEqual(67)
            ->and($queries->filter(fn ($q) => str_contains($q['query'], 'sum("line_total")')))->toHaveCount(3);
        fwrite(STDOUT, '\nRS '.json_encode(['queries' => $queries->count(), 'repeat_queries' => $repeatQueries->count(), 'refreshes' => $queries->filter(fn ($q) => str_contains($q['query'], 'sum("line_total")'))->count(), 'ms' => $ms])."\n");
    } finally {
        unlink($path);
    }
});

test('BOG reuses one normalization and token for sync plus balance while fresh conflicts stay strict', function () {
    $records = [['entryId' => 'fresh', 'entryDate' => '2026-09-18', 'entryAmount' => -15]];
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'token', 'expires_in' => 300]),
        '*statement/*' => Http::response(['records' => $records]),
        '*api/accounts/*' => Http::response(['CurrentBalance' => 123.45]),
    ]);
    $this->partialMock(BogStatementSyncService::class)->shouldReceive('normalize')->once()->passthru();
    expect(app(BogBankSyncService::class)->sync()['inserted'])->toBe(1);
    Http::assertSentCount(2);
    expect(app(BogBusinessApiService::class)->currentBalance())->toBe('123.45');
    Http::assertSentCount(3);
    expect(BogTransaction::sole()->raw_payload)->toBe($records[0])->and(FinanceTransaction::count())->toBe(0);
});

test('historical conflicts and removed ledger rows still go through publication', function () {
    $record = ['entryId' => 'pending-check', 'entryDate' => '2026-09-01', 'entryAmount' => 12.5];
    app(BogStatementSyncService::class)->import([$record], 'GE00PERF', 'GEL');
    $sync = app(BogBankSyncService::class);
    app(BankIngestionService::class)->ingest([$sync->toBankData(BogTransaction::sole())], 'api');
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'token', 'expires_in' => 300]),
        '*statement/*' => Http::response(['records' => []]),
    ]);
    BankTransaction::sole()->update(['amount' => 99]);
    expect(fn () => $sync->sync())->toThrow(DomainException::class)
        ->and($sync->lastSuccessfulSync())->toBeNull();
    BankTransaction::sole()->delete();
    expect($sync->sync()['inserted'])->toBe(1)->and(BankTransaction::sole()->amount)->toBe('12.50');
    $duplicate = BankTransaction::sole()->replicate();
    $duplicate->deduplication_key = hash('sha256', 'legacy-duplicate');
    $duplicate->save();
    expect(fn () => $sync->sync())->toThrow(DomainException::class);
});

test('BOG token invalidation expiry credential changes and scope boundaries require fresh authentication', function () {
    Http::fake([
        '*openid-connect/token' => Http::sequence()->push(['access_token' => 'one', 'expires_in' => 300])
            ->push(['access_token' => 'two', 'expires_in' => 300])->push(['access_token' => 'three', 'expires_in' => 1])
            ->push(['access_token' => 'four', 'expires_in' => 300])->push(['access_token' => 'five', 'expires_in' => 300]),
        '*api/accounts/*' => Http::sequence()->push(['CurrentBalance' => 1])->push([], 401)
            ->push(['CurrentBalance' => 2])->push(['CurrentBalance' => 3])->push(['CurrentBalance' => 4])->push(['CurrentBalance' => 5]),
    ]);
    $api = app(BogBusinessApiService::class);
    expect($api->currentBalance())->toBe('1.00');
    expect(fn () => $api->currentBalance())->toThrow(RuntimeException::class);
    expect($api->currentBalance())->toBe('2.00');
    config(['services.bog.client_id' => 'changed']);
    expect($api->currentBalance())->toBe('3.00')->and($api->currentBalance())->toBe('4.00');
    app()->forgetScopedInstances();
    expect(app(BogBusinessApiService::class)->currentBalance())->toBe('5.00');
    Http::assertSentCount(11); // Six account calls + five authentication calls; no retry hides an error.
});

test('RS chunk boundaries and failed rows preserve totals and row savepoints', function () {
    $path = tempnam(sys_get_temp_dir(), 'phase2-');
    rename($path, $path .= '.csv');
    $file = fopen($path, 'w');
    fputcsv($file, ['Date', 'Supplier', 'Product', 'Quantity', 'Unit Price', 'Total', 'Document'], escape: '');
    foreach (range(1, 205) as $i) {
        fputcsv($file, ['2026-09-18', 'Chunks', 'Product', $i, 1, $i, 'ONE'], escape: '');
    }
    fputcsv($file, ['invalid-date', 'Chunks', 'Rolled back product', 1, 1, 1, 'INVALID'], escape: '');
    fputcsv($file, ['2026-09-18', 'Chunks', 'Rolled back product', 1, 1, 1, 'VALID'], escape: '');
    fclose($file);
    try {
        [$result, $queries] = phaseTwoMeasure(fn () => app(PurchaseImportService::class)->import($path));
        expect($result)->toMatchArray(['imported' => 206, 'documents_imported' => 2, 'failed_rows' => 1])
            ->and(PurchaseItem::count())->toBe(206)->and((float) Purchase::sum('total_amount'))->toBe(21116.0)
            ->and($queries->filter(fn ($q) => str_contains($q['query'], 'sum("line_total")')))->toHaveCount(4)
            ->and(PurchaseProduct::where('name', 'Rolled back product')->count())->toBe(1);
    } finally {
        unlink($path);
    }
});

test('RS importer still rejects additional monetary rows on cash-paid documents', function () {
    $supplier = Supplier::create(['name' => 'Paid supplier']);
    $purchase = Purchase::create(['supplier_id' => $supplier->id, 'source' => 'rs', 'source_document_id' => 'PAID', 'purchase_date' => today(), 'total_amount' => 10]);
    FinanceTransaction::create(['type' => 'expense', 'purchase_id' => $purchase->id, 'transaction_date' => today(), 'amount' => 10, 'currency' => 'GEL', 'category' => 'materials', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    $path = tempnam(sys_get_temp_dir(), 'phase2-');
    rename($path, $path .= '.csv');
    file_put_contents($path, "Date,Supplier,Product,Quantity,Unit Price,Total,Document\n2026-09-18,Paid supplier,Material,1,20,20,PAID\n2026-09-18,Paid supplier,Material,1,20,20,NEW\n");
    try {
        $result = app(PurchaseImportService::class)->import($path);
        expect($result)->toMatchArray(['imported' => 1, 'failed_rows' => 1])
            ->and($purchase->fresh()->total_amount)->toBe('10.00')->and($purchase->items()->count())->toBe(0)
            ->and(FinanceTransaction::count())->toBe(1);
    } finally {
        unlink($path);
    }
});

test('Statistics descriptions load on demand and obey changed filters', function () {
    FinanceTransaction::create(['type' => 'income', 'transaction_date' => today(), 'amount' => 15, 'currency' => 'GEL',
        'category' => 'other_income', 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'description' => 'On demand detail']);
    Livewire::test(FinanceReports::class)->assertViewHas('breakdownDescriptions', [])
        ->assertDontSee('On demand detail')->set('showBreakdownDescriptions', true)
        ->assertViewHas('breakdownDescriptions', fn ($rows) => $rows['other_income'] === ['On demand detail'])
        ->assertSee('On demand detail')->set('currency', 'USD')->assertDontSee('On demand detail');
});

test('Finance and Statistics preserve totals across source currency and date filters', function () {
    foreach (['GEL', 'USD'] as $currency) {
        foreach (['2026-09-01', '2026-09-18'] as $date) {
            foreach (['income' => 120, 'expense' => 30] as $type => $amount) {
                FinanceTransaction::create(['type' => $type, 'transaction_date' => $date, 'category' => $type === 'income' ? 'other_income' : 'materials',
                    'amount' => $amount, 'currency' => $currency, 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'description' => 'Fixture description']);
            }
        }
    }
    $measurements = [];
    foreach (['2026-09-01', '2026-09-18'] as $from) {
        foreach (['all', 'clinic', 'partner'] as $source) {
            foreach (['GEL', 'USD'] as $currency) {
                $page = new FinanceReports;
                $page->dateFrom = $from;
                $page->dateUntil = '2026-09-18';
                $page->source = $source;
                $page->currency = $currency;
                foreach (['income', 'expense', 'cash_out'] as $tab) {
                    $page->reportTab = $tab;
                    [$data, $queries] = phaseTwoMeasure(fn () => (new ReflectionMethod($page, 'getViewData'))->invoke($page));
                    $expected = $source === 'partner' ? 0.0 : ($from === '2026-09-01' ? 2 : 1) * ($tab === 'income' ? 120.0 : 30.0);
                    expect($data['reportTotal'])->toBe($expected)
                        ->and((float) collect($data['reportRows'])->sum('amount'))->toBe($expected)
                        ->and((int) collect($data['reportRows'])->sum('count'))->toBe($source === 'partner' ? 0 : ($from === '2026-09-01' ? 2 : 1));
                    expect($queries->filter(fn ($q) => str_contains($q['query'], 'group by "currency", "type", "category"')))->toHaveCount(1);
                    $measurements[$tab] = $queries->count();
                }
            }
        }
    }
    $page = new Finance;
    $page->dateFrom = '2026-09-01';
    $page->dateUntil = '2026-09-18';
    [$data, $queries] = phaseTwoMeasure(fn () => (new ReflectionMethod($page, 'getViewData'))->invoke($page));
    expect($data['figures']['GEL']['revenue'])->toBe(240.0)->and($data['figures']['USD']['expenses'])->toBe(60.0);
    fwrite(STDOUT, '\nFINANCE '.json_encode(['statistics_queries' => $measurements, 'overview_queries' => $queries->count()])."\n");
});

test('shared finance aggregates preserve split salary currency rounding and cash versus bank expense populations', function () {
    foreach ([
        ['currency' => 'GEL', 'amount' => 100.01, 'clinic_cash_gel' => 60.01, 'israeli_cash_gel' => 40],
        ['currency' => 'GEL', 'amount' => 25.99, 'payment_method' => 'bank_transfer', 'cash_source' => 'other'],
        ['currency' => 'USD', 'amount' => 7.33],
    ] as $row) {
        // Read-side fixture of existing immutable salary allocations, as in FinanceOverviewTest.
        DB::table('finance_transactions')->insert(array_replace(['type' => 'expense', 'transaction_date' => today(), 'category' => 'salary',
            'payment_method' => 'cash', 'cash_source' => 'current_cashier'], $row));
    }
    PartnerFinanceTransaction::create(['source' => 'israeli', 'type' => 'expense', 'transacted_at' => today(),
        'currency' => 'GEL', 'amount' => 9.37, 'category' => 'salary', 'from_account' => 'cash']);
    $page = new FinanceReports;
    $page->dateFrom = '2026-09-18';
    $page->dateUntil = '2026-09-18';
    foreach (['all' => [135.37, 109.38], 'clinic' => [86.0, 60.01], 'partner' => [49.37, 49.37]] as $source => [$expense, $cash]) {
        $page->source = $source;
        $data = (new ReflectionMethod($page, 'getViewData'))->invoke($page);
        expect($data['totalsByCurrency']['GEL']['expense'])->toBe($expense)
            ->and($data['cashOutByCurrency']['GEL'])->toBe($cash)
            ->and($data['totalsByCurrency']['USD']['expense'])->toBe($source === 'partner' ? 0.0 : 7.33);
    }
    $page->dateFrom = $page->dateUntil = '2026-09-17';
    $data = (new ReflectionMethod($page, 'getViewData'))->invoke($page);
    expect($data['totalsByCurrency']['GEL']['expense'])->toBe(0.0);
});
