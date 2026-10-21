<?php

use App\Models\BankTransaction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\FinanceTransaction;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Services\PurchaseAnalysisEligibility;
use App\Services\PurchaseImportService;
use App\Services\PurchaseReconciliation;
use App\Services\RsHistoricalImport;
use App\Services\RsHistoricalRevalidation;
use App\Services\RsImportWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->importer = app(PurchaseImportService::class);
});

function revalidationRow(array $changes = []): array
{
    return array_replace(['Date' => '2026-01-08', 'Supplier' => 'Repair supplier', 'Product' => 'Material',
        'Quantity' => '0.3333', 'Unit' => 'pcs', 'Unit Price' => '540.0540', 'Total' => '180', 'Document' => 'REPAIR-A',
        'RS Product Code' => '04368', 'Status' => 'active', 'Document Type' => 'purchase', 'Waybill Type' => 'ტრანსპორტირებით'], $changes);
}

function revalidationRun(array $rows, User $owner, array $extraMalformedRows = []): array
{
    $path = tempnam(sys_get_temp_dir(), 'revalidate-rs').'.csv';
    $f = fopen($path, 'w');
    fputcsv($f, array_keys($rows[0]), escape: '');
    foreach (array_merge($rows, $extraMalformedRows) as $row) {
        fputcsv($f, array_values($row), escape: '');
    }
    fclose($f);
    try {
        $service = app(RsHistoricalImport::class);
        $id = $service->start($path, $owner);
        do {
            $result = $service->step($id, $owner);
        } while ($result['phase'] !== 'completed');

        return [$id, $result];
    } finally {
        unlink($path);
    }
}

function emulateThreeDecimalLine(Purchase $purchase, string $rounded): PurchaseItem
{
    $item = $purchase->items()->sole();
    $raw = $purchase->rs_import_payload['source_rows'][$item->source_row_hash]['raw'];
    $data = app(PurchaseImportService::class)->inspectSource($raw);
    $data['occurrence'] = 1;
    $data['quantity'] = $rounded;
    $hash = app(PurchaseImportService::class)->sourceHash($purchase, $data);
    $payload = $purchase->rs_import_payload;
    $source = $payload['source_rows'][$item->source_row_hash];
    $source['mapped']['quantity'] = $rounded;
    $payload['source_rows'] = [$hash => $source];
    DB::table('purchase_items')->where('id', $item->id)->update(['quantity' => $rounded, 'source_row_hash' => $hash]);
    $purchase->forceFill(['rs_import_payload' => $payload])->saveQuietly();

    return $item->fresh();
}

test('old three decimal quantities reuse the same logical line and retry is idempotent', function ($source, $rounded, $price, $total) {
    $row = revalidationRow(['Quantity' => $source, 'Unit Price' => $price, 'Total' => $total]);
    revalidationRun([$row], $this->owner);
    $purchase = Purchase::sole();
    $old = emulateThreeDecimalLine($purchase, $rounded);
    [, $retry] = revalidationRun([$row], $this->owner);
    expect($retry)->toMatchArray(['imported' => 0, 'skipped' => 1, 'failed_rows' => 0]);
    expect($purchase->items()->sole()->id)->toBe($old->id)
        ->and($old->fresh()->quantity)->toBe($source.'00')
        ->and($purchase->fresh()->total_amount)->toBe(number_format($total, 2, '.', ''));
    [, $again] = revalidationRun([$row], $this->owner);
    expect($again['imported'])->toBe(0)->and(PurchaseItem::count())->toBe(1);
    app(PurchaseReconciliation::class)->assertValid($purchase->fresh());
})->with([['0.3333', '0.333', '540.0540', 180], ['0.1667', '0.167', '540', 90]]);

test('retained contrary source quantity prevents precision merging', function () {
    revalidationRun([revalidationRow(['Quantity' => '0.333'])], $this->owner);
    [, $result] = revalidationRun([revalidationRow()], $this->owner);
    expect($result['imported'])->toBe(1)->and(PurchaseItem::count())->toBe(2);
});

