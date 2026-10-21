<?php

use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\CashboxTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvanceEntry;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceTransaction;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\EmployeeAdvanceService;
use App\Services\Finance\AccountingLedger;
use App\Services\FinanceManager;
use App\Services\PurchaseCashPayment;
use App\Services\PurchaseImportService;
use App\Services\PurchaseReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-15 12:00:00');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    FinanceOpeningBalance::create(['source' => 'cash', 'account_identifier' => 'cash', 'currency' => 'GEL', 'effective_date' => '2026-10-01', 'amount' => 10000]);
    $this->importer = app(PurchaseImportService::class);
});

function reconciliationCsv(array $changes = [], bool $documentTotal = true): string
{
    $path = tempnam(sys_get_temp_dir(), 'rs-reconcile-').'.csv';
    $file = fopen($path, 'w');
    $row = array_replace(['Date' => '2026-10-08', 'Supplier' => 'Fixture supplier', 'RS Product Code' => 'CODE-110',
        'Product' => 'Real material', 'Quantity' => 5, 'Unit' => 'ნაკრები', 'Unit Price' => 110, 'Total' => 550,
        'VAT' => 83.90, 'Document' => 'REAL-RS-1', 'Document Total' => 550], $changes);
    if (! $documentTotal) {
        unset($row['Document Total']);
    }
    fputcsv($file, array_keys($row), escape: '');
    fputcsv($file, array_values($row), escape: '');
    fclose($file);

    return $path;
}

function reconciliationImport(array $changes = [], bool $documentTotal = true): array
{
    $path = reconciliationCsv($changes, $documentTotal);
    try {
        return app(PurchaseImportService::class)->import($path);
    } finally {
        unlink($path);
    }
}

