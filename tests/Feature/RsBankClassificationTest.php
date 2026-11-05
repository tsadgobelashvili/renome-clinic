<?php

use App\Filament\Pages\Bank;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\BogBusinessApiService;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\PurchaseCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-05 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    app()->setLocale('ka');
    $this->mock(BogBusinessApiService::class)->shouldReceive('currentBalance')->andReturn('9000.00');
    $this->supplier = Supplier::create(['name' => 'Synthetic RS supplier']);
    $this->matching = app(BankPurchaseMatching::class);
    $this->ledger = app(AccountingLedger::class);
    $this->catalog = app(PurchaseCatalog::class);
    $this->surgery = app(ExpenseDimensions::class)->id('direction', 'surgery');
    $this->therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
});

function bankClassProduct(string $name, ?int $direction = null, ?string $group = null): PurchaseProduct
{
    $product = test()->catalog->resolve(test()->supplier->id, $name);
    test()->catalog->assignDirection($product, $direction);
    if ($group !== null) {
        $record = test()->catalog->createGroup(['name' => $group, 'expense_direction_id' => $direction]);
        test()->catalog->assignGroup($product, $record->id);
    }

    return $product->fresh();
}

function bankClassInvoice(array $lines): Purchase
{
    $purchase = Purchase::create(['supplier_id' => test()->supplier->id, 'source' => 'rs',
        'purchase_date' => '2026-10-30', 'document_number' => Str::random(12)]);
    foreach ($lines as [$product, $amount]) {
        $purchase->items()->create(['purchase_product_id' => $product?->id, 'item_name' => 'Synthetic product', 'quantity' => 1, 'unit_price' => $amount]);
    }

    return $purchase->fresh();
}

