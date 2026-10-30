<?php

use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Filament\Resources\Purchases\Pages\PurchaseProducts;
use App\Models\BankTransaction;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\FinanceManager;
use App\Services\PurchaseAnalysis;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseImportService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->travelTo('2026-10-22 12:00:00');
});

function cancelledCadCam(): Purchase
{
    app(PurchaseImportService::class)->import(base_path('tests/Fixtures/rs-cancelled-cad-cam.csv'));

    return Purchase::where('document_number', 'CANCELLED-CAD-CAM')->sole();
}

function catalogFinanceSnapshot(): array
{
    $tables = ['finance_transactions', 'partner_finance_transactions', 'cashbox_transactions', 'bank_transactions',
        'payments', 'employee_advances', 'employee_advance_entries', 'bank_purchase_matches', 'finance_opening_balances'];

    return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

test('cancelled unaccounted CAD CAM deletion removes lines exclusive products aliases and preserves finance and taxonomy', function () {
    Log::spy();
    $purchase = cancelledCadCam();
    $direction = app(ExpenseDimensions::class)->id('direction', 'laboratory');
    $group = app(PurchaseCatalog::class)->createGroup(['name' => 'Milling burs', 'expense_direction_id' => $direction]);
    $products = PurchaseProduct::orderBy('id')->get();
    foreach ($products as $product) {
        $product->update(['expense_direction_id' => $direction, 'purchase_product_group_id' => $group->id]);
        DB::table('purchase_product_aliases')->insert(['supplier_id' => $product->supplier_id,
            'identity_key' => hash('sha256', 'exclusive-'.$product->id), 'purchase_product_id' => $product->id]);
    }
    $groupBefore = $group->fresh()->getAttributes();
    $directionBefore = DB::table('expense_categories')->where('id', $direction)->first();
    app(FinanceManager::class)->create(['type' => 'income', 'transaction_date' => today(), 'category' => 'other', 'amount' => 50,
        'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'funding_source' => 'clinic', 'created_by' => auth()->id()]);
    $before = catalogFinanceSnapshot();
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toArray();
    Livewire::test(EditPurchase::class, ['record' => $purchase->id])->callAction('delete')->assertHasNoActionErrors();
    expect(Purchase::find($purchase->id))->toBeNull()->and(PurchaseItem::count())->toBe(0)
        ->and(PurchaseProduct::count())->toBe(0)->and(DB::table('purchase_product_aliases')->count())->toBe(0)
        ->and($group->fresh()->getAttributes())->toBe($groupBefore)
        ->and(DB::table('expense_categories')->where('id', $direction)->first())->toEqual($directionBefore)
        ->and(catalogFinanceSnapshot())->toBe($before)->and(app(AccountingLedger::class)->pnlTotals(null, null)->toArray())->toEqual($pnl);
    Livewire::test(PurchaseProducts::class)->assertCanNotSeeTableRecords($products);
    $analysis = app(PurchaseAnalysis::class);
    expect($analysis->products([], (string) $group->id, (string) $direction)->get())->toBeEmpty();
    foreach ($products as $product) {
        expect($analysis->history([], (string) $group->id, (string) $product->id, (string) $direction)->get())->toBeEmpty();
    }
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $audit) => $message === 'Unaccounted RS purchase deleted'
        && $audit['document']['id'] === $purchase->id && count($audit['orphan_product_ids']) === 3 && $audit['user_id'] === $this->owner->id)->once();
});

test('shared 1mm bur and manual retained product keep classifications and analysis while exclusive burs disappear', function () {
    $purchase = cancelledCadCam();
    $shared = PurchaseProduct::where('rs_product_code', 'CAD-10')->sole();
    $manual = PurchaseProduct::where('rs_product_code', 'CAD-20')->sole();
    app(PurchaseCatalog::class)->createManual(['supplier_id' => $manual->supplier_id, 'name' => $manual->name,
        'supplier_product_code' => 'MANUAL-20']);
    // Explicit manual reuse of the RS identity must survive automatic cleanup.
    app(PurchaseCatalog::class)->resolve($manual->supplier_id, $manual->name, $manual->rs_product_code, fromRs: false);
    $direction = app(ExpenseDimensions::class)->id('direction', 'laboratory');
    $group = app(PurchaseCatalog::class)->createGroup(['name' => 'Shared burs', 'expense_direction_id' => $direction]);
    $shared->update(['expense_direction_id' => $direction, 'purchase_product_group_id' => $group->id]);
    $classification = $shared->fresh()->getAttributes();
    $valid = Purchase::create(['supplier_id' => $purchase->supplier_id, 'source' => 'rs', 'purchase_date' => today(), 'document_number' => 'VALID-CAD']);
    $valid->forceFill(['rs_import_payload' => ['validated' => true, 'document_kind' => 'purchase']])->save();
    $valid->items()->create(['purchase_product_id' => $shared->id, 'quantity' => 2, 'unit_price' => 150]);
    $exclusive = PurchaseProduct::where('rs_product_code', 'CAD-06')->sole();
    $purchase->delete();
    expect($exclusive->fresh())->toBeNull()->and($shared->fresh()->getAttributes())->toBe($classification)
        ->and($manual->fresh())->not->toBeNull()->and($manual->fresh()->manually_retained)->toBeTrue();
    $analysis = app(PurchaseAnalysis::class);
    $rows = $analysis->products([], (string) $group->id, (string) $direction)->get();
    expect($rows)->toHaveCount(1)->and((int) $rows->first()->product_id)->toBe($shared->id)
        ->and((float) $rows->first()->purchased_quantity)->toBe(2.0)->and((float) $rows->first()->purchase_amount)->toBe(300.0);
    expect($analysis->history([], (string) $group->id, (string) $shared->id, (string) $direction)->count())->toBe(1);
});

test('historical unknown origin is retained automatically and Owner can delete it explicitly with exclusive aliases', function () {
    Log::spy();
    $purchase = cancelledCadCam();
    $product = PurchaseProduct::where('rs_product_code', 'CAD-06')->sole();
    $product->update(['catalog_origin' => null]);
    $purchase->delete();
    expect($product->fresh())->not->toBeNull();
    DB::table('purchase_product_aliases')->insert(['supplier_id' => $product->supplier_id, 'identity_key' => hash('sha256', 'legacy-code'), 'purchase_product_id' => $product->id]);
    Livewire::test(PurchaseProducts::class)->callAction(TestAction::make('deleteProduct')->table($product))->assertHasNoActionErrors()
        ->assertNotified('პროდუქტი წაიშალა')->assertCanNotSeeTableRecords([$product]);
    expect($product->fresh())->toBeNull()->and(DB::table('purchase_product_aliases')->where('purchase_product_id', $product->id)->count())->toBe(0);
    Log::shouldHaveReceived('info')->withArgs(fn ($message, $audit) => $message === 'Unused purchase catalog product deleted'
        && $audit['product']['id'] === $product->id && $audit['product']['rs_product_code'] === 'CAD-06'
        && count($audit['product']['aliases']) === 1 && $audit['user_id'] === $this->owner->id)->once();
});

test('manual deletion of a referenced product is blocked visibly and a forged direct model deletion is also blocked', function () {
    $purchase = cancelledCadCam();
    $product = PurchaseProduct::firstOrFail();
    Livewire::test(PurchaseProducts::class)->callAction(TestAction::make('deleteProduct')->table($product))
        ->assertNotified('პროდუქტის წაშლა შეუძლებელია');
    expect(fn () => $product->delete())->toThrow(ValidationException::class);
    expect($product->fresh())->not->toBeNull()->and($purchase->fresh()->items()->count())->toBe(3);
});

test('manual only products are not swept and a legacy shared identity protects the canonical product', function () {
    $manual = app(PurchaseCatalog::class)->createManual(['name' => 'Manual only tool']);
    $purchase = cancelledCadCam();
    $root = PurchaseProduct::where('rs_product_code', 'CAD-06')->sole();
    $member = PurchaseProduct::create(['supplier_id' => $root->supplier_id, 'identity_key' => hash('sha256', 'legacy-member'), 'name' => $root->name, 'normalized_name' => $root->normalized_name]);
    DB::table('purchase_product_aliases')->insert(['supplier_id' => $root->supplier_id, 'identity_key' => $member->identity_key, 'purchase_product_id' => $root->id]);
    $purchase->delete();
    expect($manual->fresh())->not->toBeNull()->and($root->fresh())->not->toBeNull()->and($member->fresh())->not->toBeNull();
    expect(fn () => $root->delete())->toThrow(ValidationException::class);
    expect(DB::table('purchase_product_aliases')->where('identity_key', $member->identity_key)->value('purchase_product_id'))->toBe($root->id);
});

test('other application foreign keys to either a product or its aliases block cleanup', function (string $reference) {
    $product = app(PurchaseCatalog::class)->resolve(Supplier::create(['name' => 'External refs'])->id, 'Referenced tool');
    $alias = DB::table('purchase_product_aliases')->insertGetId(['supplier_id' => $product->supplier_id,
        'identity_key' => hash('sha256', 'external-alias'), 'purchase_product_id' => $product->id]);
    Schema::create('catalog_external_references', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('purchase_product_id')->nullable()->constrained()->restrictOnDelete();
        $table->foreignId('alias_id')->nullable()->constrained('purchase_product_aliases')->restrictOnDelete();
    });
    DB::table('catalog_external_references')->insert($reference === 'product' ? ['purchase_product_id' => $product->id] : ['alias_id' => $alias]);
    expect(fn () => $product->delete())->toThrow(ValidationException::class);
    expect($product->fresh())->not->toBeNull()->and(DB::table('purchase_product_aliases')->where('id', $alias)->exists())->toBeTrue();
})->with(['product', 'alias']);