test('real RS unquoted description breaks are reassembled without shifting columns', function () {
    $path = base_path('tests/Fixtures/rs-unquoted-description.csv');
    expect($this->importer->import($path))->toMatchArray(['imported' => 7, 'documents_imported' => 1, 'failed_rows' => 0, 'errors' => []]);
    $purchase = Purchase::sole();
    expect($purchase->document_number)->toBe('1013032128')->and($purchase->purchase_date->toDateString())->toBe('2026-10-08')
        ->and($purchase->supplier->name)->toBe('Fixture supplier')->and($purchase->total_amount)->toBe('3052.00');
    $silicone = $purchase->items()->whereHas('purchaseProduct', fn ($q) => $q->where('rs_product_code', 'TM033B-4'))->sole();
    $gown = $purchase->items()->whereHas('purchaseProduct', fn ($q) => $q->where('rs_product_code', 'SH2622'))->sole();
    expect($silicone->item_name)->toBe('სილიკონი - FLESIL (400Gx2; 50mlX2; )')
        ->and($silicone->quantity)->toBe('5.000000')->and($silicone->unit)->toBe('ნაკრები')
        ->and($silicone->unit_price)->toBe('110.00')->and($silicone->line_total)->toBe('550.00')
        ->and($gown->item_name)->toBe('ხალათი ერთჯერადი')->and($gown->quantity)->toBe('200.000000')
        ->and($gown->unit)->toBe('ცალი')->and($gown->unit_price)->toBe('1.00')->and($gown->line_total)->toBe('200.00')
        ->and($silicone->vat_amount)->toBeNull()
        ->and($purchase->rs_import_payload['source_rows'][$silicone->source_row_hash]['raw']['original_rows'])->toHaveCount(2)
        ->and($purchase->rs_import_payload)->not->toHaveKey('document_total');
    app(PurchaseReconciliation::class)->assertValid($purchase);
    expect($this->importer->import($path))->toMatchArray(['imported' => 0, 'skipped' => 7, 'errors' => []]);
    expect(PurchaseItem::count())->toBe(7)->and(FinanceTransaction::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
});

test('source columns and explicit document total remain distinct and auditable', function () {
    expect(reconciliationImport())->toMatchArray(['imported' => 1, 'errors' => []]);
    $purchase = Purchase::sole();
    $item = PurchaseItem::sole();
    expect($item->purchaseProduct->rs_product_code)->toBe('CODE-110')->and($item->quantity)->toBe('5.000000')
        ->and($item->unit)->toBe('ნაკრები')->and($item->unit_price)->toBe('110.00')->and($item->line_total)->toBe('550.00')
        ->and((float) $item->vat_amount)->toBe(83.90)->and($purchase->rs_import_payload['document_total'])->toBe('550.00')
        ->and($purchase->total_amount)->toBe('550.00');
    app(PurchaseReconciliation::class)->assertValid($purchase);
});

test('authoritative RS line amount survives form classification saves without multiplication', function () {
    expect(reconciliationImport(['Quantity' => 3, 'Unit Price' => 0.33, 'Total' => 1, 'Document Total' => 1]))->toMatchArray(['errors' => []]);
    $purchase = Purchase::sole();
    $item = PurchaseItem::sole();
    Livewire::test(EditPurchase::class, ['record' => $purchase->id])->call('save')->assertHasNoFormErrors();
    expect($item->fresh()->line_total)->toBe('1.00')->and($purchase->fresh()->total_amount)->toBe('1.00');
    app(PurchaseReconciliation::class)->assertValid($purchase->fresh());
});

test('document mismatch blocks every new accounting confirmation atomically', function (string $path) {
    $result = reconciliationImport(['Document Total' => 551]);
    expect($result['errors'])->not->toBeEmpty();
    $purchase = Purchase::sole();
    if ($path === 'cash') {
        $confirm = fn () => app(PurchaseCashPayment::class)->post($purchase->id, $this->owner);
    } elseif ($path === 'finance') {
        $confirm = fn () => app(FinanceManager::class)->create(['purchase_id' => $purchase->id, 'type' => 'expense',
            'transaction_date' => now(), 'category' => 'supplier', 'amount' => 550, 'currency' => 'GEL',
            'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    } elseif ($path === 'advance') {
        $employee = Employee::create(['first_name' => 'RS', 'last_name' => 'Buyer', 'is_active' => true]);
        $advance = app(EmployeeAdvanceService::class)->issue(['employee_id' => $employee->id, 'source' => 'other', 'amount' => 1000,
            'date' => '2026-10-08', 'posting_key' => (string) Str::uuid()], $this->owner);
        $confirm = fn () => app(EmployeeAdvanceService::class)->settlePurchase($advance->id, $purchase->id, '2026-10-15', (string) Str::uuid(), $this->owner);
    } else {
        $bank = BankTransaction::create(['transaction_date' => '2026-10-08', 'direction' => 'outflow', 'amount' => 550, 'currency' => 'GEL', 'operation_type' => 'PMD',
            'bank_category_id' => BankCategory::where('code', 'supplier_materials')->value('id'), 'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64)]);
        $confirm = fn () => app(BankPurchaseMatching::class)->confirm($bank->id, [['purchase_id' => $purchase->id, 'amount' => 550]], $this->owner);
    }
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toArray();
    expect($confirm)->toThrow(ValidationException::class);
    expect(FinanceTransaction::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0)
        ->and(EmployeeAdvanceEntry::count())->toBe(0)->and(DB::table('bank_purchase_matches')->count())->toBe(0)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toArray())->toBe($pnl);
})->with(['cash', 'advance', 'bank', 'finance']);

test('invalid shifted monetary values are rejected rather than replaced with quantity times price', function (string $field, mixed $value) {
    $result = reconciliationImport([$field => $value]);
    expect($result['failed_rows'])->toBe(1)->and($result['errors'])->not->toBeEmpty()
        ->and(Purchase::count())->toBe(0)->and(PurchaseItem::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0);
})->with([['Total', 'ჩვეულებრივი'], ['Quantity', 'CODE-110'], ['Unit Price', 'დღგ'], ['Quantity', -2]]);

test('source restoration is explicit idempotent and restricted to unconfirmed Owner documents', function () {
    reconciliationImport();
    $purchase = Purchase::sole();
    $item = PurchaseItem::sole();
    $item->update(['quantity' => 110, 'unit' => '5', 'unit_price' => 550, 'line_total' => 60500]);
    expect(fn () => app(PurchaseCashPayment::class)->post($purchase->id, $this->owner))->toThrow(ValidationException::class);
    Livewire::test(EditPurchase::class, ['record' => $purchase->id])->callAction('refreshRsSource')->assertHasNoActionErrors();
    $this->importer->refreshUnconfirmed($purchase->id, $this->owner);
    expect($item->fresh()->quantity)->toBe('5.000000')->and($item->fresh()->unit)->toBe('ნაკრები')
        ->and($purchase->fresh()->total_amount)->toBe('550.00')->and(PurchaseItem::count())->toBe(1)->and(FinanceTransaction::count())->toBe(0);
    $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    expect(fn () => $this->importer->refreshUnconfirmed($purchase->id, $admin))->toThrow(HttpException::class);
    app(PurchaseCashPayment::class)->post($purchase->id, $this->owner);
    $snapshot = $purchase->fresh()->getRawOriginal();
    expect(fn () => $this->importer->refreshUnconfirmed($purchase->id, $this->owner))->toThrow(ValidationException::class);
    reconciliationImport(['Total' => 600, 'Document Total' => 600]);
    expect($purchase->fresh()->getRawOriginal())->toBe($snapshot)->and($item->fresh()->line_total)->toBe('550.00')
        ->and(FinanceTransaction::count())->toBe(1);
});

test('historical imports without raw evidence cannot create new accounting or be guessed from totals', function () {
    reconciliationImport();
    $purchase = Purchase::sole();
    $purchase->forceFill(['rs_import_payload' => null])->saveQuietly();
    expect(fn () => app(PurchaseCashPayment::class)->post($purchase->id, $this->owner))->toThrow(ValidationException::class)
        ->and(fn () => $this->importer->refreshUnconfirmed($purchase->id, $this->owner))->toThrow(ValidationException::class)
        ->and(FinanceTransaction::count())->toBe(0);
});

test('document total uses a one tetri rounding tolerance without replacing source amounts', function (float $total, bool $valid) {
    $result = reconciliationImport(['Document Total' => $total]);
    expect($result['errors'] === [])->toBe($valid);
    expect(Purchase::sole()->total_amount)->toBe('550.00')->and(PurchaseItem::sole()->line_total)->toBe('550.00');
    if ($valid) {
        app(PurchaseReconciliation::class)->assertValid(Purchase::sole());
    } else {
        expect(fn () => app(PurchaseCashPayment::class)->post(Purchase::sole()->id, $this->owner))->toThrow(ValidationException::class);
        expect(FinanceTransaction::count())->toBe(0);
    }
})->with([[550.01, true], [549.99, true], [550.02, false]]);

test('an incomplete import and a partial document with invalid rows cannot be confirmed', function () {
    $path = reconciliationCsv();
    $file = fopen($path, 'a');
    fputcsv($file, ['2026-10-08', 'Fixture supplier', 'BAD', 'Invalid row', 'not-a-quantity', 'pcs', 1, 1, 0, 'REAL-RS-1', 550], escape: '');
    fclose($file);
    try {
        expect($this->importer->import($path)['failed_rows'])->toBe(1);
    } finally {
        unlink($path);
    }
    $purchase = Purchase::sole();
    expect(fn () => app(PurchaseCashPayment::class)->post($purchase->id, $this->owner))->toThrow(ValidationException::class);
    $payload = $purchase->rs_import_payload;
    $payload['validation_errors'] = [];
    $payload['validated'] = false;
    $purchase->forceFill(['rs_import_payload' => $payload])->saveQuietly();
    expect(fn () => app(PurchaseCashPayment::class)->post($purchase->id, $this->owner))->toThrow(ValidationException::class)
        ->and(FinanceTransaction::count())->toBe(0);
});

test('bank confirmed source history is not refreshed or rewritten by later imports', function () {
    reconciliationImport();
    $purchase = Purchase::sole();
    $bank = BankTransaction::create(['transaction_date' => '2026-10-08', 'direction' => 'outflow', 'amount' => 550, 'currency' => 'GEL', 'operation_type' => 'PMD',
        'bank_category_id' => BankCategory::where('code', 'supplier_materials')->value('id'), 'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64)]);
    app(BankPurchaseMatching::class)->confirm($bank->id, [['purchase_id' => $purchase->id, 'amount' => 550]], $this->owner);
    $snapshot = $purchase->fresh()->getRawOriginal();
    expect(fn () => $this->importer->refreshUnconfirmed($purchase->id, $this->owner))->toThrow(ValidationException::class);
    expect(reconciliationImport(['Total' => 600, 'Document Total' => 600])['errors'])->not->toBeEmpty();
    expect($purchase->fresh()->getRawOriginal())->toBe($snapshot)->and(PurchaseItem::sole()->line_total)->toBe('550.00')
        ->and(DB::table('bank_purchase_matches')->sole()->amount)->toBe(550)
        ->and(FinanceTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(1);
});

test('optional original export reproduces the real document safely in memory', function () {
    $path = getenv('RS_MAPPING_SAMPLE');
    if (! $path) {
        $this->markTestSkipped('Set RS_MAPPING_SAMPLE to check the private original export in memory.');
    }
    $result = $this->importer->import($path);
    // The separate source return has negative quantity/price and must not be
    // silently clamped into a positive purchase. It does not taint this document.
    expect($result['errors'])->toHaveCount(1)->and($result['errors'][0])->toContain('Invalid RS quantity');
    $purchase = Purchase::where('document_number', '1013032128')->sole();
    expect($purchase->total_amount)->toBe('3052.00')->and($purchase->items()->count())->toBe(7)
        ->and(Purchase::where('document_number', 'აქტიური')->count())->toBe(0)->and(FinanceTransaction::count())->toBe(0);
    app(PurchaseReconciliation::class)->assertValid($purchase);
});
