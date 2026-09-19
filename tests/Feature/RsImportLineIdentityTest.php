<?php

use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\FinanceUsdUsageService;
use App\Services\PurchaseCashPayment;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

function lineIdentityRow(array $changes = []): array
{
    return array_replace([
        'date' => '2026-09-19', 'supplier' => 'Identity Supplier', 'product' => 'Material',
        'quantity' => 2, 'unit' => 'pcs', 'price' => 10, 'total' => 20, 'vat' => 0,
        'document' => 'IDENTITY-1', 'code' => '001', 'line' => '',
    ], $changes);
}

function importIdentityRows(array $rows): array
{
    $path = tempnam(sys_get_temp_dir(), 'rs-identity-');
    rename($path, $path .= '.csv');
    $file = fopen($path, 'w');
    fputcsv($file, ['Date', 'Supplier', 'Product', 'Quantity', 'Unit', 'Unit Price', 'Total', 'VAT', 'Document', 'RS Product Code', 'Source Line ID'], escape: '');
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

test('identical legitimate RS lines are each imported once across batching boundaries', function (int $count) {
    $rows = array_fill(0, $count, lineIdentityRow());
    expect(importIdentityRows($rows))->toMatchArray(['documents_imported' => 1, 'imported' => $count, 'skipped' => 0, 'failed_rows' => 0]);
    $hashes = PurchaseItem::orderBy('id')->pluck('source_row_hash')->all();
    expect(array_unique($hashes))->toHaveCount($count);
    expect(importIdentityRows($rows))->toMatchArray(['documents_imported' => 0, 'imported' => 0, 'skipped' => $count, 'failed_rows' => 0]);
    expect(PurchaseItem::count())->toBe($count)->and(Purchase::sole()->total_amount)->toBe(number_format($count * 20, 2, '.', ''))
        ->and(PurchaseItem::orderBy('id')->pluck('source_row_hash')->all())->toBe($hashes);
})->with([2, 3, 102]);

test('overlapping exports preserve multiplicity independent of unrelated lines and supplier boundaries', function () {
    $a = lineIdentityRow();
    $b = lineIdentityRow(['code' => '002', 'product' => 'Other']);
    $otherSupplier = lineIdentityRow(['supplier' => 'Other supplier']);
    expect(importIdentityRows([$a, $a]))->toMatchArray(['imported' => 2]);
    expect(importIdentityRows([$b, $a, $otherSupplier, $a, $a]))->toMatchArray(['imported' => 3, 'skipped' => 2]);
    expect(importIdentityRows([$a, $a, $a, $b, $otherSupplier]))->toMatchArray(['imported' => 0, 'skipped' => 5]);
    expect(PurchaseItem::count())->toBe(5)->and((float) Purchase::sum('total_amount'))->toBe(100.0);
});

test('identical Excel lines and their CSV equivalent share occurrence identities', function () {
    $path = tempnam(sys_get_temp_dir(), 'rs-identical-');
    rename($path, $path .= '.xlsx');
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Date', 'Supplier', 'Product', 'Quantity', 'Unit', 'Unit Price', 'Total', 'VAT', 'Document', 'RS Product Code', 'Source Line ID']));
    $writer->addRow(Row::fromValues(array_values(lineIdentityRow())));
    $writer->addRow(Row::fromValues(array_values(lineIdentityRow())));
    $writer->close();
    try {
        expect(app(PurchaseImportService::class)->import($path))->toMatchArray(['imported' => 2, 'skipped' => 0]);
        expect(importIdentityRows([lineIdentityRow(), lineIdentityRow()]))->toMatchArray(['imported' => 0, 'skipped' => 2]);
    } finally {
        unlink($path);
    }
});

test('source line IDs distinguish identical lines and remain stable under reorder and partial overlap', function () {
    $one = lineIdentityRow(['line' => 'L1']);
    $two = lineIdentityRow(['line' => 'L2']);
    expect(importIdentityRows([$one, $two]))->toMatchArray(['imported' => 2, 'skipped' => 0]);
    expect(importIdentityRows([$two]))->toMatchArray(['imported' => 0, 'skipped' => 1]);
    expect(importIdentityRows([$two, $one]))->toMatchArray(['imported' => 0, 'skipped' => 2]);
    expect(importIdentityRows([lineIdentityRow(['document' => 'OTHER', 'line' => 'L1'])]))->toMatchArray(['imported' => 1]);
    $result = importIdentityRows([lineIdentityRow(['line' => 'L1', 'total' => 99])]);
    expect($result)->toMatchArray(['imported' => 0, 'skipped' => 0, 'failed_rows' => 1])
        ->and($result['errors'][0])->toContain('source line ID already exists with different values')
        ->and(PurchaseItem::count())->toBe(3)->and((float) Purchase::sum('total_amount'))->toBe(60.0);
});

test('historical content hash covers only its own occurrence and remains unchanged', function () {
    importIdentityRows([lineIdentityRow()]);
    $item = PurchaseItem::sole();
    $oldHash = hash('sha256', 'identity supplier|IDENTITY-1|2026-09-19|material|2|pcs|10|20|0|rs|001');
    $item->update(['source_row_hash' => $oldHash]);
    $rows = array_fill(0, 3, lineIdentityRow(['quantity' => '2.000', 'price' => '10.00', 'total' => '20.00', 'vat' => '0.00']));
    expect(importIdentityRows($rows))->toMatchArray(['imported' => 2, 'skipped' => 1, 'failed_rows' => 0]);
    expect(importIdentityRows($rows))->toMatchArray(['imported' => 0, 'skipped' => 3, 'failed_rows' => 0]);
    expect($item->fresh()->source_row_hash)->toBe($oldHash)->and(Purchase::sole()->total_amount)->toBe('60.00');
});

test('legacy product-backed document receives its missing identical occurrence without a second document', function () {
    $supplier = Supplier::create(['name' => 'Identity Supplier']);
    $product = Product::create(['name' => 'Material', 'selling_price' => 0]);
    $purchase = Purchase::create(['supplier_id' => $supplier->id, 'purchase_date' => '2026-09-19', 'document_number' => 'IDENTITY-1']);
    $hash = hash('sha256', implode('|', [$supplier->normalized_name, 'IDENTITY-1', '2026-09-19', $product->normalized_name, 2, 'pcs', 10, 20, 0]));
    $purchase->items()->create(['product_id' => $product->id, 'quantity' => 2, 'unit' => 'pcs', 'unit_price' => 10, 'vat_amount' => 0, 'source_row_hash' => $hash]);
    expect(importIdentityRows([lineIdentityRow(), lineIdentityRow()]))->toMatchArray(['imported' => 1, 'skipped' => 1]);
    expect(importIdentityRows([lineIdentityRow(), lineIdentityRow()]))->toMatchArray(['imported' => 0, 'skipped' => 2]);
    expect(Purchase::count())->toBe(1)->and($purchase->fresh()->total_amount)->toBe('40.00')
        ->and(PurchaseItem::where('source_row_hash', $hash)->count())->toBe(1);
});

test('ambiguous historical content-only row to explicit line ID conversion reports failure', function () {
    importIdentityRows([lineIdentityRow()]);
    PurchaseItem::sole()->update(['source_row_hash' => str_repeat('a', 64)]);
    $result = importIdentityRows([lineIdentityRow(['line' => 'L1'])]);
    expect($result)->toMatchArray(['imported' => 0, 'skipped' => 0, 'failed_rows' => 1])
        ->and($result['errors'][0])->toContain('Cannot safely associate source line IDs')
        ->and(PurchaseItem::count())->toBe(1)->and(Purchase::sole()->total_amount)->toBe('20.00');
});

test('failed identical first line keeps its identity and retries without dropping the second', function () {
    $dispatcher = PurchaseItem::getEventDispatcher();
    PurchaseItem::setEventDispatcher(clone $dispatcher);
    $attempt = 0;
    PurchaseItem::creating(function () use (&$attempt) {
        if (++$attempt === 1) {
            throw new DomainException('Simulated line persistence failure');
        }
    });
    try {
        expect(importIdentityRows([lineIdentityRow(), lineIdentityRow()]))->toMatchArray(['imported' => 1, 'failed_rows' => 1, 'skipped' => 0]);
        $survivingHash = PurchaseItem::sole()->source_row_hash;
        expect(importIdentityRows([lineIdentityRow(), lineIdentityRow()]))->toMatchArray(['imported' => 1, 'failed_rows' => 0, 'skipped' => 1]);
        expect(importIdentityRows([lineIdentityRow(), lineIdentityRow()]))->toMatchArray(['imported' => 0, 'failed_rows' => 0, 'skipped' => 2]);
        expect(PurchaseItem::where('source_row_hash', $survivingHash)->count())->toBe(1)
            ->and(Purchase::sole()->total_amount)->toBe('40.00');
    } finally {
        PurchaseItem::setEventDispatcher($dispatcher);
    }
});

test('duplicate-looking imported lines post their full cash total once and retain allocations', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->actingAs($owner);
    FinanceOpeningBalance::create(['source' => 'cash', 'account_identifier' => 'cash', 'currency' => 'GEL', 'effective_date' => today(), 'amount' => 5000]);
    $rows = [lineIdentityRow(), lineIdentityRow()];
    importIdentityRows($rows);
    $purchase = Purchase::sole();
    app(PurchaseCatalog::class)->assignDirection($purchase->items()->first()->purchaseProduct, app(ExpenseDimensions::class)->id('direction', 'surgery'));
    $cash = app(FinanceUsdUsageService::class);
    $before = $cash->cashBalances('clinic')['GEL'];
    app(PurchaseCashPayment::class)->post($purchase->id, $owner);
    expect(importIdentityRows($rows))->toMatchArray(['imported' => 0, 'skipped' => 2, 'failed_rows' => 0]);
    app(PurchaseCashPayment::class)->post($purchase->id, $owner);
    expect((float) $cash->cashBalances('clinic')['GEL'])->toBe((float) $before - 40)
        ->and(FinanceTransaction::where('purchase_id', $purchase->id)->count())->toBe(1)
        ->and((float) app(AccountingLedger::class)->dimensionEntries(null, null, 'cash')->sum('amount'))->toBe(40.0);
    expect(importIdentityRows([...$rows, lineIdentityRow()]))->toMatchArray(['imported' => 0, 'skipped' => 2, 'failed_rows' => 1]);
    expect($purchase->fresh()->total_amount)->toBe('40.00');
});

