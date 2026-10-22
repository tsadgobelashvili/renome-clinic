<?php

// Standalone integration test for a NEW disposable cluster only. No RefreshDatabase
// or application credentials. Run with PHP -n and explicitly enabled pdo_pgsql.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Models\PurchaseItem;
use App\Models\RsImportCheckpoint;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Assert;

if (getenv('RS_DISPOSABLE_PG_TEST') !== 'yes' || getenv('DB_DATABASE') !== 'rs_checkpoint_integration'
    || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_PORT') !== '55439'
    || extension_loaded('pdo_sqlite') || extension_loaded('sqlite3')) {
    throw new RuntimeException('Requires the dedicated disposable PostgreSQL cluster and no SQLite extensions.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || DB::getDriverName() !== 'pgsql'
    || DB::connection()->getDatabaseName() !== 'rs_checkpoint_integration') {
    throw new RuntimeException('Unsafe integration test connection.');
}
if (DB::table('information_schema.tables')->where('table_schema', 'public')->exists()) {
    throw new RuntimeException('Disposable database must be empty. Refusing migration of existing data.');
}
Artisan::call('migrate', ['--force' => true]);
config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
    'patient_identifiers.hash_key' => random_bytes(32)]);
$private = storage_path('framework/testing/rs-pg-private');
Storage::set('local', Storage::build(['driver' => 'local', 'root' => $private, 'visibility' => 'private']));
Storage::set('tmp-for-tests', Storage::build(['driver' => 'local', 'root' => $private.'/temporary', 'visibility' => 'private']));
Filament::setCurrentPanel(Filament::getDefaultPanel());
Filament::bootCurrentPanel();
$owner = User::factory()->create(['role' => User::ROLE_OWNER]);
auth()->login($owner);
$tables = ['finance_transactions', 'partner_finance_transactions', 'cashbox_transactions', 'bank_transactions',
    'payments', 'employee_advances', 'employee_advance_entries', 'bank_purchase_matches'];
$snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
$before = $snapshot();
$csv = fopen('php://temp', 'w+');
fputcsv($csv, ['Date', 'Supplier', 'Product', 'Quantity', 'Unit', 'Unit Price', 'Total', 'Document', 'RS Product Code', 'Source Line ID', 'Notes'], escape: '');
for ($i = 0; $i < 1200; $i++) {
    fputcsv($csv, ['2026-01-08', 'PG Supplier', 'Material', 1, 'pcs', 1, 1, 'PG-'.intdiv($i, 100), 'CODE', 'L'.$i, str_repeat('x', 110)], escape: '');
}
fputcsv($csv, ['2026-01-08', 'PG Supplier', 'Material', 'bad', 'pcs', 1, 1, 'PG-BAD', 'CODE', 'BAD', ''], escape: '');
// Pad an unused cell, without adding records, to reproduce the 240 KiB upload.
fseek($csv, 0, SEEK_END);
$length = ftell($csv);
if ($length >= 240 * 1024) {
    throw new RuntimeException('Fixture unexpectedly exceeds 240 KiB.');
}
fseek($csv, -1, SEEK_END);
fwrite($csv, str_repeat('x', 240 * 1024 - $length)."\n");
rewind($csv);
$contents = stream_get_contents($csv);
fclose($csv);
Assert::assertSame(240 * 1024, strlen($contents));
$upload = fn () => UploadedFile::fake()->createWithContent('year-240k.csv', $contents)->mimeType('text/csv');
$page = Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => $upload()])
    ->assertHasNoActionErrors()->assertNotified('RS იმპორტი დაიწყო')->assertActionNotMounted('importRs')
    ->assertSet('rsImportProgress.phase', 'preparing')->assertSee('ფაილი შენახულია');
$id = $page->get('rsImportRun');
$checkpoint = RsImportCheckpoint::findOrFail($id);
Assert::assertSame('pgsql', $checkpoint->getConnection()->getDriverName());
Assert::assertSame('year-240k.csv', $checkpoint->original_filename);
Storage::disk('local')->assertExists($checkpoint->source_path);
session()->forget('rs-historical-import.'.$owner->id);
unset($page);
$page = Livewire::test(ListPurchases::class)->assertSet('rsImportRun', $id)->call('continueRsImport')->assertSee('500 / 1201');
$guard = 0;
while ($page->get('rsImportProgress')['phase'] !== 'completed' && ++$guard < 10) {
    $page->call('continueRsImport');
}
$page->assertSet('rsImportProgress.phase', 'completed');
Assert::assertSame(1200, PurchaseItem::count());
Assert::assertSame(1, $page->get('rsImportProgress')['failed_rows']);
Assert::assertSame($before, $snapshot());
$retry = $page->callAction('importRs', data: ['file' => $upload()])->assertHasNoActionErrors();
$guard = 0;
while ($retry->get('rsImportProgress')['phase'] !== 'completed' && ++$guard < 10) {
    $retry->call('continueRsImport');
}
Assert::assertSame(1200, PurchaseItem::count());
Assert::assertSame(1200, $retry->get('rsImportProgress')['skipped']);
Assert::assertSame($before, $snapshot());
echo json_encode(['driver' => 'pgsql', 'pdo_sqlite' => false, 'sqlite3' => false, 'upload_bytes' => strlen($contents),
    'imported' => 1200, 'duplicate_retry' => 1200, 'rejected' => 1, 'finance_unchanged' => true, 'reload_without_session' => true]).PHP_EOL;
