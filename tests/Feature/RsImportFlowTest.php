<?php

use App\Filament\Resources\Purchases\Pages\ListPurchases;
use App\Filament\Resources\Purchases\Pages\PurchaseItems;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('actual Georgian RS export headers flow from upload into documents lines and unclassified view', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $supplier = Supplier::create(['name' => 'სატესტო მომწოდებელი']);
    $known = app(PurchaseCatalog::class)->resolve($supplier->id, 'ცნობილი მასალა', '001');
    $direction = app(ExpenseDimensions::class)->id('direction', 'therapy');
    app(PurchaseCatalog::class)->assignDirection($known, $direction);
    $tables = ['finance_transactions', 'partner_finance_transactions', 'direct_expenses', 'cashbox_transactions', 'bank_transactions', 'payments', 'products'];
    $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $upload = fn () => UploadedFile::fake()->createWithContent('report (5).csv', file_get_contents(base_path('tests/fixtures/rs-items-georgian.csv')))->mimeType('text/csv');
    $page = Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => $upload()])->assertHasNoActionErrors()
        ->assertNotified('RS იმპორტი დასრულდა');
    expect(Purchase::count())->toBe(2)->and(PurchaseItem::count())->toBe(3)
        ->and((float) Purchase::sum('total_amount'))->toBe(362.0)
        ->and(Purchase::where('document_number', 'RS-TEST-1')->first()->purchase_date->toDateString())->toBe('2026-09-01');
    $page->assertCanSeeTableRecords(Purchase::all());
    $unknown = PurchaseItem::where('item_name', 'უცნობი მასალა')->firstOrFail();
    $mapped = PurchaseItem::where('item_name', 'ცნობილი მასალა')->firstOrFail();
    expect($mapped->purchaseProduct->expense_direction_id)->toBe($direction)->and($mapped->purchaseProduct->rs_product_code)->toBe('001');
    Livewire::test(PurchaseItems::class)->assertCanSeeTableRecords(PurchaseItem::all());
    Livewire::test(PurchaseItems::class)->filterTable('uncategorized')->assertCanSeeTableRecords([$unknown])->assertCanNotSeeTableRecords([$mapped])
        ->call('updateTableColumnState', 'direction', (string) $unknown->id, (string) $direction)->assertCanNotSeeTableRecords([$unknown]);
    $page->callAction('importRs', data: ['file' => $upload()])->assertHasNoActionErrors()->assertNotified('ახალი ჩანაწერები არ იმპორტირებულა');
    expect(Purchase::count())->toBe(2)->and(PurchaseItem::count())->toBe(3)
        ->and(collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all())->toBe($before);
});

test('partial reimport adds new lines to the existing document and reports exact counts', function () {
    $rows = file(base_path('tests/fixtures/rs-items-georgian.csv'));
    $path = tempnam(sys_get_temp_dir(), 'rs-flow');
    rename($path, $path .= '.csv');
    try {
        file_put_contents($path, $rows[0].$rows[1]);
        $first = app(PurchaseImportService::class)->import($path);
        file_put_contents($path, implode('', $rows));
        $second = app(PurchaseImportService::class)->import($path);
        $third = app(PurchaseImportService::class)->import($path);
    } finally {
        unlink($path);
    }
    expect($first)->toMatchArray(['documents_imported' => 1, 'imported' => 1, 'skipped' => 0, 'failed_rows' => 0, 'errors' => []])
        ->and($second)->toMatchArray(['documents_imported' => 1, 'imported' => 2, 'skipped' => 1, 'failed_rows' => 0, 'errors' => []])
        ->and($third)->toMatchArray(['documents_imported' => 0, 'imported' => 0, 'skipped' => 3, 'failed_rows' => 0, 'errors' => []])
        ->and(Purchase::count())->toBe(2)->and(PurchaseItem::count())->toBe(3);
});

test('unsupported document only exports produce a warning rather than zero row success', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $file = UploadedFile::fake()->createWithContent('documents.csv', "ზედნადები,ორგანიზაცია,თანხა\nDOC,Test,100\n")->mimeType('text/csv');
    Livewire::test(ListPurchases::class)->callAction('importRs', data: ['file' => $file])->assertHasNoActionErrors()
        ->assertNotified(Notification::make()->warning()->title('ახალი ჩანაწერები არ იმპორტირებულა')
            ->body('დოკუმენტები: 0 · პროდუქტები: 0 · დუბლიკატები: 0 · უკატეგორიო: 0 · არასწორი სტრიქონები: 0'."\n".'RS სათაურები ვერ მოიძებნა: საჭიროა საქონლის დასახელება და გამყიდველი/მომწოდებელი. ატვირთეთ საქონლის დეტალური ექსპორტი.')->persistent());
    expect(Purchase::count())->toBe(0);
});

test('invalid rows report failures and do not prevent subsequent valid document persistence', function () {
    $path = tempnam(sys_get_temp_dir(), 'rs-invalid');
    rename($path, $path .= '.csv');
    $fixture = file_get_contents(base_path('tests/fixtures/rs-items-georgian.csv'));
    file_put_contents($path, preg_replace('/01-სექ-2026 11:18:22/', '31-თებ-2026 11:18:22', $fixture, 1));
    try {
        $result = app(PurchaseImportService::class)->import($path);
    } finally {
        unlink($path);
    }
    expect($result)->toMatchArray(['documents_imported' => 2, 'imported' => 2, 'failed_rows' => 1, 'skipped' => 0])
        ->and($result['errors'])->toHaveCount(1)->and($result['errors'][0])->toContain('Row 2', 'Invalid RS document date')
        ->and(Purchase::count())->toBe(2)->and(PurchaseItem::count())->toBe(2);
});

test('optional original RS file imports in the isolated test database', function () {
    $path = getenv('RS_IMPORT_SAMPLE');
    if (! $path) {
        $this->markTestSkipped('Set RS_IMPORT_SAMPLE to verify a local RS export without copying private data into fixtures.');
    }
    $summary = app(PurchaseImportService::class)->import($path);
    expect($summary)->toMatchArray(['documents_imported' => 20, 'imported' => 37, 'skipped' => 0, 'failed_rows' => 0, 'errors' => []])
        ->and(Purchase::count())->toBe(20)->and(PurchaseItem::count())->toBe(37);
    expect(app(PurchaseImportService::class)->import($path))->toMatchArray(['documents_imported' => 0, 'imported' => 0, 'skipped' => 37, 'errors' => []]);
});
