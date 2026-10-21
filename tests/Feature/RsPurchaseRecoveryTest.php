<?php

use App\Filament\Actions\RecoverPurchaseFromFile;
use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvanceEntry;
use App\Models\FinanceTransaction;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProductGroup;
use App\Models\Supplier;
use App\Models\User;
use App\Services\EmployeeAdvanceService;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use App\Services\PurchaseReconciliation;
use App\Services\PurchaseRecovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-15 12:00:00');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->path = base_path('tests/Fixtures/rs-unquoted-description.csv');
    $this->service = app(PurchaseRecovery::class);
    $this->legacy = recoveryLegacyPurchase();
});

function recoveryLegacyPurchase(): Purchase
{
    $supplier = Supplier::create(['name' => 'ტრანსპორტირებით']);
    $purchase = Purchase::forceCreate(['id' => 33, 'source' => 'rs', 'supplier_id' => $supplier->id, 'purchase_date' => '2026-10-08',
        'document_number' => 'აქტიური', 'source_document_id' => 'აქტიური', 'import_batch_id' => (string) Str::uuid()]);
    foreach ([['ნაკრები', 110, '5', 550, 60500], ['ცალი', 1, '200', 200, 200]] as [$name, $quantity, $unit, $price, $total]) {
        $product = app(PurchaseCatalog::class)->resolve($supplier->id, $name);
        $purchase->items()->create(['purchase_product_id' => $product->id, 'item_name' => $name, 'quantity' => $quantity,
            'unit' => $unit, 'unit_price' => $price, 'line_total' => $total, 'source_row_hash' => 'v2:'.substr(hash('sha256', $name), 0, 61)]);
    }

    return $purchase->fresh();
}

function recoveryOverlap(): Purchase
{
    $source = app(PurchaseImportService::class)->recoverySource(base_path('tests/Fixtures/rs-unquoted-description.csv'), '1013032128');
    $supplier = Supplier::create(['name' => $source['supplier']]);
    $purchase = Purchase::forceCreate(['id' => 34, 'source' => 'rs', 'supplier_id' => $supplier->id, 'purchase_date' => $source['date'],
        'document_number' => $source['document_number'], 'source_document_id' => $source['source_id'], 'import_batch_id' => (string) Str::uuid()]);
    foreach ($source['rows'] as $hash => $data) {
        if (in_array($data['rs_code'], ['TM033B-4', 'SH2622'])) {
            continue;
        }
        $product = app(PurchaseCatalog::class)->resolve($supplier->id, $data['product'], $data['rs_code'], $data['supplier_code']);
        $purchase->items()->create([...$data['mapped'], 'purchase_product_id' => $product->id, 'source_row_hash' => $hash]);
    }

    return $purchase->fresh();
}