test('different quantities outside the old precision rule remain distinct', function () {
    revalidationRun([revalidationRow(['Quantity' => '0.332'])], $this->owner);
    [, $result] = revalidationRun([revalidationRow()], $this->owner);
    expect($result['imported'])->toBe(1)->and(PurchaseItem::count())->toBe(2);
});

test('waybill modes have their own field and cannot masquerade as accounting document type', function ($label) {
    [, $result] = revalidationRun([revalidationRow(['Waybill Type' => $label])], $this->owner);
    expect($result['review_rows'])->toBe(0)->and(Purchase::sole()->rs_import_payload['document_kind'])->toBe('purchase')
        ->and(app(PurchaseAnalysisEligibility::class)->documents()->count())->toBe(1);
    $raw = array_values(Purchase::sole()->rs_import_payload['source_rows'])[0]['raw'];
    $mapped = $this->importer->inspectSource($raw);
    expect($mapped['waybill_type'])->toBe($label)->and($mapped['document_type'])->toBe('purchase');
    [, $wrongField] = revalidationRun([revalidationRow(['Document' => 'WRONG-FIELD', 'Document Type' => $label])], $this->owner);
    expect($wrongField['review_rows'])->toBe(1);
})->with(['ტრანსპორტირებით', 'ტრანსპორტირების გარეშე', 'ქვე-ზედნადები']);

test('attributable malformed quantities block only their document and unattributed errors stay file level', function () {
    [$run, $result] = revalidationRun([
        revalidationRow(), revalidationRow(['Quantity' => 'bad number']),
        revalidationRow(['Document' => 'REPAIR-B']), revalidationRow(['Document' => 'REPAIR-C']),
    ], $this->owner, [['unattributed', 'broken', 'row']]);
    expect($result['failed_rows'])->toBe(2);
    $eligible = Purchase::whereIn('id', app(PurchaseAnalysisEligibility::class)->documents())->pluck('document_number')->all();
    expect($eligible)->toEqualCanonicalizing(['REPAIR-B', 'REPAIR-C']);
    $plan = app(RsHistoricalRevalidation::class)->preview($run, $this->owner);
    expect($plan['file_errors'])->toHaveCount(1)->and($plan['review'])->toHaveKey(Purchase::where('document_number', 'REPAIR-A')->sole()->id);
    $report = Storage::disk('local')->get('purchase-import-reports/'.$result['error_report_id'].'.jsonl');
    expect($report)->toContain('bad number', 'unattributed');
});

