<?php

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\RsImportCheckpoint;
use App\Models\User;
use App\Services\RsHistoricalImport;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
});

function checkpointUpload(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('year.csv', file_get_contents(base_path('tests/fixtures/rs-items-georgian.csv')))->mimeType('text/csv');
}

test('a database checkpoint recovers without a session and isolates source progress and reports', function () {
    $page = Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => checkpointUpload()])
        ->assertHasNoActionErrors()->assertActionNotMounted('importRs');
    $id = $page->get('rsImportRun');
    $checkpoint = RsImportCheckpoint::findOrFail($id);
    expect($checkpoint->owner_id)->toBe($this->owner->id)->and($checkpoint->original_filename)->toBe('year.csv')
        ->and($checkpoint->file_hash)->toBe(hash_file('sha256', base_path('tests/fixtures/rs-items-georgian.csv')))
        ->and($checkpoint->metadata)->not->toHaveKey('inputs');
    expect(Storage::disk('local')->allFiles('purchase-import-workspaces'))->not->toContain('purchase-import-workspaces/'.$id.'.sqlite');
    session()->flush();
    Livewire::test(ListPurchases::class)->assertSet('rsImportRun', $id)->call('continueRsImport')->assertSet('rsImportProgress.phase', 'completed');
    expect($checkpoint->fresh()->completed_at)->not->toBeNull()->and(PurchaseItem::count())->toBe(3);
    $other = User::factory()->create(['role' => User::ROLE_OWNER]);
    expect(fn () => app(RsHistoricalImport::class)->status($id, $other))->toThrow(HttpException::class);
    $this->actingAs($other);
    Livewire::test(ListPurchases::class)->assertSet('rsImportRun', null);
    $report = $checkpoint->metadata['summary']['error_report_id'];
    $this->get(route('purchases.import-report', $report))->assertForbidden();
});

test('database creation failure preserves the Livewire upload and the same modal can retry', function () {
    $page = Livewire::test(ListPurchases::class)->mountAction('importRs')->setActionData(['file' => checkpointUpload()]);
    $temporary = array_values($page->get('mountedActions')[0]['data']['file'])[0];
    expect($temporary)->toBeInstanceOf(TemporaryUploadedFile::class);
    $event = 'eloquent.creating: '.RsImportCheckpoint::class;
    Event::listen($event, fn () => throw new RuntimeException('Synthetic PostgreSQL checkpoint failure'));
    try {
        $page->callMountedAction()->assertNotified('იმპორტის დაწყება ვერ მოხერხდა')->assertSet('rsImportRun', null);
        expect($temporary->exists())->toBeTrue()->and(RsImportCheckpoint::count())->toBe(0)->and(Purchase::count())->toBe(0);
    } finally {
        Event::forget($event);
    }
    $page->callMountedAction()->assertNotified('RS იმპორტი დაიწყო')->assertActionNotMounted('importRs');
    expect(RsImportCheckpoint::count())->toBe(1)->and($temporary->exists())->toBeFalse();
});

test('legacy disk checkpoints are ignored without migrating or changing purchase data', function () {
    $legacy = (string) Str::uuid();
    Storage::disk('local')->put('purchase-import-workspaces/'.$legacy.'.sqlite', 'legacy operational state');
    session(['rs-historical-import.'.$this->owner->id => $legacy]);
    $before = DB::table('purchases')->get()->toJson();
    Livewire::test(ListPurchases::class)->assertSet('rsImportRun', null)
        ->callAction('importRs', data: ['file' => checkpointUpload()])->assertSet('rsImportProgress.phase', 'preparing');
    expect(DB::table('purchases')->get()->toJson())->toBe($before);
    Storage::disk('local')->assertExists('purchase-import-workspaces/'.$legacy.'.sqlite');
});

