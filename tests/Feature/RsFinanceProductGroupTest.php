<?php

use App\Filament\Pages\Finance;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\FinanceTransaction;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseProduct;
use App\Models\PurchaseProductGroup;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\BogBusinessApiService;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\Finance\EntryDetails;
use App\Services\Finance\RsBankExpenseDetails;
use App\Services\PurchaseCatalog;
use App\Services\PurchaseClassificationBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-04 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    $this->mock(BogBusinessApiService::class)->shouldReceive('currentBalance')->andReturn('9000.00');
    $this->catalog = app(PurchaseCatalog::class);
    $this->ledger = app(AccountingLedger::class);
    $this->surgery = app(ExpenseDimensions::class)->id('direction', 'surgery');
    $this->therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    $this->supplier = Supplier::create(['name' => 'Synthetic RS supplier']);
});

function rsGroupProduct(string $name, ?int $direction = null): PurchaseProduct
{
    $product = test()->catalog->resolve(test()->supplier->id, $name);
    test()->catalog->assignDirection($product, $direction);

    return $product->fresh();
}

function rsGroupInvoice(array $products): Purchase
{
    $purchase = Purchase::create(['supplier_id' => test()->supplier->id, 'source' => 'rs', 'purchase_date' => '2026-02-01', 'document_number' => Str::random(12)]);
    foreach ($products as $id => $amount) {
        $purchase->items()->create(['purchase_product_id' => $id, 'quantity' => 1, 'unit_price' => $amount]);
    }

    return $purchase->fresh();
}