test('duplicate-looking imported lines allocate one bank expense at the full document total', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $rows = [lineIdentityRow(), lineIdentityRow()];
    importIdentityRows($rows);
    $purchase = Purchase::sole();
    app(PurchaseCatalog::class)->assignDirection($purchase->items()->first()->purchaseProduct, app(ExpenseDimensions::class)->id('direction', 'surgery'));
    $bank = BankTransaction::create([
        'transaction_date' => today(), 'direction' => 'outflow', 'amount' => 40, 'currency' => 'GEL',
        'operation_type' => 'PMD', 'source' => 'api', 'bank_category_id' => BankCategory::where('code', 'uncategorized')->value('id'),
        'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64),
    ]);
    app(BankPurchaseMatching::class)->confirm($bank->id, [['purchase_id' => $purchase->id, 'amount' => 40]], $owner);
    expect(importIdentityRows($rows))->toMatchArray(['imported' => 0, 'skipped' => 2]);
    $summary = app(BankPurchaseMatching::class)->summary($bank->id);
    expect((float) $summary->allocated)->toBe(40.0)->and($summary->status)->toBe('matched')
        ->and((float) app(AccountingLedger::class)->dimensionEntries(null, null, 'bank')->sum('amount'))->toBe(40.0)
        ->and(BankTransaction::count())->toBe(1)->and(FinanceTransaction::count())->toBe(0);
});