test('retention removes expired completed files reports and indexes while preserving active sources', function () {
    $page = Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => checkpointUpload()]);
    $active = RsImportCheckpoint::findOrFail($page->get('rsImportRun'));
    touch(Storage::disk('local')->path($active->source_path), now()->subDays(60)->timestamp);
    touch(Storage::disk('local')->path($active->error_report_path), now()->subDays(60)->timestamp);
    $active->update(['expires_at' => now()->subDay()]); // Even stale expiry cannot override active status.
    $completed = $active->replicate()->fill(['id' => (string) Str::uuid(), 'status' => 'completed', 'expires_at' => now()->subDay(),
        'source_path' => 'purchase-import-workspaces/expired.csv', 'staged_path' => 'purchase-import-workspaces/expired.jsonl',
        'error_report_path' => 'purchase-import-reports/expired.jsonl']);
    $completed->save();
    foreach ([$completed->source_path, $completed->staged_path, $completed->error_report_path] as $path) {
        Storage::disk('local')->put($path, 'expired');
    }
    DB::table('rs_import_checkpoint_entries')->insert(['checkpoint_id' => $completed->id, 'kind' => 'issue', 'entry_key' => hash('sha256', 'expired')]);
    $before = DB::table('purchases')->get()->toJson();
    $this->artisan('rs:imports-prune')->assertSuccessful();
    expect(RsImportCheckpoint::find($completed->id))->toBeNull()->and(RsImportCheckpoint::find($active->id))->not->toBeNull()
        ->and(DB::table('rs_import_checkpoint_entries')->where('checkpoint_id', $completed->id)->count())->toBe(0)
        ->and(DB::table('purchases')->get()->toJson())->toBe($before);
    Storage::disk('local')->assertExists($active->source_path);
    Storage::disk('local')->assertExists($active->error_report_path);
    Storage::disk('local')->assertMissing($completed->source_path);
    Storage::disk('local')->assertMissing($completed->staged_path);
    Storage::disk('local')->assertMissing($completed->error_report_path);
});

test('retention removes old unreferenced private artifacts and preserves legacy databases', function () {
    $orphan = 'purchase-import-reports/'.Str::uuid().'.jsonl';
    $legacy = 'purchase-import-workspaces/'.Str::uuid().'.sqlite';
    foreach ([$orphan, $legacy] as $path) {
        Storage::disk('local')->put($path, 'old operational artifact');
        touch(Storage::disk('local')->path($path), now()->subDays(60)->timestamp);
    }
    $this->artisan('rs:imports-prune')->assertSuccessful();
    Storage::disk('local')->assertMissing($orphan);
    Storage::disk('local')->assertExists($legacy);
});

test('a private report write failure pauses without advancing the cursor and resumes safely', function () {
    $original = Storage::disk('local');
    $disk = Mockery::mock($original);
    $fail = true;
    // Capture by reference so the same request state can retry after storage recovers.
    $disk->shouldReceive('append')->andReturnUsing(function ($path, $contents, $separator = PHP_EOL) use (&$fail, $original) {
        return $fail ? false : $original->append($path, $contents, $separator);
    });
    Storage::set('local', $disk);
    $file = UploadedFile::fake()->createWithContent('bad.csv', "Date,Supplier,Product,Quantity,Unit,Unit Price,Total,Document\n2026-01-08,Report supplier,Material,bad,pcs,1,1,BAD\n")->mimeType('text/csv');
    $page = Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => $file])->call('continueRsImport')
        ->assertSet('rsImportPaused', true)->assertNotified('RS იმპორტი შეჩერებულია');
    $checkpoint = RsImportCheckpoint::findOrFail($page->get('rsImportRun'));
    expect($checkpoint->cursor)->toBe(0)->and(PurchaseItem::count())->toBe(0);
    $fail = false;
    $page->call('resumeRsImport')->assertSet('rsImportProgress.phase', 'completed');
    expect($checkpoint->fresh()->rejected_count)->toBe(1);
    expect($disk->get($checkpoint->fresh()->error_report_path))->toContain('Row 2');
    expect(substr_count(trim($disk->get($checkpoint->fresh()->error_report_path)), "\n"))->toBe(0);
});

test('checkpoint index failure pauses preparation without rejecting valid source rows', function () {
    $page = Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => checkpointUpload()]);
    $fail = true;
    DB::connection()->beforeExecuting(function ($query, $bindings) use (&$fail): void {
        if ($fail && str_contains($query, 'rs_import_checkpoint_entries') && str_starts_with(strtolower($query), 'insert')
            && in_array('occurrence', $bindings, true)) {
            throw new QueryException('testing', $query, $bindings, new RuntimeException('Synthetic checkpoint index failure'));
        }
    });
    $page->call('continueRsImport')->assertSet('rsImportPaused', true)->assertNotified('RS იმპორტი შეჩერებულია');
    $checkpoint = RsImportCheckpoint::findOrFail($page->get('rsImportRun'));
    expect($checkpoint->status)->toBe('preparing')->and($checkpoint->cursor)->toBe(0)
        ->and($checkpoint->rejected_count)->toBe(0)->and(PurchaseItem::count())->toBe(0);
    $fail = false;
    $page->call('resumeRsImport')->assertSet('rsImportProgress.phase', 'completed');
    expect(PurchaseItem::count())->toBe(3)->and($checkpoint->fresh()->rejected_count)->toBe(0);
});