function rsGroupBank(string $amount, array $invoices): BankTransaction
{
    $bank = BankTransaction::create(['transaction_date' => '2026-03-01', 'amount' => $amount, 'direction' => 'outflow', 'currency' => 'GEL',
        'bank_category_id' => BankCategory::where('code', 'uncategorized')->value('id'), 'counterparty_name' => 'Synthetic RS supplier',
        'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64)]);
    app(BankPurchaseMatching::class)->confirm($bank->id, collect($invoices)->map(fn ($amount, $id) => ['purchase_id' => $id, 'amount' => $amount])->values()->all(), auth()->user());

    return $bank;
}

function rsGroupClassify(PurchaseProduct $product, int $direction, int $group): void
{
    app(PurchaseClassificationBatch::class)->save([$product->id => ['expense_direction_id' => $direction, 'purchase_product_group_id' => $group]],
        [$product->id => $product->fresh()->getAttributes()]);
}

function rsGroupAccountingSnapshot(): array
{
    return collect(['purchases', 'purchase_items', 'bank_purchase_matches', 'bank_transactions', 'suppliers', 'finance_transactions',
        'partner_finance_transactions', 'cashbox_transactions', 'payments', 'payment_splits', 'employee_advance_entries'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

test('four historical invoices resolve 8880 GEL into the product group assigned after bank linking', function () {
    $one = rsGroupProduct('Synthetic bone A', $this->surgery);
    $two = rsGroupProduct('Synthetic bone B', $this->surgery);
    $unknown = rsGroupProduct('Genuinely unclassified item');
    $invoices = [];
    foreach ([[$one->id => 1120, $two->id => 632, $unknown->id => 136], [$one->id => 1094, $two->id => 1234],
        [$one->id => 1110, $two->id => 1252], [$one->id => 1146, $two->id => 1292]] as $items) {
        $invoice = rsGroupInvoice($items);
        $invoices[$invoice->id] = $invoice->total_amount;
    }
    $bank = rsGroupBank('9016.00', $invoices);
    $entries = fn () => $this->ledger->dimensionEntries(null, null, source: 'bank');
    expect((float) $entries()->where('dimension_group', (string) $this->surgery)->where('dimension_subgroup', 'review')->sum('amount'))->toBe(8880.0);
    $snapshot = rsGroupAccountingSnapshot();
    $totals = $this->ledger->pnlTotals(null, null)->toJson();
    $movements = $this->ledger->movementTotals(null, null)->toJson();
    $group = $this->catalog->createGroup(['name' => 'ძვალი', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($one, $this->surgery, $group->id);
    rsGroupClassify($two, $this->surgery, $group->id);
    $classified = $entries()->where('dimension_group', (string) $this->surgery)->sole();
    expect($classified->dimension_subgroup)->toBe('product-group:'.$group->id)
        ->and($classified->dimension_subname)->toBe('ძვალი')->and((float) $classified->amount)->toBe(8880.0)
        ->and($classified->entry_key)->toBe('bank:'.$bank->id)
        ->and((float) $entries()->where('dimension_subgroup', 'review')->sum('amount'))->toBe(136.0)
        ->and((float) $entries()->sum('amount'))->toBe(9016.0)
        ->and(rsGroupAccountingSnapshot())->toBe($snapshot)
        ->and($this->ledger->pnlTotals(null, null)->toJson())->toBe($totals)
        ->and($this->ledger->movementTotals(null, null)->toJson())->toBe($movements);
    $details = app(EntryDetails::class)->query($entries()->where('dimension_subgroup', 'product-group:'.$group->id), rsClassification: true)->sole();
    expect($details->detail_category)->toBe('ქირურგია')->and($details->detail_subcategory)->toBe('ძვალი');
    Livewire::test(Finance::class)->set('dateFrom', '2026-01-01')->set('dateUntil', '2026-11-04')->set('moneySource', 'bank')
        ->call('selectOverviewCard', 'expenses')->call('selectExpenseCategory', (string) $this->surgery)->assertSee('ძვალი')
        ->call('selectExpenseSubcategory', 'product-group:'.$group->id)
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && (float) $rows->first()->amount === 8880.0);
    expect(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('partial multi-invoice payments retain their paid proportions after classification', function () {
    $one = rsGroupProduct('One', $this->surgery);
    $two = rsGroupProduct('Two', $this->surgery);
    $first = rsGroupInvoice([$one->id => 100]);
    $second = rsGroupInvoice([$two->id => 200]);
    rsGroupBank('150.00', [$first->id => 50, $second->id => 100]);
    $before = rsGroupAccountingSnapshot();
    foreach ([[$one, 'Group one'], [$two, 'Group two']] as [$product, $name]) {
        $group = $this->catalog->createGroup(['name' => $name, 'expense_direction_id' => $this->surgery]);
        rsGroupClassify($product, $this->surgery, $group->id);
    }
    $entries = $this->ledger->dimensionEntries(null, null, source: 'bank')->get()->keyBy('dimension_subname');
    expect((float) $entries['Group one']->amount)->toBe(50.0)->and((float) $entries['Group two']->amount)->toBe(100.0)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe(150.0)
        ->and(rsGroupAccountingSnapshot())->toBe($before);
});

test('product-level rounding is conserved when products share a group', function () {
    $one = rsGroupProduct('First', $this->surgery);
    $two = rsGroupProduct('Second', $this->surgery);
    $three = rsGroupProduct('Third', $this->surgery);
    $invoice = rsGroupInvoice([$one->id => 1, $two->id => 1, $three->id => 1]);
    rsGroupBank('1.00', [$invoice->id => 1]);
    $group = $this->catalog->createGroup(['name' => 'Shared', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($one, $this->surgery, $group->id);
    rsGroupClassify($two, $this->surgery, $group->id);
    $entries = $this->ledger->dimensionEntries(null, null, source: 'bank')->get();
    expect($entries)->toHaveCount(2)->and(round($entries->sum('amount'), 2))->toBe(1.0)
        ->and((float) $entries->firstWhere('dimension_subgroup', 'product-group:'.$group->id)->amount)->toBe(0.67)
        ->and((float) $entries->firstWhere('dimension_subgroup', 'review')->amount)->toBe(0.33);
});

test('existing expense shares follow later group direction and label changes without relinking', function () {
    $product = rsGroupProduct('Material', $this->surgery);
    $invoice = rsGroupInvoice([$product->id => 100]);
    rsGroupBank('100.00', [$invoice->id => 100]);
    $snapshot = rsGroupAccountingSnapshot();
    $group = $this->catalog->createGroup(['name' => 'Therapy supplies', 'expense_direction_id' => $this->therapy]);
    rsGroupClassify($product, $this->therapy, $group->id);
    $this->catalog->updateGroup($group, ['name' => 'Updated group', 'expense_direction_id' => $this->therapy]);
    $entry = $this->ledger->dimensionEntries(null, null, source: 'bank')->sole();
    expect((int) $entry->dimension_group)->toBe($this->therapy)->and($entry->dimension_subname)->toBe('Updated group')
        ->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('canonical product aliases supply classification without rewriting historical invoice items', function () {
    $old = rsGroupProduct('Historical product');
    $invoice = rsGroupInvoice([$old->id => 100]);
    rsGroupBank('100.00', [$invoice->id => 100]);
    $snapshot = rsGroupAccountingSnapshot();
    $canonical = rsGroupProduct('Owner approved canonical product', $this->surgery);
    $group = $this->catalog->createGroup(['name' => 'Canonical group', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($canonical, $this->surgery, $group->id);
    DB::table('purchase_product_aliases')->insert(['supplier_id' => $this->supplier->id, 'identity_key' => $old->identity_key, 'purchase_product_id' => $canonical->id]);
    $entry = $this->ledger->dimensionEntries(null, null, source: 'bank')->sole();
    expect($entry->dimension_subname)->toBe('Canonical group')->and((int) $entry->expense_direction_id)->toBe($this->surgery)
        ->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('verified supplier-scoped legacy product mappings resolve existing expense groups', function () {
    $product = rsGroupProduct('Catalog product', $this->surgery);
    $retail = Product::create(['name' => 'Legacy retail identity', 'selling_price' => 0]);
    $anchor = rsGroupInvoice([$product->id => 100]);
    $anchor->items()->sole()->update(['product_id' => $retail->id]);
    $legacy = Purchase::create(['supplier_id' => $this->supplier->id, 'source' => 'rs', 'purchase_date' => '2026-02-02']);
    $legacy->items()->create(['product_id' => $retail->id, 'quantity' => 1, 'unit_price' => 50]);
    rsGroupBank('50.00', [$legacy->id => 50]);
    $group = $this->catalog->createGroup(['name' => 'Legacy resolved group', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($product, $this->surgery, $group->id);
    $snapshot = rsGroupAccountingSnapshot();
    expect($this->ledger->dimensionEntries(null, null, source: 'bank')->sole()->dimension_subname)->toBe('Legacy resolved group')
        ->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('Product Group IDs do not collide with unrelated expense type IDs in the hierarchy', function () {
    $type = app(ExpenseDimensions::class)->id('type', 'materials', $this->surgery);
    $group = (new PurchaseProductGroup)->forceFill(['id' => $type, 'name' => 'Distinct RS product group', 'expense_direction_id' => $this->surgery]);
    $group->save();
    $product = rsGroupProduct('RS item', $this->surgery);
    rsGroupClassify($product, $this->surgery, $group->id);
    $invoice = rsGroupInvoice([$product->id => 100]);
    rsGroupBank('100.00', [$invoice->id => 100]);
    FinanceTransaction::create(['type' => 'expense', 'transaction_date' => '2026-03-01', 'category' => 'materials', 'amount' => 20,
        'currency' => 'GEL', 'payment_method' => 'bank_transfer', 'expense_direction_id' => $this->surgery, 'expense_type_id' => $type]);
    $entries = $this->ledger->dimensionEntries(null, null);
    $children = $this->ledger->dimensionGroups($entries, (string) $this->surgery)->keyBy('subcategory_key');
    expect($children)->toHaveCount(2)->and((float) $children['product-group:'.$group->id]->amount)->toBe(100.0)
        ->and((float) $children[(string) $type]->amount)->toBe(20.0)
        ->and((float) $this->ledger->pnlTotals(null, null)->sole()->expenses)->toBe(120.0);
});

test('an explicit Product Group direction resolves legacy products without a stored product direction', function () {
    $product = rsGroupProduct('Group-directed legacy item');
    $invoice = rsGroupInvoice([$product->id => 100]);
    rsGroupBank('100.00', [$invoice->id => 100]);
    $group = $this->catalog->createGroup(['name' => 'Group direction', 'expense_direction_id' => $this->surgery]);
    $this->catalog->assignGroup($product, $group->id);
    $snapshot = rsGroupAccountingSnapshot();
    $entry = $this->ledger->dimensionEntries(null, null, source: 'bank')->sole();
    expect((int) $entry->expense_direction_id)->toBe($this->surgery)->and($entry->dimension_subname)->toBe('Group direction')
        ->and($product->fresh()->expense_direction_id)->toBeNull()->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('ambiguous legacy identities remain unclassified instead of borrowing an unrelated product group', function () {
    $retail = Product::create(['name' => 'Ambiguous legacy identity', 'selling_price' => 0]);
    foreach (['First identity', 'Second identity'] as $name) {
        $product = rsGroupProduct($name, $this->surgery);
        $group = $this->catalog->createGroup(['name' => $name.' group', 'expense_direction_id' => $this->surgery]);
        rsGroupClassify($product, $this->surgery, $group->id);
        rsGroupInvoice([$product->id => 10])->items()->sole()->update(['product_id' => $retail->id]);
    }
    $legacy = Purchase::create(['supplier_id' => $this->supplier->id, 'source' => 'rs', 'purchase_date' => '2026-02-02']);
    $legacy->items()->create(['product_id' => $retail->id, 'quantity' => 1, 'unit_price' => 50]);
    rsGroupBank('50.00', [$legacy->id => 50]);
    $snapshot = rsGroupAccountingSnapshot();
    $entry = $this->ledger->dimensionEntries(null, null, source: 'bank')->sole();
    expect($entry->dimension_subgroup)->toBe('review')->and((float) $entry->amount)->toBe(50.0)
        ->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

function rsInvoiceDetailRows(): Collection
{
    $details = app(EntryDetails::class);

    return $details->withRsInvoices($details->query(test()->ledger->dimensionEntries(null, null), rsClassification: true)->get());
}

function rsInvoiceDetailHtml(object $entry): string
{
    app()->setLocale('ka');

    return view('filament.pages.finance-entry-details', compact('entry'))->render();
}

test('RS bank detail replaces the unclassified bank label with its actual invoice and preserves references and actions', function () {
    $product = rsGroupProduct('Synthetic material', $this->surgery);
    $group = $this->catalog->createGroup(['name' => 'ძვალი', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($product, $this->surgery, $group->id);
    $invoice = rsGroupInvoice([$product->id => 100]);
    $bank = rsGroupBank('100.00', [$invoice->id => 100]);
    $bank->update(['reference' => 'BANK-REF-100']);
    $snapshot = rsGroupAccountingSnapshot();
    $totals = $this->ledger->pnlTotals(null, null)->toJson();
    $row = rsInvoiceDetailRows()->sole();
    $html = rsInvoiceDetailHtml($row);
    expect($row->detail_rs_invoices)->toBe([['id' => $invoice->id, 'number' => $invoice->document_number]])
        ->and($row->detail_key)->toBe('bank:'.$bank->id)->and($row->source)->toBe('bank')->and((float) $row->amount)->toBe(100.0)
        ->and($html)->toContain('RS ზედნადებები', '№'.$invoice->document_number, 'ქირურგია', 'ძვალი', 'BANK-REF-100', 'დეტალები')
        ->not->toContain(__('expense-dimensions.review'), __('bank-rules.provenance.none'), 'დეტალები / რედაქტირება');
    Livewire::test(Finance::class)->set('dateFrom', '2026-01-01')->set('dateUntil', '2026-11-04')->set('moneySource', 'bank')
        ->call('selectOverviewCard', 'expenses')->call('selectExpenseCategory', (string) $this->surgery)
        ->call('selectExpenseSubcategory', 'product-group:'.$group->id)
        ->assertSee('RS ზედნადებები')->assertSee('№'.$invoice->document_number)->assertSee('BANK-REF-100')
        ->assertDontSee(__('bank-rules.provenance.none'))
        ->assertViewHas('overviewDetails', fn ($rows) => $rows->count() === 1 && count($rows->first()->detail_rs_invoices) === 1);
    expect(rsGroupAccountingSnapshot())->toBe($snapshot)->and($this->ledger->pnlTotals(null, null)->toJson())->toBe($totals);
});

test('multiple actual linked invoices appear once despite multiple products and unrelated supplier invoices', function () {
    $one = rsGroupProduct('First RS product', $this->surgery);
    $two = rsGroupProduct('Second RS product', $this->surgery);
    $group = $this->catalog->createGroup(['name' => 'Shared invoice group', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($one, $this->surgery, $group->id);
    rsGroupClassify($two, $this->surgery, $group->id);
    $first = rsGroupInvoice([$one->id => 100, $two->id => 50]);
    $second = rsGroupInvoice([$one->id => 100]);
    $unlinked = rsGroupInvoice([$one->id => 250]);
    rsGroupBank('250.00', [$first->id => 150, $second->id => 100]);
    $snapshot = rsGroupAccountingSnapshot();
    $row = rsInvoiceDetailRows()->sole();
    $html = rsInvoiceDetailHtml($row);
    expect(array_column($row->detail_rs_invoices, 'id'))->toBe([$first->id, $second->id])
        ->and((float) $row->amount)->toBe(250.0)
        ->and(substr_count($html, '№'.$first->document_number))->toBe(1)
        ->and(substr_count($html, '№'.$second->document_number))->toBe(1)
        ->and($html)->not->toContain($unlinked->document_number)
        ->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('partial bank payments display the linked invoice without repeating its full amount', function () {
    $product = rsGroupProduct('Partly paid product', $this->surgery);
    $group = $this->catalog->createGroup(['name' => 'Partial group', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($product, $this->surgery, $group->id);
    $invoice = rsGroupInvoice([$product->id => 100]);
    rsGroupBank('70.00', [$invoice->id => 70]);
    $bank = rsGroupBank('30.00', [$invoice->id => 30]);
    $snapshot = rsGroupAccountingSnapshot();
    $rows = rsInvoiceDetailRows();
    $row = $rows->firstWhere('entry_key', 'bank:'.$bank->id);
    expect($rows)->toHaveCount(2)->and((float) $rows->sum('amount'))->toBe(100.0)
        ->and((float) $row->amount)->toBe(30.0)
        ->and($row->detail_rs_invoices)->toBe([['id' => $invoice->id, 'number' => $invoice->document_number]])
        ->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('invoice references are scoped to the contributing Product Group of each expense portion', function () {
    $one = rsGroupProduct('Group A product', $this->surgery);
    $two = rsGroupProduct('Group B product', $this->surgery);
    $a = $this->catalog->createGroup(['name' => 'Group A', 'expense_direction_id' => $this->surgery]);
    $b = $this->catalog->createGroup(['name' => 'Group B', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($one, $this->surgery, $a->id);
    rsGroupClassify($two, $this->surgery, $b->id);
    $first = rsGroupInvoice([$one->id => 100]);
    $second = rsGroupInvoice([$two->id => 200]);
    $mixed = rsGroupInvoice([$one->id => 50, $two->id => 50]);
    rsGroupBank('400.00', [$first->id => 100, $second->id => 200, $mixed->id => 100]);
    $snapshot = rsGroupAccountingSnapshot();
    $details = app(EntryDetails::class);
    $entries = $details->query($this->ledger->dimensionEntries(null, null), rsClassification: true)->get();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = $details->withRsInvoices($entries)->keyBy('purchase_product_group_id');
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
    expect($rows)->toHaveCount(2)->and((float) $rows->sum('amount'))->toBe(400.0)
        ->and((float) $rows[$a->id]->amount)->toBe(150.0)->and((float) $rows[$b->id]->amount)->toBe(250.0)
        ->and(array_column($rows[$a->id]->detail_rs_invoices, 'id'))->toBe([$first->id, $mixed->id])
        ->and(array_column($rows[$b->id]->detail_rs_invoices, 'id'))->toBe([$second->id, $mixed->id])
        ->and(rsInvoiceDetailHtml($rows[$a->id]))->not->toContain($second->document_number)
        ->and(rsInvoiceDetailHtml($rows[$b->id]))->not->toContain($first->document_number)
        ->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('ordinary manual expenses retain Expense Types and unlinked banks retain their classification status', function () {
    $type = app(ExpenseDimensions::class)->id('type', 'materials', $this->surgery);
    $expense = FinanceTransaction::create(['type' => 'expense', 'transaction_date' => '2026-03-01', 'category' => 'materials', 'amount' => 20,
        'currency' => 'GEL', 'payment_method' => 'bank_transfer', 'expense_direction_id' => $this->surgery, 'expense_type_id' => $type]);
    $bank = rsGroupBank('10.00', []);
    $bank->update(['bank_category_id' => BankCategory::where('code', 'operating_expense')->value('id')]);
    $rows = app(EntryDetails::class)->query($this->ledger->dimensionEntries(null, null), rsClassification: true)->get();
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(EntryDetails::class)->withRsInvoices($rows);
    expect(DB::getQueryLog())->toHaveCount(0);
    DB::disableQueryLog();
    $manual = $rows->firstWhere('entry_key', 'finance:'.$expense->id);
    expect(rsInvoiceDetailHtml($manual))->toContain('მასალები')->not->toContain('RS ზედნადებები')
        ->and(rsInvoiceDetailHtml($rows->firstWhere('entry_key', 'bank:'.$bank->id)))->toContain(__('expense-dimensions.review'), __('bank-rules.provenance.none'));
});

test('many invoice references use a compact expandable list without duplicate references or amounts', function () {
    $product = rsGroupProduct('Many invoices product', $this->surgery);
    $group = $this->catalog->createGroup(['name' => 'Many invoices group', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($product, $this->surgery, $group->id);
    $invoices = collect(range(1, 6))->map(fn () => rsGroupInvoice([$product->id => 10]));
    rsGroupBank('60.00', $invoices->mapWithKeys(fn ($invoice) => [$invoice->id => 10])->all());
    $snapshot = rsGroupAccountingSnapshot();
    $rows = rsInvoiceDetailRows();
    $html = rsInvoiceDetailHtml($rows->sole());
    expect($rows)->toHaveCount(1)->and((float) $rows->sole()->amount)->toBe(60.0)
        ->and($rows->sole()->detail_rs_invoices)->toHaveCount(6)
        ->and($html)->toContain('<details>', '<summary', '+4')->not->toContain('<details open');
    foreach ($invoices as $invoice) {
        expect(substr_count($html, '№'.$invoice->document_number))->toBe(1);
    }
    expect(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('an invoice with a zero-tetri share does not appear as a contributor to that Product Group', function () {
    $one = rsGroupProduct('Tiny share product', $this->surgery);
    $two = rsGroupProduct('Paid share product', $this->surgery);
    $a = $this->catalog->createGroup(['name' => 'Tiny share group', 'expense_direction_id' => $this->surgery]);
    $b = $this->catalog->createGroup(['name' => 'Paid share group', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($one, $this->surgery, $a->id);
    rsGroupClassify($two, $this->surgery, $b->id);
    $partial = rsGroupInvoice([$one->id => '0.01', $two->id => '9.99']);
    $full = rsGroupInvoice([$one->id => '1.00']);
    rsGroupBank('1.01', [$partial->id => '0.01', $full->id => '1.00']);
    $snapshot = rsGroupAccountingSnapshot();
    $rows = rsInvoiceDetailRows()->keyBy('purchase_product_group_id');
    expect((float) $rows[$a->id]->amount)->toBe(1.0)->and((float) $rows[$b->id]->amount)->toBe(0.01)
        ->and(array_column($rows[$a->id]->detail_rs_invoices, 'id'))->toBe([$full->id])
        ->and(array_column($rows[$b->id]->detail_rs_invoices, 'id'))->toBe([$partial->id])
        ->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('original bank details render the BankCategory relationship as its name instead of an enum array key', function () {
    $bank = rsGroupBank('10.00', []);
    expect($bank->fresh()->category)->toBeInstanceOf(BankCategory::class);
    $snapshot = rsGroupAccountingSnapshot();
    $page = Livewire::test(Finance::class)->mountAction('financeRecord', ['key' => 'bank:'.$bank->id])->assertActionMounted('financeRecord');
    $action = $page->instance()->getMountedAction();
    expect($action->getModalContent()->render())->toContain($bank->category->name)
        ->and($action->getExtraModalFooterActions())->toBeEmpty()->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('RS details action opens a read-only modal with all actual products and no editing or navigation controls', function () {
    $product = rsGroupProduct('Modal surgical product', $this->surgery);
    $group = $this->catalog->createGroup(['name' => 'Modal group', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($product, $this->surgery, $group->id);
    $invoice = rsGroupInvoice([$product->id => 150]);
    $invoice->items()->sole()->update(['unit' => 'pcs', 'quantity' => 3, 'unit_price' => 50]);
    $bank = rsGroupBank('75.00', [$invoice->id => 75]);
    $snapshot = rsGroupAccountingSnapshot();
    $totals = $this->ledger->pnlTotals(null, null)->toJson();
    $args = ['bank_id' => $bank->id, 'direction_id' => $this->surgery, 'group_id' => $group->id];
    $page = Livewire::test(Finance::class)->mountAction('rsExpenseDetails', $args)->assertActionMounted('rsExpenseDetails');
    $action = $page->instance()->getMountedAction();
    $html = $action->getModalContent()->render();
    expect($html)->toContain('№'.$invoice->document_number, 'Modal surgical product', 'pcs', '150.00', '75.00', 'ნახვა', '<details', 'სრული ღირებულება', 'ამ ჯგუფზე')
        ->not->toContain('რედაქტირება', 'wire:model', '<input', 'href=')
        ->and($action->getModalSubmitAction())->toBeNull()->and($action->getExtraModalFooterActions())->toBeEmpty();
    $page->unmountAction();
    expect(rsGroupAccountingSnapshot())->toBe($snapshot)->and($this->ledger->pnlTotals(null, null)->toJson())->toBe($totals);
});

test('RS modal scopes multi-invoice rows and partial amounts to the selected group and keeps full mixed invoice contents', function () {
    $a = rsGroupProduct('Selected group product', $this->surgery);
    $b = rsGroupProduct('Other group product', $this->surgery);
    $ga = $this->catalog->createGroup(['name' => 'Selected group', 'expense_direction_id' => $this->surgery]);
    $gb = $this->catalog->createGroup(['name' => 'Other group', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($a, $this->surgery, $ga->id);
    rsGroupClassify($b, $this->surgery, $gb->id);
    $mixed = rsGroupInvoice([$a->id => 100, $b->id => 100]);
    $selected = rsGroupInvoice([$a->id => 50]);
    $other = rsGroupInvoice([$b->id => 30]);
    $unlinked = rsGroupInvoice([$a->id => 999]);
    $bank = rsGroupBank('180.00', [$mixed->id => 100, $selected->id => 50, $other->id => 30]);
    $snapshot = rsGroupAccountingSnapshot();
    $service = app(RsBankExpenseDetails::class);
    $result = $service->read(['bank_id' => $bank->id, 'direction_id' => $this->surgery, 'group_id' => $ga->id]);
    $docs = $result['documents']->keyBy('id');
    expect($docs->keys()->all())->toBe([$mixed->id, $selected->id])
        ->and($result['invoiceTetri'])->toBe(25000)->and($result['bankAllocatedTetri'])->toBe(15000)->and($result['allocatedTetri'])->toBe(10000)
        ->and($docs[$mixed->id]['total_tetri'])->toBe(20000)->and($docs[$mixed->id]['bank_tetri'])->toBe(10000)->and($docs[$mixed->id]['group_tetri'])->toBe(5000)
        ->and($docs[$mixed->id]['items'])->toHaveCount(2)
        ->and($docs[$mixed->id]['items']->firstWhere('resolved_product_id', $a->id)->contributes)->toBeTrue()
        ->and($docs[$mixed->id]['items']->firstWhere('resolved_product_id', $b->id)->contributes)->toBeFalse();
    $html = view('filament.pages.finance-rs-invoice-details', $result)->render();
    expect($html)->toContain('Selected group product', 'Other group product', 'სხვა ჯგუფი')
        ->not->toContain($other->document_number, $unlinked->document_number)
        ->and(substr_count($html, '№'.$mixed->document_number))->toBe(1);
    $otherResult = $service->read(['bank_id' => $bank->id, 'direction_id' => $this->surgery, 'group_id' => $gb->id]);
    expect($otherResult['documents']->pluck('id')->all())->toBe([$mixed->id, $other->id])
        ->and($otherResult['allocatedTetri'])->toBe(8000)->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('RS modal does not duplicate invoice totals when several products share the same group', function () {
    $a = rsGroupProduct('First grouped product', $this->surgery);
    $b = rsGroupProduct('Second grouped product', $this->surgery);
    $group = $this->catalog->createGroup(['name' => 'Shared modal group', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($a, $this->surgery, $group->id);
    rsGroupClassify($b, $this->surgery, $group->id);
    $invoice = rsGroupInvoice([$a->id => 60, $b->id => 40]);
    $bank = rsGroupBank('100.00', [$invoice->id => 100]);
    $snapshot = rsGroupAccountingSnapshot();
    $result = app(RsBankExpenseDetails::class)->read(['bank_id' => $bank->id, 'direction_id' => $this->surgery, 'group_id' => $group->id]);
    expect($result['documents'])->toHaveCount(1)->and($result['documents']->sole()['items'])->toHaveCount(2)
        ->and($result['invoiceTetri'])->toBe(10000)->and($result['allocatedTetri'])->toBe(10000)
        ->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});

test('RS modal rejects unrelated group requests and unauthorized users without writes', function () {
    $product = rsGroupProduct('Protected modal product', $this->surgery);
    $group = $this->catalog->createGroup(['name' => 'Protected group', 'expense_direction_id' => $this->surgery]);
    rsGroupClassify($product, $this->surgery, $group->id);
    $invoice = rsGroupInvoice([$product->id => 100]);
    $bank = rsGroupBank('100.00', [$invoice->id => 100]);
    $snapshot = rsGroupAccountingSnapshot();
    $service = app(RsBankExpenseDetails::class);
    expect(fn () => $service->read(['bank_id' => $bank->id, 'direction_id' => $this->therapy, 'group_id' => $group->id]))->toThrow(HttpException::class);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    expect(fn () => $service->read(['bank_id' => $bank->id, 'direction_id' => $this->surgery, 'group_id' => $group->id]))->toThrow(HttpException::class)
        ->and(rsGroupAccountingSnapshot())->toBe($snapshot);
});