function bankClassPayment(string $amount, array $invoices, array $attributes = []): BankTransaction
{
    $bank = BankTransaction::create(['transaction_date' => '2026-10-30', 'amount' => $amount, 'currency' => 'GEL', 'direction' => 'outflow',
        'counterparty_name' => 'Synthetic RS supplier', 'bank_category_id' => BankCategory::where('code', 'uncategorized')->value('id'),
        'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64), ...$attributes]);
    test()->matching->confirm($bank->id, collect($invoices)->map(fn ($paid, $id) => ['purchase_id' => $id, 'amount' => $paid])->values()->all(), auth()->user());

    return $bank;
}

function bankClassPage()
{
    return Livewire::test(Bank::class)->set('dateFrom', '2026-10-01')->set('dateUntil', '2026-11-05');
}

function bankClassSnapshot(): array
{
    return collect(['bank_transactions', 'bank_purchase_matches', 'purchases', 'purchase_items', 'finance_transactions',
        'partner_finance_transactions', 'cashbox_transactions', 'payments', 'employee_advance_entries'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

test('one classified RS invoice displays its real direction and group without overwriting the bank category', function () {
    $product = bankClassProduct('Material', $this->surgery, 'Synthetic bone');
    $invoice = bankClassInvoice([[$product, '467.20']]);
    $manualType = app(ExpenseDimensions::class)->id('type', 'materials', $this->therapy);
    $bank = bankClassPayment('467.20', [$invoice->id => '467.20'], ['expense_direction_id' => $this->therapy,
        'expense_type_id' => $manualType, 'classification_source' => 'manual']);
    $snapshot = bankClassSnapshot();
    $page = bankClassPage()->assertSee('Synthetic bone')->assertSeeHtml('data-rs-bank-classification="'.$bank->id.'"');
    preg_match('/<span data-rs-bank-classification="'.$bank->id.'".*?<\/button>/s', $page->html(), $labelHtml);
    expect($labelHtml[0])->toContain(app(ExpenseDimensions::class)->labelById($this->surgery))
        ->not->toContain(app(ExpenseDimensions::class)->labelById($this->therapy))
        ->not->toContain(app(ExpenseDimensions::class)->labelById(null));
    $label = $page->viewData('rsClassifications')[$bank->id];
    expect($label['directions']->all())->toBe([app(ExpenseDimensions::class)->labelById($this->surgery)])
        ->and($label['groups']->all())->toBe(['Synthetic bone'])->and($label['unresolved'])->toBeFalse()
        ->and($bank->fresh()->expense_direction_id)->toBe($this->therapy)->and($bank->fresh()->expense_type_id)->toBe($manualType)
        ->and((float) $this->ledger->dimensionEntries(null, null, source: 'bank')->sole()->amount)->toBe(467.2)
        ->and(bankClassSnapshot())->toBe($snapshot);
});

test('one invoice with multiple directions splits exact line totals but remains one bank expense', function () {
    $one = bankClassProduct('Surgery material', $this->surgery, 'Surgical group');
    $two = bankClassProduct('Therapy material', $this->therapy, 'Therapy group');
    $invoice = bankClassInvoice([[$one, '200.00'], [$two, '267.20']]);
    $bank = bankClassPayment('467.20', [$invoice->id => '467.20']);
    $snapshot = bankClassSnapshot();
    $entries = $this->ledger->dimensionEntries(null, null, source: 'bank')->get()->keyBy('dimension_subname');
    expect($entries)->toHaveCount(2)->and((float) $entries['Surgical group']->amount)->toBe(200.0)
        ->and((float) $entries['Therapy group']->amount)->toBe(267.2)
        ->and($this->ledger->pnl(null, null, source: 'bank')->count())->toBe(1)
        ->and((float) $this->ledger->pnlTotals(null, null, source: 'bank')->sole()->expenses)->toBe(467.2);
    bankClassPage()->assertSee('RS · 2 მიმართულება');
    expect(bankClassSnapshot())->toBe($snapshot);
});

test('multiple groups in one direction are visible with one aggregate bank row', function () {
    $one = bankClassProduct('A', $this->surgery, 'Group A');
    $two = bankClassProduct('B', $this->surgery, 'Group B');
    $invoice = bankClassInvoice([[$one, 80], [$two, 20]]);
    $bank = bankClassPayment('100.00', [$invoice->id => 100]);
    $page = bankClassPage()->assertSee('RS · 2 ჯგუფი');
    expect($page->viewData('rsClassifications')[$bank->id]['directions'])->toHaveCount(1)
        ->and($this->ledger->dimensionEntries(null, null, source: 'bank')->count())->toBe(2)
        ->and((float) $this->ledger->dimensionEntries(null, null, source: 'bank')->sum('amount'))->toBe(100.0)
        ->and(substr_count($page->html(), 'wire:key="bank-transaction-'.$bank->id.'"'))->toBe(1);
});

test('partial payments across multiple invoices conserve allocations and coalesce shared classifications', function () {
    $one = bankClassProduct('A', $this->surgery, 'Shared group');
    $two = bankClassProduct('B', $this->therapy, 'Other group');
    $first = bankClassInvoice([[$one, 100], [$two, 200]]);
    $second = bankClassInvoice([[$one, 100]]);
    $bank = bankClassPayment('200.00', [$first->id => 150, $second->id => 50]);
    $snapshot = bankClassSnapshot();
    $entries = $this->ledger->dimensionEntries(null, null, source: 'bank')->get()->keyBy('dimension_subname');
    expect($entries)->toHaveCount(2)->and((float) $entries['Shared group']->amount)->toBe(100.0)
        ->and((float) $entries['Other group']->amount)->toBe(100.0)
        ->and((float) $this->matching->allocations()->where('bank_transaction_id', $bank->id)->sum('amount'))->toBe(200.0)
        ->and((float) $this->ledger->pnlTotals(null, null, source: 'bank')->sole()->expenses)->toBe(200.0)
        ->and(bankClassSnapshot())->toBe($snapshot);
});

test('integer tetri rounding is deterministic and identical in bank and finance allocations', function () {
    $products = collect(['A', 'B', 'C'])->map(fn ($name) => bankClassProduct($name, $this->surgery, 'Group '.$name));
    $invoice = bankClassInvoice($products->map(fn ($p) => [$p, '1.00'])->all());
    $bank = bankClassPayment('1.00', [$invoice->id => '1.00']);
    $read = fn () => $this->matching->allocations([$bank->id])->get()->mapWithKeys(fn ($row) => [$row->purchase_product_group_id => $this->matching->cents($row->amount)])->sortKeys()->all();
    $expected = array_combine($products->pluck('purchase_product_group_id')->all(), [34, 33, 33]);
    expect($read())->toBe($expected)->and($read())->toBe($expected);
    $finance = $this->ledger->dimensionEntries(null, null, source: 'bank')->get();
    expect($finance->mapWithKeys(fn ($row) => [$row->purchase_product_group_id => $this->matching->cents($row->amount)])->sortKeys()->all())->toBe($expected)
        ->and($finance->sum(fn ($row) => $this->matching->cents($row->amount)))->toBe(100);
});

test('fully paid decimal line totals retain every original tetri', function () {
    $one = bankClassProduct('A', $this->surgery, 'A group');
    $two = bankClassProduct('B', $this->therapy, 'B group');
    $invoice = bankClassInvoice([[$one, '0.10'], [$one, '0.20'], [$two, '466.90']]);
    $bank = bankClassPayment('467.20', [$invoice->id => '467.20']);
    $shares = $this->matching->allocations([$bank->id])->get()->keyBy('purchase_product_id');
    expect($shares)->toHaveCount(2)->and($this->matching->cents($shares[$one->id]->amount))->toBe(30)
        ->and($this->matching->cents($shares[$two->id]->amount))->toBe(46690);
});

test('current group directions and canonical aliases resolve in bank summary as well as finance', function () {
    $old = bankClassProduct('Historical identity');
    $invoice = bankClassInvoice([[$old, 100]]);
    $bank = bankClassPayment('100.00', [$invoice->id => 100]);
    $canonical = bankClassProduct('Canonical identity', $this->therapy, 'Current group');
    DB::table('purchase_product_aliases')->insert(['supplier_id' => $this->supplier->id, 'identity_key' => $old->identity_key, 'purchase_product_id' => $canonical->id]);
    // Group direction is authoritative even when the legacy catalog field is absent.
    $canonical->update(['expense_direction_id' => null]);
    $snapshot = bankClassSnapshot();
    expect($this->matching->summary($bank->id)->status)->toBe('matched');
    $page = bankClassPage()->assertSee('Current group');
    expect($page->viewData('rsClassifications')[$bank->id]['unresolved'])->toBeFalse()
        ->and((int) $this->ledger->dimensionEntries(null, null, source: 'bank')->sole()->expense_direction_id)->toBe($this->therapy)
        ->and(bankClassSnapshot())->toBe($snapshot);
});

test('unknown products retain their expense share and are visibly unresolved until classified on refresh', function () {
    $known = bankClassProduct('Known', $this->surgery, 'Known group');
    $unknown = bankClassProduct('Unknown');
    $invoice = bankClassInvoice([[$known, 100], [$unknown, 50], [null, 25]]);
    $bank = bankClassPayment('175.00', [$invoice->id => 175]);
    $snapshot = bankClassSnapshot();
    $page = bankClassPage()->assertSee(__('bank-rs.classification_unresolved'));
    expect($page->viewData('rsClassifications')[$bank->id]['unresolved'])->toBeTrue()
        ->and((float) $this->ledger->dimensionEntries(null, null, source: 'bank')->sum('amount'))->toBe(175.0)
        ->and((float) $this->ledger->dimensionEntries(null, null, source: 'bank')->where('dimension_group', 'review')->sum('amount'))->toBe(75.0);
    $this->catalog->assignDirection($unknown, $this->surgery);
    $this->catalog->assignGroup($unknown, $known->purchase_product_group_id);
    $invoice->items()->whereNull('purchase_product_id')->update(['purchase_product_id' => $known->id]);
    // Above are isolated fixture classification changes; displaying results performs no writes.
    $updated = bankClassSnapshot();
    $page->call('$refresh')->assertDontSee(__('bank-rs.classification_unresolved'));
    expect($page->viewData('rsClassifications')[$bank->id]['unresolved'])->toBeFalse()
        ->and((float) $this->ledger->dimensionEntries(null, null, source: 'bank')->sole()->amount)->toBe(175.0)
        ->and(bankClassSnapshot())->toBe($updated)
        ->and($updated['bank_transactions'])->toBe($snapshot['bank_transactions'])
        ->and($updated['bank_purchase_matches'])->toBe($snapshot['bank_purchase_matches']);
});

test('unlinked bank portions remain explicit without duplicating expense totals', function () {
    $product = bankClassProduct('Material', $this->surgery, 'RS group');
    $invoice = bankClassInvoice([[$product, 80]]);
    $bank = bankClassPayment('100.00', [$invoice->id => 80]);
    $page = bankClassPage()->assertSee(__('bank-rs.unlinked_portion'));
    expect($page->viewData('rsClassifications')[$bank->id]['unlinked'])->toBeTrue()
        ->and((float) $this->matching->allocations([$bank->id])->sum('amount'))->toBe(80.0)
        ->and((float) $this->ledger->dimensionEntries(null, null, source: 'bank')->sum('amount'))->toBe(100.0);
});

test('manual bank expenses keep their stored labels and classification without RS overrides', function () {
    $type = app(ExpenseDimensions::class)->id('type', 'materials', $this->therapy);
    $bank = bankClassPayment('25.00', [], ['expense_direction_id' => $this->therapy, 'expense_type_id' => $type, 'classification_source' => 'manual']);
    $snapshot = bankClassSnapshot();
    $page = bankClassPage()->assertSee(app(ExpenseDimensions::class)->labelById($type));
    expect($page->viewData('rsClassifications')->has($bank->id))->toBeFalse()
        ->and((int) $this->ledger->dimensionEntries(null, null, source: 'bank')->sole()->expense_type_id)->toBe($type)
        ->and(bankClassSnapshot())->toBe($snapshot);
});
