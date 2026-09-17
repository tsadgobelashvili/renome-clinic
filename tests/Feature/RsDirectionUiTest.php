<?php

use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Filament\Resources\Purchases\Pages\PurchaseItems;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('RS selectors offer the requested directions without renaming historical administration', function () {
    expect(array_values(app(PurchaseCatalog::class)->directionOptions()))->toHaveCount(7)
        ->toContain('ქირურგია', 'თერაპია', 'ორთოპედია', 'ლაბორატორია', 'საერთო', 'სხვა');
    $admin = app(ExpenseDimensions::class)->id('direction', 'administration');
    expect(app(PurchaseCatalog::class)->directionOptions($admin)[$admin])->toBe('ადმინისტრაცია');
});

test('inline assignment and compact edit form update the same mapping for future RS imports', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $tables = ['finance_transactions', 'partner_finance_transactions', 'cashbox_transactions', 'bank_transactions', 'payments'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $snapshot();
    $fixture = base_path('tests/fixtures/rs-items-georgian.csv');
    app(PurchaseImportService::class)->import($fixture);
    $line = PurchaseItem::where('item_name', 'უცნობი მასალა')->firstOrFail();
    $surgery = app(ExpenseDimensions::class)->id('direction', 'surgery');
    $therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    Livewire::test(PurchaseItems::class)->filterTable('uncategorized')->assertCanSeeTableRecords([$line])
        ->call('updateTableColumnState', 'direction', (string) $line->id, (string) $surgery)->assertCanNotSeeTableRecords([$line]);

    $path = tempnam(sys_get_temp_dir(), 'rs-direction');
    rename($path, $path .= '.csv');
    try {
        file_put_contents($path, str_replace('RS-TEST-1', 'RS-TEST-3', file_get_contents($fixture)));
        expect(app(PurchaseImportService::class)->import($path)['imported'])->toBe(2);
        $next = Purchase::where('document_number', 'RS-TEST-3')->firstOrFail();
        $nextLine = $next->items()->where('purchase_product_id', $line->purchase_product_id)->firstOrFail();
        expect($nextLine->purchaseProduct->expense_direction_id)->toBe($surgery);
        $page = Livewire::test(EditPurchase::class, ['record' => $next->id])->assertSuccessful()
            ->assertSee('მიმართულება')->assertSee('renome-rs-items', escape: false);
        expect($page->instance()->getMaxContentWidth())->toBe(Width::SevenExtraLarge);
        $page->set('data.items.record-'.$nextLine->id.'.expense_direction_id', $therapy);
        // Form edits persist only on save, so cancelling does not reclassify history.
        expect($line->purchaseProduct->fresh()->expense_direction_id)->toBe($surgery);
        $page->call('save')->assertHasNoFormErrors();
        expect($line->purchaseProduct->fresh()->expense_direction_id)->toBe($therapy)
            ->and((float) $next->fresh()->total_amount)->toBe(336.0);
        file_put_contents($path, str_replace('RS-TEST-1', 'RS-TEST-4', file_get_contents($fixture)));
        app(PurchaseImportService::class)->import($path);
        $latest = Purchase::where('document_number', 'RS-TEST-4')->firstOrFail()->items()->where('purchase_product_id', $line->purchase_product_id)->firstOrFail();
        expect($latest->purchaseProduct->expense_direction_id)->toBe($therapy)->and(PurchaseProduct::count())->toBe(3);
    } finally {
        unlink($path);
    }
    expect($snapshot())->toBe($before);
});

test('duplicate product rows in the same document cannot overwrite the edited direction with stale state', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    app(PurchaseImportService::class)->import(base_path('tests/fixtures/rs-items-georgian.csv'));
    $line = PurchaseItem::firstOrFail();
    $second = $line->purchase->items()->create(['purchase_product_id' => $line->purchase_product_id, 'quantity' => 1, 'unit_price' => 15]);
    $surgery = app(ExpenseDimensions::class)->id('direction', 'surgery');
    $page = Livewire::test(EditPurchase::class, ['record' => $line->purchase_id])
        ->set('data.items.record-'.$line->id.'.expense_direction_id', $surgery);
    expect((int) $page->get('data.items.record-'.$second->id.'.expense_direction_id'))->toBe($surgery);
    $page->call('save')->assertHasNoFormErrors();
    expect($line->purchaseProduct->fresh()->expense_direction_id)->toBe($surgery);
});
