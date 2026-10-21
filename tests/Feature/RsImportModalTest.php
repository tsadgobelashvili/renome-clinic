<?php

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Models\PurchaseItem;
use App\Models\User;
use App\Services\RsHistoricalImport;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
});

test('XLSX modal submit persists its source closes and starts on the next progress request', function () {
    $path = tempnam(sys_get_temp_dir(), 'rs-modal').'.xlsx';
    $writer = new Writer;
    $writer->openToFile($path);
    $writer->addRow(Row::fromValues(['Date', 'Supplier', 'Product', 'Quantity', 'Unit', 'Unit Price', 'Total Amount', 'Invoice Number']));
    $writer->addRow(Row::fromValues(['2026-08-30', 'Excel Supplier', 'Resin', 2, 'pcs', 15, 30, 'XLSX-1']));
    $writer->close();
    try {
        $upload = UploadedFile::fake()->createWithContent('year.xlsx', file_get_contents($path))->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $page = Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => $upload])
            ->assertHasNoActionErrors()->assertActionNotMounted('importRs')->assertNotified('RS იმპორტი დაიწყო')
            ->assertSet('rsImportProgress.phase', 'preparing')->assertSee('ფაილი შენახულია');
        $id = $page->get('rsImportRun');
        Storage::disk('local')->assertExists('purchase-import-workspaces/'.$id.'.xlsx');
        Storage::disk('local')->assertExists('purchase-import-workspaces/'.$id.'.sqlite');
        expect(PurchaseItem::count())->toBe(0);
        $page->call('continueRsImport')->assertSet('rsImportProgress.phase', 'completed');
        expect(PurchaseItem::count())->toBe(1);
        Storage::disk('local')->assertMissing('purchase-import-workspaces/'.$id.'.xlsx');
    } finally {
        unlink($path);
    }
});

test('invalid or oversized modal uploads visibly fail before checkpoint creation', function (string $name, string $mime, int $size) {
    Log::spy();
    $page = Livewire::test(ListPurchases::class)->callAction('importRs', data: [
        'file' => UploadedFile::fake()->create($name, $size, $mime),
    ])->assertHasActionErrors()->assertNotified('ფაილი ვერ აიტვირთა')->assertSet('rsImportRun', null);
    Log::shouldHaveReceived('error')->withArgs(fn ($message, $context) => $message === 'RS import start failed' && isset($context['user_id'], $context['exception_class']))->once();
    expect(Storage::disk('local')->allFiles('purchase-import-workspaces'))->toBe([]);
})->with([
    ['bad.pdf', 'application/pdf', 1],
    ['large.csv', 'text/csv', 10241],
]);

test('checkpoint or storage startup exceptions visibly fail and allow retry', function () {
    Log::spy();
    $importer = Mockery::mock(RsHistoricalImport::class);
    $importer->shouldReceive('start')->once()->andThrow(new RuntimeException('checkpoint storage unavailable'));
    app()->instance(RsHistoricalImport::class, $importer);
    $file = UploadedFile::fake()->createWithContent('year.csv', file_get_contents(base_path('tests/fixtures/rs-items-georgian.csv')))->mimeType('text/csv');
    Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => $file])
        ->assertNotified('იმპორტის დაწყება ვერ მოხერხდა')->assertSet('rsImportRun', null);
    expect(Storage::disk('local')->allFiles('purchase-imports'))->toBe([]);
    Log::shouldHaveReceived('error')->once();
});

test('missing upload state reports a visible Georgian error', function () {
    Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => null])
        ->assertHasActionErrors()->assertNotified('ფაილი ვერ აიტვირთა')->assertSet('rsImportRun', null);
});

test('expired Livewire temporary file produces a visible error without a checkpoint', function () {
    $file = UploadedFile::fake()->createWithContent('expired.csv', file_get_contents(base_path('tests/fixtures/rs-items-georgian.csv')))->mimeType('text/csv');
    $page = Livewire::test(ListPurchases::class)->mountAction('importRs')->setActionData(['file' => $file]);
    $temporary = array_values($page->get('mountedActions')[0]['data']['file'])[0];
    expect($temporary)->toBeInstanceOf(TemporaryUploadedFile::class);
    $temporary->delete();
    // Call the server method directly: Livewire's test-only hydration touches
    // missing temporary files and would recreate an empty file on the next HTTP request.
    try {
        $page->instance()->callMountedAction();
    } catch (ValidationException) {
    }
    Notification::assertNotified('ფაილი ვერ აიტვირთა');
    expect($page->instance()->rsImportRun)->toBeNull();
});

test('a failed private source copy cannot leave a runnable checkpoint', function () {
    $originalDisk = Storage::disk('local');
    $disk = Mockery::mock($originalDisk);
    $copyAttempts = 0;
    $disk->shouldReceive('put')->andReturnUsing(function ($path, $stream, $options = []) use ($originalDisk, &$copyAttempts) {
        if (str_starts_with($path, 'purchase-import-workspaces/')) {
            expect(is_resource($stream))->toBeTrue();
            $copyAttempts++;

            return false;
        }

        return $originalDisk->put($path, $stream, $options);
    });
    Storage::set('local', $disk);
    $file = UploadedFile::fake()->createWithContent('year.csv', file_get_contents(base_path('tests/fixtures/rs-items-georgian.csv')))->mimeType('text/csv');
    Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => $file])
        ->assertNotified('იმპორტის დაწყება ვერ მოხერხდა')->assertSet('rsImportRun', null);
    expect($disk->allFiles('purchase-import-workspaces'))->toBe([]);
    expect($copyAttempts)->toBe(1);
});

test('service preflight validation uses a visible notification even for a non-form error key', function () {
    $importer = Mockery::mock(RsHistoricalImport::class);
    $importer->shouldReceive('start')->once()->andThrow(ValidationException::withMessages(['file' => 'Technical preflight failure']));
    app()->instance(RsHistoricalImport::class, $importer);
    $file = UploadedFile::fake()->createWithContent('year.csv', file_get_contents(base_path('tests/fixtures/rs-items-georgian.csv')))->mimeType('text/csv');
    Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => $file])
        ->assertNotified('ფაილი ვერ აიტვირთა')->assertSet('rsImportRun', null)->assertDontSee('Technical preflight failure');
});

test('a lower configured Livewire temporary upload limit is reflected in the modal limit', function () {
    config(['livewire.temporary_file_upload.rules' => 'required|file|max:512']);
    expect(ListPurchases::rsUploadLimitKiB())->toBe(512);
});