test('non Owners cannot delete RS documents or catalog products through server methods', function () {
    $purchase = cancelledCadCam();
    $product = PurchaseProduct::firstOrFail();
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    expect(fn () => $purchase->delete())->toThrow(AuthorizationException::class);
    expect(fn () => $product->delete())->toThrow(AuthorizationException::class);
    expect(Purchase::count())->toBe(1)->and(PurchaseProduct::count())->toBe(3);
});

test('Bank accounting links still block document deletion and leave catalog and financial history unchanged', function () {
    $purchase = cancelledCadCam();
    $bank = BankTransaction::create(['transaction_date' => today(), 'amount' => 450, 'currency' => 'GEL', 'direction' => 'outflow',
        'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64), 'source' => 'api', 'raw_data' => []]);
    $purchase->bankTransactions()->attach($bank->id, ['amount' => 450, 'confirmed_by' => $this->owner->id]);
    $before = catalogFinanceSnapshot();
    Livewire::test(EditPurchase::class, ['record' => $purchase->id])->assertActionDisabled('delete');
    expect(fn () => $purchase->delete())->toThrow(ValidationException::class);
    expect(PurchaseProduct::count())->toBe(3)->and($purchase->fresh()->items()->count())->toBe(3)->and(catalogFinanceSnapshot())->toBe($before);
});

test('Finance and Israeli Finance usage keeps the affected RS product even when its last purchase line is deleted', function (string $ledger) {
    $purchase = cancelledCadCam();
    $product = PurchaseProduct::where('rs_product_code', 'CAD-10')->sole();
    if ($ledger === 'clinic') {
        FinanceTransaction::create(['type' => 'income', 'transaction_date' => today(), 'category' => 'other', 'amount' => 25,
            'currency' => 'GEL', 'payment_method' => 'bank_transfer', 'purchase_product_id' => $product->id]);
    } else {
        PartnerFinanceTransaction::create(['type' => 'expense', 'transacted_at' => now(), 'category' => 'materials',
            'amount' => 25, 'currency' => 'USD', 'from_account' => 'cash', 'purchase_product_id' => $product->id]);
    }
    $before = catalogFinanceSnapshot();
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toArray();
    $purchase->delete();
    expect($product->fresh())->not->toBeNull()->and(PurchaseItem::count())->toBe(0);
    expect(fn () => $product->delete())->toThrow(ValidationException::class);
    expect(catalogFinanceSnapshot())->toBe($before)->and(app(AccountingLedger::class)->pnlTotals(null, null)->toArray())->toEqual($pnl);
})->with(['clinic', 'israeli']);

test('legacy purchase lines resolved through exact code and alias mappings keep the canonical catalog product', function () {
    $purchase = cancelledCadCam();
    $product = PurchaseProduct::where('rs_product_code', 'CAD-10')->sole();
    $legacy = Purchase::create(['supplier_id' => $purchase->supplier_id, 'source' => 'rs', 'purchase_date' => today()]);
    $legacy->items()->create(['source_row_hash' => 'LEGACY-CAD', 'item_name' => $product->name, 'quantity' => 1, 'unit_price' => 150]);
    $legacy->forceFill(['rs_import_payload' => ['validated' => true, 'source_rows' => ['LEGACY-CAD' => ['raw' => [
        'headers' => ['RS Product Code'], 'cells' => ['CAD-10'],
    ]]]]])->save();
    $purchase->delete();
    expect($product->fresh())->not->toBeNull();
    expect(fn () => $product->delete())->toThrow(ValidationException::class);
    expect($legacy->fresh()->items()->count())->toBe(1);
});

test('a later product cleanup failure rolls back document lines aliases and prior product deletion without success audits', function () {
    $purchase = cancelledCadCam();
    $ids = PurchaseProduct::orderBy('id')->pluck('id');
    foreach ($ids as $id) {
        DB::table('purchase_product_aliases')->insert(['supplier_id' => $purchase->supplier_id,
            'identity_key' => hash('sha256', 'rollback-'.$id), 'purchase_product_id' => $id]);
    }
    Log::spy();
    $event = 'eloquent.deleting: '.PurchaseProduct::class;
    Event::listen($event, function ($product) use ($ids): void {
        if ($product->id === $ids[1]) {
            throw new RuntimeException('Synthetic later cleanup failure');
        }
    });
    try {
        expect(fn () => $purchase->delete())->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }
    expect(Purchase::find($purchase->id))->not->toBeNull()->and(PurchaseItem::count())->toBe(3)
        ->and(PurchaseProduct::count())->toBe(3)->and(DB::table('purchase_product_aliases')->count())->toBe(3);
    Log::shouldNotHaveReceived('info');
});

test('a last legacy line without a direct catalog foreign key cleans only its resolved RS identity', function () {
    $purchase = cancelledCadCam();
    $product = PurchaseProduct::where('rs_product_code', 'CAD-10')->sole();
    $line = $purchase->items()->where('purchase_product_id', $product->id)->sole();
    $line->update(['purchase_product_id' => null, 'source_row_hash' => 'LAST-LEGACY-CAD']);
    $purchase->forceFill(['rs_import_payload' => ['source_rows' => ['LAST-LEGACY-CAD' => ['raw' => [
        'headers' => ['RS Product Code'], 'cells' => ['CAD-10'],
    ]]]]])->save();
    $purchase->delete();
    expect(PurchaseProduct::count())->toBe(0)->and(PurchaseItem::count())->toBe(0);
});

test('manually resolved supplier products carry manual intent and RS reuse does not overwrite it', function () {
    $supplier = Supplier::create(['name' => 'Manual supplier']);
    $product = app(PurchaseCatalog::class)->resolve($supplier->id, 'Manual tool', 'M-1', fromRs: false);
    expect($product->catalog_origin)->toBe('manual')->and($product->manually_retained)->toBeTrue();
    expect(app(PurchaseCatalog::class)->resolve($supplier->id, 'Manual tool', 'M-1')->id)->toBe($product->id);
    expect($product->fresh()->catalog_origin)->toBe('manual')->and($product->fresh()->manually_retained)->toBeTrue();
});