test('dry run repairs nothing and apply removes only an obsolete precision duplicate with audit and no finance effect', function () {
    [$run] = revalidationRun([revalidationRow()], $this->owner);
    $purchase = Purchase::sole();
    $precise = $purchase->items()->sole()->getAttributes();
    $preciseSource = $purchase->rs_import_payload['source_rows'][$precise['source_row_hash']];
    $old = emulateThreeDecimalLine($purchase, '0.333');
    $new = $purchase->items()->create(collect($precise)->except(['id', 'created_at', 'updated_at'])->all());
    $payload = $purchase->fresh()->rs_import_payload;
    $payload['source_rows'][$new->source_row_hash] = $preciseSource;
    $payload['document_kind'] = 'review';
    $payload['validation_errors'] = ['Row 99: RS row width does not match its headers. Check CSV quoting/columns.'];
    $payload['validated'] = false;
    $purchase->forceFill(['rs_import_payload' => $payload])->saveQuietly();
    // The original checkpoint retains this formerly global error; no raw report is rewritten.
    $work = new RsImportWorkspace($run);
    $work->issue('*', $payload['validation_errors'][0]);
    FinanceTransaction::create(['type' => 'expense', 'category' => 'rent', 'transaction_date' => now(), 'amount' => 25, 'currency' => 'GEL', 'payment_method' => 'bank_transfer']);
    $tables = ['purchases', 'purchase_items', 'purchase_products', 'finance_transactions', 'cashbox_transactions', 'bank_transactions', 'employee_advances', 'employee_advance_entries', 'bank_purchase_matches'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($t) => [$t => DB::table($t)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $service = app(RsHistoricalRevalidation::class);
    $plan = $service->preview($run, $this->owner);
    expect($snapshot())->toBe($before)->and($plan['changes'])->toHaveCount(1)
        ->and($plan['changes'][0]['remove'])->toBe([$old->id]);
    $service->apply($run, $this->owner, $plan['fingerprint']);
    expect($purchase->items()->sole()->id)->toBe($new->id)->and($purchase->fresh()->total_amount)->toBe('180.00')
        ->and($purchase->fresh()->rs_import_payload['historical_revalidations'][0]['removed_items'][0]['item']['id'])->toBe($old->id)
        ->and(app(PurchaseAnalysisEligibility::class)->documents()->count())->toBe(1);
    foreach (array_diff($tables, ['purchases', 'purchase_items']) as $table) {
        expect($snapshot()[$table])->toBe($before[$table]);
    }
    $next = $service->preview($run, $this->owner);
    expect($next['changes'])->toBe([])->and($next['unchanged'])->toBe([$purchase->id]);
    $after = $snapshot();
    $service->apply($run, $this->owner, $next['fingerprint']);
    expect($snapshot())->toBe($after);
});

test('accounted documents are never repaired even through explicit apply', function () {
    [$run] = revalidationRun([revalidationRow()], $this->owner);
    $purchase = Purchase::sole();
    emulateThreeDecimalLine($purchase, '0.333');
    FinanceTransaction::create(['type' => 'expense', 'category' => 'materials', 'transaction_date' => now(), 'amount' => 180,
        'currency' => 'GEL', 'payment_method' => 'bank_transfer', 'purchase_id' => $purchase->id]);
    $service = app(RsHistoricalRevalidation::class);
    $plan = $service->preview($run, $this->owner);
    expect($plan['changes'])->toBe([])->and($plan['review'])->toHaveKey($purchase->id);
    $service->apply($run, $this->owner, $plan['fingerprint']);
    expect($purchase->items()->sole()->quantity)->toBe('0.333000');
});

test('bank and advance links independently prevent precision repair', function ($link) {
    [$run] = revalidationRun([revalidationRow()], $this->owner);
    $purchase = Purchase::sole();
    emulateThreeDecimalLine($purchase, '0.333');
    if ($link === 'bank') {
        $bank = BankTransaction::create(['transaction_date' => today(), 'direction' => 'outflow', 'amount' => 180, 'currency' => 'GEL',
            'source' => 'api', 'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64)]);
        $purchase->bankTransactions()->attach($bank->id, ['amount' => 180, 'confirmed_by' => $this->owner->id]);
    } else {
        $employee = Employee::create(['first_name' => 'Buyer', 'last_name' => 'Test', 'is_active' => true]);
        $advance = EmployeeAdvance::create(['employee_id' => $employee->id, 'posting_key' => (string) Str::uuid(),
            'amount' => 180, 'date' => today(), 'source' => 'other', 'created_by' => $this->owner->id]);
        DB::table('employee_advance_entries')->insert(['posting_key' => (string) Str::uuid(), 'employee_advance_id' => $advance->id,
            'kind' => 'rs', 'expense_date' => today(), 'amount' => 180, 'purchase_id' => $purchase->id, 'description' => 'RS fixture', 'created_by' => $this->owner->id,
            'created_at' => now(), 'updated_at' => now()]);
    }
    $service = app(RsHistoricalRevalidation::class);
    $plan = $service->preview($run, $this->owner);
    expect($plan['changes'])->toBe([])->and($plan['review'])->toHaveKey($purchase->id);
    $service->apply($run, $this->owner, $plan['fingerprint']);
    expect($purchase->items()->sole()->quantity)->toBe('0.333000');
})->with(['bank', 'advance']);

test('precision duplicate repair covers 0.1667 and preserves the correct current line identity', function () {
    [$run] = revalidationRun([revalidationRow(['Quantity' => '0.1667', 'Unit Price' => '540', 'Total' => '90'])], $this->owner);
    $p = Purchase::sole();
    $precise = $p->items()->sole()->getAttributes();
    $source = $p->rs_import_payload['source_rows'][$precise['source_row_hash']];
    $old = emulateThreeDecimalLine($p, '0.167');
    $new = $p->items()->create(collect($precise)->except(['id', 'created_at', 'updated_at'])->all());
    $payload = $p->fresh()->rs_import_payload;
    $payload['source_rows'][$new->source_row_hash] = $source;
    $p->forceFill(['rs_import_payload' => $payload])->saveQuietly();
    $service = app(RsHistoricalRevalidation::class);
    $plan = $service->preview($run, $this->owner);
    expect($plan['changes'][0]['remove'])->toBe([$old->id]);
    $service->apply($run, $this->owner, $plan['fingerprint']);
    expect($p->items()->sole()->id)->toBe($new->id)->and($p->fresh()->total_amount)->toBe('90.00');
});

test('command requires a dry-run fingerprint and supports a no-op apply', function () {
    [$run] = revalidationRun([revalidationRow()], $this->owner);
    $this->artisan('purchases:revalidate-rs', ['--import' => $run, '--owner' => $this->owner->id, '--dry-run' => true])
        ->expectsOutputToContain('DRY RUN: no records changed.')->assertSuccessful();
    $this->artisan('purchases:revalidate-rs', ['--import' => $run, '--owner' => $this->owner->id, '--apply' => true])->assertFailed();
    $plan = app(RsHistoricalRevalidation::class)->preview($run, $this->owner);
    $this->artisan('purchases:revalidate-rs', ['--import' => $run, '--owner' => $this->owner->id, '--apply' => true, '--fingerprint' => $plan['fingerprint']])
        ->expectsOutputToContain('APPLY completed atomically.')->assertSuccessful();
});

test('explicit source line IDs retain their identity through a proven precision correction', function () {
    $row = revalidationRow(['Source Line ID' => 'EXPLICIT-1']);
    revalidationRun([$row], $this->owner);
    $p = Purchase::sole();
    $old = emulateThreeDecimalLine($p, '0.333');
    [, $result] = revalidationRun([$row], $this->owner);
    expect($result)->toMatchArray(['imported' => 0, 'skipped' => 1, 'failed_rows' => 0]);
    expect($p->items()->sole()->id)->toBe($old->id)->and($old->fresh()->quantity)->toBe('0.333300');
});

test('legacy negative purchase is excluded and retained without inferring cancellation', function () {
    revalidationRun([revalidationRow()], $this->owner);
    $p = Purchase::create(['source' => 'rs', 'supplier_id' => Purchase::sole()->supplier_id, 'purchase_date' => '2026-09-30', 'document_number' => 'NEGATIVE', 'total_amount' => -4728.65]);
    expect(app(PurchaseAnalysisEligibility::class)->documents()->pluck('id')->all())->not->toContain($p->id);
    expect($p->fresh()->total_amount)->toBe('-4728.65')->and($p->fresh()->rs_import_payload)->toBeNull();
});

test('cancelled and unsupported credit remain excluded after revalidation', function () {
    [$run] = revalidationRun([revalidationRow(['Status' => 'cancelled']), revalidationRow(['Document' => 'CREDIT', 'Document Type' => 'credit', 'Quantity' => -1, 'Total' => -180])], $this->owner);
    $service = app(RsHistoricalRevalidation::class);
    $plan = $service->preview($run, $this->owner);
    $service->apply($run, $this->owner, $plan['fingerprint']);
    expect(app(PurchaseAnalysisEligibility::class)->documents()->count())->toBe(0)->and(Purchase::count())->toBe(2);
});

test('non-owners cannot preview apply or invoke the command', function () {
    [$run] = revalidationRun([revalidationRow()], $this->owner);
    $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    $service = app(RsHistoricalRevalidation::class);
    expect(fn () => $service->preview($run, $admin))->toThrow(HttpException::class);
    expect(fn () => $service->apply($run, $admin, 'invalid'))->toThrow(HttpException::class);
    $this->artisan('purchases:revalidate-rs', ['--import' => $run, '--owner' => $admin->id, '--dry-run' => true])->assertFailed();
});

test('stale dry run fingerprint cancels apply without partial writes', function () {
    [$run] = revalidationRun([revalidationRow()], $this->owner);
    $service = app(RsHistoricalRevalidation::class);
    $plan = $service->preview($run, $this->owner);
    Purchase::sole()->update(['notes' => 'Concurrent edit']);
    expect(fn () => $service->apply($run, $this->owner, $plan['fingerprint']))->toThrow(DomainException::class);
});