function recoverySnapshot(): array
{
    return collect(['purchases', 'purchase_items', 'purchase_products', 'suppliers', 'finance_transactions', 'cashbox_transactions', 'bank_transactions', 'bank_purchase_matches', 'employee_advance_entries'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

test('legacy phantom is previewed without writes and recovered from authoritative source under its own identity', function () {
    $before = recoverySnapshot();
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toArray();
    $preview = $this->service->preview(33, $this->path, '1013032128', $this->owner);
    expect(recoverySnapshot())->toBe($before)->and($preview['current_total'])->toBe('60700.00')
        ->and($preview['source']['total'])->toBe('3052.00')->and($preview['source']['rows'])->toHaveCount(7);
    $key = (string) Str::uuid();
    $purchase = $this->service->recover(33, $this->path, '1013032128', $preview['fingerprint'], $key, $this->owner);
    expect($purchase->id)->toBe(33)->and($purchase->document_number)->toBe('1013032128')->and($purchase->source_document_id)->toBe('1013032128')
        ->and($purchase->total_amount)->toBe('3052.00')->and(Purchase::count())->toBe(1)->and($purchase->items()->count())->toBe(7);
    $silicone = $purchase->items()->whereHas('purchaseProduct', fn ($q) => $q->where('rs_product_code', 'TM033B-4'))->sole();
    expect($silicone->quantity)->toBe('5.000000')->and($silicone->unit)->toBe('ნაკრები')->and($silicone->unit_price)->toBe('110.00')->and($silicone->line_total)->toBe('550.00')
        ->and($silicone->item_name)->toBe('სილიკონი - FLESIL (400Gx2; 50mlX2; )');
    app(PurchaseReconciliation::class)->assertValid($purchase);
    $after = recoverySnapshot();
    expect($this->service->recover(33, $this->path, '1013032128', $preview['fingerprint'], $key, $this->owner)->id)->toBe(33)
        ->and(recoverySnapshot())->toBe($after)
        ->and(DB::table('finance_transactions')->count())->toBe(0)->and(DB::table('cashbox_transactions')->count())->toBe(0)
        ->and(DB::table('bank_transactions')->count())->toBe(0)->and(EmployeeAdvanceEntry::count())->toBe(0)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toArray())->toBe($pnl);
    expect(app(PurchaseImportService::class)->import($this->path))->toMatchArray(['imported' => 0, 'skipped' => 7, 'errors' => []]);
    expect(Purchase::count())->toBe(1)->and(PurchaseItem::count())->toBe(7);
});

test('existing partial document is explicitly previewed consolidated and keeps exact product classifications', function () {
    $overlap = recoveryOverlap();
    $ids = $overlap->items()->pluck('id')->all();
    $product = $overlap->items()->first()->purchaseProduct;
    $direction = app(ExpenseDimensions::class)->id('direction', 'general');
    $type = app(ExpenseDimensions::class)->id('type', 'other', $direction);
    app(PurchaseCatalog::class)->assignClassification($product, $direction, $type);
    $group = PurchaseProductGroup::create(['name' => 'Recovery exact group']);
    $product->update(['purchase_product_group_id' => $group->id]);
    $before = recoverySnapshot();
    $preview = $this->service->preview(33, $this->path, '1013032128', $this->owner);
    expect($preview['consolidate_ids'])->toBe([34])->and(recoverySnapshot())->toBe($before);
    $purchase = $this->service->recover(33, $this->path, '1013032128', $preview['fingerprint'], (string) Str::uuid(), $this->owner);
    expect(Purchase::count())->toBe(1)->and($purchase->items()->whereIn('id', $ids)->count())->toBe(5)
        ->and($product->fresh()->only(['expense_direction_id', 'expense_type_id', 'purchase_product_group_id']))
        ->toBe(['expense_direction_id' => $direction, 'expense_type_id' => $type, 'purchase_product_group_id' => $group->id])
        ->and($purchase->rs_import_payload['recovery']['consolidated_purchase_ids'])->toBe([34])
        ->and($purchase->rs_import_payload['recovery_history'][0]['before'])->toHaveCount(2)
        ->and($purchase->total_amount)->toBe('3052.00');
});

test('unmatched legacy classifications are not guessed from names or units', function () {
    $old = $this->legacy->items()->first()->purchaseProduct;
    $old->update(['name' => 'სილიკონი - FLESIL (400Gx2; 50mlX2; )']);
    app(PurchaseCatalog::class)->assignDirection($old, app(ExpenseDimensions::class)->id('direction', 'surgery'));
    $preview = $this->service->preview(33, $this->path, '1013032128', $this->owner);
    $purchase = $this->service->recover(33, $this->path, '1013032128', $preview['fingerprint'], (string) Str::uuid(), $this->owner);
    expect($purchase->items()->whereHas('purchaseProduct', fn ($q) => $q->whereNotNull('expense_direction_id'))->count())->toBe(0)
        ->and($old->fresh()->expense_direction_id)->not->toBeNull();
});

test('accounting blockers report exact records on both target and overlapping document', function (string $kind, bool $overlap) {
    $purchase = $overlap ? recoveryOverlap() : $this->legacy;
    if ($kind === 'finance') {
        $record = FinanceTransaction::create(['purchase_id' => $purchase->id, 'type' => 'expense', 'category' => 'supplier', 'amount' => 550,
            'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => today()]);
        $expected = 'Finance #'.$record->id;
    } elseif ($kind === 'bank') {
        $record = BankTransaction::create(['transaction_date' => today(), 'direction' => 'outflow', 'amount' => 550, 'currency' => 'GEL',
            'operation_type' => 'PMD', 'bank_category_id' => BankCategory::where('code', 'supplier_materials')->value('id'),
            'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64)]);
        $purchase->bankTransactions()->attach($record->id, ['amount' => 550, 'confirmed_by' => $this->owner->id]);
        $expected = 'Bank transaction/allocation #'.$record->id;
    } else {
        $employee = Employee::create(['first_name' => 'Recovery', 'last_name' => 'Recipient', 'is_active' => true]);
        $advance = app(EmployeeAdvanceService::class)->issue(['employee_id' => $employee->id, 'source' => 'other', 'amount' => 1000,
            'date' => today()->toDateString(), 'posting_key' => (string) Str::uuid()], $this->owner);
        $record = $advance->entries()->create(['kind' => 'rs', 'amount' => 550, 'purchase_id' => $purchase->id, 'expense_date' => today(),
            'posting_key' => (string) Str::uuid(), 'created_by' => $this->owner->id, 'description' => 'Historical RS confirmation']);
        $expected = 'Advance settlement #'.$record->id;
    }
    $snapshot = recoverySnapshot();
    expect(fn () => $this->service->preview(33, $this->path, '1013032128', $this->owner))->toThrow(ValidationException::class, $expected);
    expect(recoverySnapshot())->toBe($snapshot);
})->with(['finance', 'bank', 'advance'])->with([false, true]);

test('changed target overlap or source after preview is rejected unchanged', function (string $change) {
    $overlap = recoveryOverlap();
    $path = tempnam(sys_get_temp_dir(), 'rs-recovery-').'.csv';
    copy($this->path, $path);
    try {
        $preview = $this->service->preview(33, $path, '1013032128', $this->owner);
        if ($change === 'source') {
            file_put_contents($path, str_replace(',110,550,', ',110,551,', file_get_contents($path)));
        } else {
            ($change === 'target' ? $this->legacy : $overlap)->update(['notes' => 'Changed after preview']);
        }
        $snapshot = recoverySnapshot();
        expect(fn () => $this->service->recover(33, $path, '1013032128', $preview['fingerprint'], (string) Str::uuid(), $this->owner))->toThrow(ValidationException::class);
        expect(recoverySnapshot())->toBe($snapshot);
    } finally {
        unlink($path);
    }
})->with(['target', 'overlap', 'source']);

test('failed final reconciliation rolls back line replacement consolidation and supplier correction', function () {
    recoveryOverlap();
    $preview = $this->service->preview(33, $this->path, '1013032128', $this->owner);
    $snapshot = recoverySnapshot();
    $guard = Mockery::mock(PurchaseReconciliation::class);
    $guard->shouldReceive('assertValid')->once()->andThrow(ValidationException::withMessages(['items' => 'Simulated reconciliation failure']));
    app()->instance(PurchaseReconciliation::class, $guard);
    expect(fn () => $this->service->recover(33, $this->path, '1013032128', $preview['fingerprint'], (string) Str::uuid(), $this->owner))->toThrow(ValidationException::class);
    expect(recoverySnapshot())->toBe($snapshot);
});

test('Owner wizard displays the parsed preview before replacing staging records', function () {
    Storage::fake('local');
    recoveryOverlap();
    $snapshot = recoverySnapshot();
    $upload = UploadedFile::fake()->createWithContent('source.csv', file_get_contents($this->path))->mimeType('text/csv');
    $page = Livewire::test(EditPurchase::class, ['record' => 33])->mountAction('reimportRs')
        ->setActionData(['file' => $upload, 'document_number' => '1013032128'])->goToNextWizardStep()->assertHasNoErrors()
        ->assertSet('rsRecoveryPreview.source.total', '3052.00');
    // Action modals are delivered in Filament partials rather than the page HTML.
    $component = $page->instance();
    $html = $component->{$component->getMountedActionSchemaName()}->toHtml();
    expect($html)->toContain('60,700.00', '3,052.00', 'TM033B-4', 'SH2622', 'data-rs-recovery-preview');
    expect(recoverySnapshot())->toBe($snapshot);
    $page->setActionData(['confirm_recovery' => false])->callMountedAction()->assertHasActionErrors();
    expect(recoverySnapshot())->toBe($snapshot);
    $page->setActionData(['confirm_recovery' => true])->callMountedAction()->assertHasNoActionErrors();
    expect(Purchase::findOrFail(33)->total_amount)->toBe('3052.00')->and(Purchase::count())->toBe(1)
        ->and(FinanceTransaction::count())->toBe(0);
});

test('non-owner cannot access the recovery UI or backend', function (string $role) {
    $user = User::factory()->create(['role' => $role]);
    $snapshot = recoverySnapshot();
    expect(fn () => $this->service->preview(33, $this->path, '1013032128', $user))->toThrow(HttpException::class)
        ->and(fn () => $this->service->recover(33, $this->path, '1013032128', 'forged', (string) Str::uuid(), $user))->toThrow(HttpException::class);
    Livewire::actingAs($user)->test(EditPurchase::class, ['record' => 33])->assertForbidden();
    expect(recoverySnapshot())->toBe($snapshot);
})->with([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('forged upload paths and final submissions without preview cannot replace a purchase', function () {
    Storage::fake('local');
    $page = Livewire::test(EditPurchase::class, ['record' => 33])->mountAction('reimportRs');
    expect(fn () => RecoverPurchaseFromFile::uploadPath($page->instance(), '../.env'))->toThrow(ValidationException::class);
    $upload = UploadedFile::fake()->createWithContent('source.csv', file_get_contents($this->path))->mimeType('text/csv');
    $snapshot = recoverySnapshot();
    $page->setActionData(['file' => $upload, 'document_number' => '1013032128', 'confirm_recovery' => true])
        ->callMountedAction()->assertHasActionErrors();
    expect(recoverySnapshot())->toBe($snapshot);
});

test('a new accounting confirmation after preview blocks recovery with exact record IDs', function () {
    $preview = $this->service->preview(33, $this->path, '1013032128', $this->owner);
    $posting = FinanceTransaction::create(['purchase_id' => 33, 'type' => 'expense', 'category' => 'supplier', 'amount' => 550,
        'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'transaction_date' => today()]);
    $snapshot = recoverySnapshot();
    expect(fn () => $this->service->recover(33, $this->path, '1013032128', $preview['fingerprint'], (string) Str::uuid(), $this->owner))
        ->toThrow(ValidationException::class, 'Finance #'.$posting->id);
    expect(recoverySnapshot())->toBe($snapshot);
});

test('source without authoritative amounts malformed fragments and explicit total mismatch are rejected', function (string $problem) {
    $path = tempnam(sys_get_temp_dir(), 'rs-recovery-invalid-').'.csv';
    $contents = file_get_contents($this->path);
    if ($problem === 'fragment') {
        $contents = str_replace(',ნაკრები,5,110,550,', ',ნაკრები,5,110,', $contents);
    } elseif ($problem === 'orphan') {
        $contents = preg_replace('/^TM033B-4,[^\n]*\n/m', '', $contents);
    } elseif ($problem === 'amount') {
        $contents = str_replace(',110,550,', ',110,not-money,', $contents);
    } elseif ($problem === 'missing') {
        $contents = str_replace(',110,550,', ',110,,', $contents);
    } elseif ($problem === 'missing_price') {
        $contents = str_replace(',110,550,', ',,550,', $contents);
    } elseif ($problem === 'date') {
        $contents = str_replace('08-ოქტ-2026', '31-თებ-2026', $contents);
    } else {
        $lines = explode("\n", trim($contents));
        $lines[0] .= ',Document Total';
        foreach ($lines as $index => &$line) {
            if ($index > 0 && ! str_starts_with($line, 'TM033B-4,') && ! str_starts_with($line, 'SH2622,')) {
                $line .= ',9999';
            }
        }
        unset($line);
        $contents = implode("\n", $lines);
    }
    file_put_contents($path, $contents);
    $snapshot = recoverySnapshot();
    try {
        expect(fn () => $this->service->preview(33, $path, '1013032128', $this->owner))->toThrow(ValidationException::class);
        expect(recoverySnapshot())->toBe($snapshot);
    } finally {
        unlink($path);
    }
})->with(['fragment', 'orphan', 'amount', 'missing', 'missing_price', 'date', 'document_total']);

test('overlapping document with an unmatched extra line cannot be discarded during recovery', function () {
    $overlap = recoveryOverlap();
    $product = app(PurchaseCatalog::class)->resolve($overlap->supplier_id, 'Extra unrelated material', 'EXTRA');
    $overlap->items()->create(['purchase_product_id' => $product->id, 'quantity' => 1, 'unit_price' => 77]);
    $snapshot = recoverySnapshot();
    expect(fn () => $this->service->preview(33, $this->path, '1013032128', $this->owner))->toThrow(ValidationException::class, 'not an exact source match');
    expect(recoverySnapshot())->toBe($snapshot);
});

test('the expected example amount is never used as a recovery override', function () {
    $path = tempnam(sys_get_temp_dir(), 'rs-recovery-derived-').'.csv';
    file_put_contents($path, str_replace(',110,550,', ',111,555,', file_get_contents($this->path)));
    try {
        $preview = $this->service->preview(33, $path, '1013032128', $this->owner);
        expect($preview['source']['total'])->toBe('3057.00');
        $purchase = $this->service->recover(33, $path, '1013032128', $preview['fingerprint'], (string) Str::uuid(), $this->owner);
        expect($purchase->total_amount)->toBe('3057.00')->and(FinanceTransaction::count())->toBe(0);
    } finally {
        unlink($path);
    }
});

test('a name-only corrected line cannot inherit an existing same-name catalog classification', function () {
    $source = app(PurchaseImportService::class)->recoverySource($this->path, '1013032128');
    $supplier = Supplier::create(['name' => $source['supplier']]);
    $first = reset($source['rows']);
    $old = app(PurchaseCatalog::class)->resolve($supplier->id, $first['product']);
    app(PurchaseCatalog::class)->assignDirection($old, app(ExpenseDimensions::class)->id('direction', 'surgery'));
    $path = tempnam(sys_get_temp_dir(), 'rs-recovery-name-').'.csv';
    $file = fopen($path, 'w');
    fputcsv($file, ['Date', 'Supplier', 'Product', 'RS Product Code', 'Quantity', 'Unit', 'Unit Price', 'Total', 'Document'], escape: '');
    foreach ($source['rows'] as $data) {
        fputcsv($file, [$source['date'], $source['supplier'], $data['product'], $data['rs_code'] === 'TM033B-4' ? '' : $data['rs_code'],
            $data['quantity'], $data['unit'], $data['unit_price'], $data['total'], '1013032128'], escape: '');
    }
    fclose($file);
    try {
        $preview = $this->service->preview(33, $path, '1013032128', $this->owner);
        $purchase = $this->service->recover(33, $path, '1013032128', $preview['fingerprint'], (string) Str::uuid(), $this->owner);
        $item = $purchase->items()->where('item_name', trim($first['product']))->sole();
        expect($item->purchase_product_id)->not->toBe($old->id)->and($item->purchaseProduct->expense_direction_id)->toBeNull()
            ->and($old->fresh()->expense_direction_id)->not->toBeNull();
    } finally {
        unlink($path);
    }
});
