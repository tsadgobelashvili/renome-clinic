<?php

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Models\User;
use App\Services\RsHistoricalImport;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

// No database traits or real filesystem operations: exercise the action with mocks.
beforeEach(function () {
    DB::shouldReceive('connection')->never();
    Http::preventStrayRequests();
    $this->page = new class extends ListPurchases
    {
        public function importAction()
        {
            return $this->getHeaderActions()[1];
        }

        public function resetPage(?string $pageName = null): void {}

        public function flushCachedTableRecords(): void {}

        public function continueRsImport(): void {}
    };
    $this->action = $this->page->importAction();
});

test('RS uploads use private generated names and supported extensions', function (string $extension) {
    $field = $this->action->getSchema(Schema::make($this->page))->getComponents()[0];
    $file = Mockery::mock(TemporaryUploadedFile::class);
    $file->shouldReceive('getClientOriginalExtension')->once()->andReturn($extension);
    $name = $field->getUploadedFileNameForStorage($file);
    expect($name)->toMatch('/^[a-f0-9-]{36}\.'.strtolower($extension).'$/')
        ->and($field->getVisibility())->toBe('private')
        ->and($field->getDiskName())->toBe('local')
        ->and($this->page->rsUploadPath)->toBe('purchase-imports/'.$name);
    expect((new ReflectionProperty(ListPurchases::class, 'rsUploadPath'))->getAttributes(Locked::class))->toHaveCount(1);
    foreach (['rsImportRun', 'rsImportProgress', 'rsImportPaused'] as $property) {
        expect((new ReflectionProperty(ListPurchases::class, $property))->getAttributes(Locked::class))->toHaveCount(1);
    }
})->with(['csv', 'XLSX']);

test('RS forged upload state cannot resolve or delete a stored file', function (mixed $path) {
    $this->page->rsUploadPath = 'purchase-imports/owned.csv';
    Storage::shouldReceive('disk')->never();
    $importer = Mockery::mock(RsHistoricalImport::class);
    $importer->shouldNotReceive('start');
    expect(fn () => ($this->action->getActionFunction())(['file' => $path], $importer))->toThrow(ValidationException::class);
})->with([
    ['../.env'], ['purchase-imports/../secret.csv'], ['/purchase-imports/owned.csv'],
    ['purchase-imports\\owned.csv'], ['purchase-imports/owned.csv/extra'],
    ["purchase-imports/owned.csv\n"], ['purchase-imports/owned.php'], ['purchase-imports/owned.xls'],
    ['purchase-imports/another.csv'], [null], [['purchase-imports/owned.csv']],
]);

test('RS cleanup uses only its validated upload on success and importer failure', function (string $extension, bool $fail) {
    $stored = 'purchase-imports/owned.'.$extension;
    $this->page->rsUploadPath = $stored;
    $disk = Mockery::mock();
    Storage::shouldReceive('disk')->with('local')->twice()->andReturn($disk);
    $disk->shouldReceive('path')->once()->with($stored)->andReturn('/private/'.$stored);
    $disk->shouldReceive('delete')->once()->with($stored)->andReturn(true);
    $actor = new User(['role' => User::ROLE_OWNER]);
    $actor->forceFill(['id' => 42]);
    Auth::shouldReceive('user')->times($fail ? 1 : 2)->andReturn($actor);
    $importer = Mockery::mock(RsHistoricalImport::class);
    $call = $importer->shouldReceive('start')->once()->with('/private/'.$stored, $actor, true);
    if ($fail) {
        $call->andThrow(new RuntimeException('Import failed'));
        expect(fn () => ($this->action->getActionFunction())(['file' => $stored], $importer))->toThrow(RuntimeException::class, 'Import failed');
    } else {
        Auth::shouldReceive('id')->once()->andReturn(42);
        $call->andReturn('a90d4fb2-a51b-48d8-af18-ab26aa1499fb');
        $importer->shouldReceive('status')->once()->with('a90d4fb2-a51b-48d8-af18-ab26aa1499fb', $actor)
            ->andReturn(['phase' => 'importing', 'processed' => 0, 'total' => 5000]);
        ($this->action->getActionFunction())(['file' => $stored], $importer);
    }
    expect($this->page->rsUploadPath)->toBeNull();
})->with([['csv', false], ['xlsx', false], ['csv', true], ['xlsx', true]]);
