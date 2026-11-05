<?php

use App\Filament\Pages\Bank;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\Bank\BankPurchaseSuggestions;
use App\Services\PurchaseReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-04 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->supplier = Supplier::create(['name' => 'Supplier Alpha', 'tax_id' => '012345678']);
    $this->matching = app(BankPurchaseMatching::class);
    $this->advice = app(BankPurchaseSuggestions::class);
});

function suggestedBank(string $amount = '100.00', array $extra = []): BankTransaction
{
    return BankTransaction::create(['transaction_date' => '2026-11-04 12:00:00', 'direction' => 'outflow', 'currency' => 'GEL', 'amount' => $amount,
        'counterparty_name' => 'Supplier Alpha', 'bank_category_id' => BankCategory::where('code', 'uncategorized')->value('id'),
        'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64), ...$extra]);
}

function suggestedInvoice($test, string $amount = '100.00', array $extra = []): Purchase
{
    $purchase = Purchase::create(['supplier_id' => $test->supplier->id, 'source' => 'rs', 'purchase_date' => '2026-11-03', 'document_number' => Str::random(12), ...$extra]);
    $purchase->items()->create(['item_name' => 'Synthetic item', 'quantity' => 1, 'unit_price' => $amount]);

    return $purchase->fresh();
}

function adviceStorage(): array
{
    return collect(['bank_transactions', 'bank_purchase_matches', 'purchases', 'purchase_items', 'suppliers', 'finance_transactions', 'cashbox_transactions', 'payments', 'employee_advance_entries'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

test('tax identity precedes names and supplier filtering requires explicit show all', function () {
    $bank = suggestedBank(extra: ['counterparty_name' => 'Other company', 'raw_data' => ['BeneficiaryDetails' => ['Inn' => '012-345-678']]]);
    $correct = suggestedInvoice($this);
    $other = Supplier::create(['name' => 'Other company', 'tax_id' => '987654321']);
    $wrong = suggestedInvoice($this, extra: ['supplier_id' => $other->id]);
    $before = adviceStorage();
    $result = $this->advice->browse($bank);
    expect($result['identity'])->toBe(['ids' => [$this->supplier->id], 'method' => 'tax'])
        ->and($result['documents']->pluck('id')->all())->toBe([$correct->id])
        ->and($this->advice->browse($bank, $wrong->document_number)['documents'])->toBeEmpty()
        ->and($this->advice->browse($bank, $wrong->document_number, true)['documents']->pluck('id')->all())->toBe([$wrong->id])
        ->and($this->advice->browse($bank, showAll: true)['suggested_ids'])->toBe([$correct->id])
        ->and(adviceStorage())->toBe($before);
});

test('unique normalized names match while legal prefix collisions stay ambiguous', function () {
    $bank = suggestedBank(extra: ['counterparty_name' => ' LLC "SUPPLIER   ALPHA" ']);
    suggestedInvoice($this);
    expect($this->advice->supplier($bank)['method'])->toBe('name');
    Supplier::create(['name' => 'LLC Supplier Alpha', 'tax_id' => '987654321']);
    $result = $this->advice->browse($bank);
    expect($result['identity']['method'])->toBe('ambiguous')->and($result['documents'])->toBeEmpty()
        ->and($result['combinations'])->toBeEmpty()
        ->and($this->advice->browse($bank, showAll: true)['documents'])->toHaveCount(1);
});

test('unknown or conflicting explicit tax IDs cannot fall back to a misleading name', function (array $raw, string $method) {
    $result = $this->advice->browse(suggestedBank(extra: ['raw_data' => $raw]));
    expect($result['identity']['method'])->toBe($method)->and($result['identity']['ids'])->toBeEmpty();
})->with([
    [['beneficiaryDetails' => ['inn' => '987654321']], 'unmatched'],
    [['counterparty_tax_id' => '012345678', 'beneficiaryDetails' => ['inn' => '987654321']], 'ambiguous'],
]);

test('Excel counterparty tax headers are supported but invoice digits and the senders tax ID are not identity', function () {
    $bank = suggestedBank(extra: ['counterparty_name' => 'Unknown', 'raw_data' => ['cells' => [['header' => 'კონტრაგენტის საიდენტიფიკაციო კოდი', 'value' => '012345678']]]]);
    expect($this->advice->supplier($bank)['method'])->toBe('tax');
    $bank->raw_data = ['senderDetails' => ['inn' => '012345678']];
    $bank->description = 'Invoice 012345678';
    expect($this->advice->supplier($bank)['method'])->toBe('unmatched');
});

test('only consistent previous Owner confirmed links provide alias evidence', function () {
    $old = suggestedBank(extra: ['counterparty_name' => 'Verified old trading name', 'counterparty_account' => 'GE-ALPHA']);
    $invoice = suggestedInvoice($this);
    $this->matching->confirm($old->id, [['purchase_id' => $invoice->id, 'amount' => 100]], auth()->user());
    $bank = suggestedBank(extra: ['counterparty_name' => 'Verified old trading name', 'counterparty_account' => 'GE-ALPHA']);
    expect($this->advice->supplier($bank))->toBe(['ids' => [$this->supplier->id], 'method' => 'alias']);
    $other = Supplier::create(['name' => 'Other supplier']);
    $otherInvoice = suggestedInvoice($this, extra: ['supplier_id' => $other->id]);
    $conflict = suggestedBank(extra: ['counterparty_name' => 'Verified old trading name', 'counterparty_account' => 'GE-ALPHA']);
    $this->matching->confirm($conflict->id, [['purchase_id' => $otherInvoice->id, 'amount' => 100]], auth()->user());
    expect($this->advice->supplier($bank)['method'])->toBe('ambiguous');
});

test('exact single and multiple document alternatives are advisory with integer tetri totals', function () {
    $bank = suggestedBank();
    $single = suggestedInvoice($this);
    $one = suggestedInvoice($this, '60.00');
    $two = suggestedInvoice($this, '40.00');
    $before = adviceStorage();
    $result = $this->advice->browse($bank);
    expect($result['combinations'])->toHaveCount(2)
        ->and($result['combinations'][0]['documents']->pluck('id')->all())->toBe([$single->id])
        ->and($result['combinations'][1]['documents']->pluck('id')->sort()->values()->all())->toBe(collect([$one->id, $two->id])->sort()->values()->all());
    foreach ($result['combinations'] as $combination) {
        expect($combination['total_tetri'])->toBe(10000)->and(is_int($combination['total_tetri']))->toBeTrue();
    }
    expect(adviceStorage())->toBe($before);
});

test('a unique company name conflicting with a verified historical alias stays ambiguous', function () {
    $other = Supplier::create(['name' => 'Different legal supplier']);
    $invoice = suggestedInvoice($this, extra: ['supplier_id' => $other->id]);
    $old = suggestedBank(extra: ['counterparty_name' => ' LLC "SUPPLIER   ALPHA" ']);
    $this->matching->confirm($old->id, [['purchase_id' => $invoice->id, 'amount' => 100]], auth()->user());
    $result = $this->advice->browse(suggestedBank());
    expect($result['identity']['method'])->toBe('ambiguous')->and($result['documents'])->toBeEmpty()
        ->and($result['combinations'])->toBeEmpty();
});

test('tetri combinations do not lose decimal fractions', function (string $one, string $two, string $total) {
    suggestedInvoice($this, $one);
    suggestedInvoice($this, $two);
    $result = $this->advice->browse(suggestedBank($total));
    expect($result['combinations'])->toHaveCount(1)
        ->and($result['combinations'][0]['total_tetri'])->toBe($this->matching->cents($total));
})->with([['0.10', '0.20', '0.30'], ['32.54', '67.46', '100.00'], ['1.01', '2.02', '3.03']]);

test('three and four invoice combinations work within bounded search', function (int $count, string $target) {
    foreach (range(1, $count) as $unused) {
        suggestedInvoice($this, '7.00');
    }
    $result = $this->advice->browse(suggestedBank($target));
    expect($result['combinations'][0]['documents'])->toHaveCount($count)
        ->and($result['combinations'][0]['total_tetri'])->toBe($this->matching->cents($target));
})->with([[3, '21.00'], [4, '28.00']]);

test('outstanding amounts exclude other payments and fully consumed invoices but allow editing own links', function () {
    $invoice = suggestedInvoice($this);
    $old = suggestedBank('60.00');
    $this->matching->confirm($old->id, [['purchase_id' => $invoice->id, 'amount' => 60]], auth()->user());
    $bank = suggestedBank('40.00');
    $result = $this->advice->browse($bank);
    expect($result['combinations'][0]['total_tetri'])->toBe(4000)
        ->and($this->matching->cents($result['documents']->sole()->outstanding))->toBe(4000);
    $this->matching->confirm($bank->id, [['purchase_id' => $invoice->id, 'amount' => 40]], auth()->user());
    expect($this->advice->browse(suggestedBank('1.00'))['documents'])->toBeEmpty()
        ->and($this->matching->cents($this->advice->browse($old)['documents']->sole()->outstanding))->toBe(6000);
});

test('date proximity ranks first with earlier dates preferred for equal distance', function () {
    $bank = suggestedBank();
    $later = suggestedInvoice($this, extra: ['purchase_date' => '2026-11-05']);
    $earlier = suggestedInvoice($this, extra: ['purchase_date' => '2026-11-03']);
    $result = $this->advice->browse($bank);
    expect($result['combinations'][0]['documents']->sole()->id)->toBe($earlier->id)
        ->and($result['combinations'][1]['documents']->sole()->id)->toBe($later->id);
    $closest = suggestedInvoice($this, extra: ['purchase_date' => '2026-11-04']);
    $bank->description = 'Payment '.$earlier->document_number;
    expect($this->advice->browse($bank)['combinations'][0]['documents']->sole()->id)->toBe($closest->id);
});

test('RS tax-prefixed supplier labels expose the fourteen existing-style invoices without inventing a match', function (array $raw, string $method) {
    $supplier = Supplier::create(['name' => '(405182092) შპს ინდივიდუალლაინ', 'tax_id' => null]);
    $bank = suggestedBank('6670.00', ['counterparty_name' => 'შპს ინდივიდუალლაინ', 'transaction_date' => '2026-10-30', 'raw_data' => $raw]);
    $ids = [];
    foreach (['600', '600', '3800', '3800', '240', '3800', '3800', '3800', '3800', '2450', '3800', '1900', '1400', '1900'] as $amount) {
        $ids[] = suggestedInvoice($this, $amount, ['supplier_id' => $supplier->id, 'purchase_date' => '2026-10-19'])->id;
    }
    $other = suggestedInvoice($this);
    $before = adviceStorage();
    $result = $this->advice->browse($bank);
    expect($result['identity'])->toBe(['ids' => [$supplier->id], 'method' => $method])
        ->and($result['documents']->pluck('id')->sort()->values()->all())->toBe(collect($ids)->sort()->values()->all())
        ->and($result['combinations'])->toBeEmpty()
        ->and($this->advice->browse($bank, showAll: true)['documents']->pluck('id'))->toContain($other->id)
        ->and($this->advice->browse($bank, search: '405182092')['documents'])->toHaveCount(14)
        ->and(adviceStorage())->toBe($before);
})->with([[[], 'name'], [['BeneficiaryDetails' => ['Inn' => '405182092']], 'tax']]);

test('explicit tax IDs match RS label prefixes and include only proven sibling supplier rows', function () {
    $prefixed = Supplier::create(['name' => '(012-345-678) LLC Alpha trading', 'tax_id' => null]);
    $invoice = suggestedInvoice($this, extra: ['supplier_id' => $prefixed->id]);
    $bank = suggestedBank(extra: ['counterparty_name' => 'Different bank label', 'raw_data' => ['BeneficiaryDetails' => ['Inn' => '012 345 678']]]);
    expect($this->advice->supplier($bank))->toBe(['ids' => [$this->supplier->id, $prefixed->id], 'method' => 'tax'])
        ->and($this->advice->browse($bank)['documents']->sole()->id)->toBe($invoice->id);
    $bank->raw_data = null;
    $bank->counterparty_name = 'LLC Alpha trading';
    expect($this->advice->supplier($bank))->toBe(['ids' => [$this->supplier->id, $prefixed->id], 'method' => 'name']);
});

test('conflicting embedded tax IDs and same names with different legal identities remain ambiguous', function () {
    $supplier = Supplier::create(['name' => '(987654321) Supplier Alpha', 'tax_id' => '012345678']);
    expect($this->advice->supplier(suggestedBank())['method'])->toBe('ambiguous')
        ->and($this->advice->supplier(suggestedBank(extra: ['raw_data' => ['counterparty_tax_id' => '012345678']]))['method'])->toBe('ambiguous');
    $supplier->update(['tax_id' => null]);
    expect($this->advice->supplier(suggestedBank())['method'])->toBe('ambiguous');
    $supplier->update(['name' => 'Dental 012345678 Supplies']);
    expect($this->advice->supplier(suggestedBank(extra: ['counterparty_name' => '(987654321) Supplier Alpha']))['ids'])->toBeEmpty();
});

test('6670 GEL suggestions find exact one two three and four document sums automatically', function (array $amounts) {
    foreach ($amounts as $amount) {
        suggestedInvoice($this, $amount);
    }
    $result = $this->advice->browse(suggestedBank('6670.00'));
    expect($result['combinations'])->toHaveCount(1)
        ->and($result['combinations'][0]['documents'])->toHaveCount(count($amounts))
        ->and($result['combinations'][0]['total_tetri'])->toBe(667000);
})->with([
    [['6670.00']], [['3800.00', '2870.00']], [['3800.00', '1900.00', '970.00']], [['2450.00', '1900.00', '1400.00', '920.00']],
]);

test('rejected source rows do not consume a visible page or the eligible suggestion pool', function () {
    foreach (range(1, 61) as $unused) {
        suggestedInvoice($this)->forceFill(['rs_import_payload' => ['pending_import' => true]])->save();
    }
    $eligible = suggestedInvoice($this);
    $result = $this->advice->browse(suggestedBank());
    expect($result['documents']->sole()->id)->toBe($eligible->id)
        ->and($result['has_more'])->toBeFalse()
        ->and($result['combinations'][0]['documents']->sole()->id)->toBe($eligible->id);
});

test('larger invoices cannot crowd four-part exact matches out of the bounded pool', function () {
    foreach (range(1, 61) as $unused) {
        suggestedInvoice($this, '6671.00');
    }
    foreach (['2450.00', '1900.00', '1400.00', '920.00'] as $amount) {
        suggestedInvoice($this, $amount);
    }
    $result = $this->advice->browse(suggestedBank('6670.00'));
    expect($result['documents'])->toHaveCount(20)->and($result['has_more'])->toBeTrue()
        ->and($result['combinations'][0]['documents'])->toHaveCount(4)
        ->and($result['combinations'][0]['total_tetri'])->toBe(667000);
});

test('four-part search reaches the full sixty-document pool without a cubic traversal', function () {
    foreach (range(1, 56) as $unused) {
        suggestedInvoice($this, '6000.00');
    }
    foreach (['2450.00', '1900.00', '1400.00', '920.00'] as $amount) {
        suggestedInvoice($this, $amount);
    }
    expect($this->advice->browse(suggestedBank('6670.00'))['combinations'][0]['documents'])->toHaveCount(4);
});

test('exact document references rank alternatives without matching reference substrings', function () {
    suggestedInvoice($this, extra: ['document_number' => 'INV-1234']);
    $exact = suggestedInvoice($this, extra: ['document_number' => 'INV-123']);
    $bank = suggestedBank(extra: ['description' => 'Payment for INV-123']);
    expect($this->advice->browse($bank)['combinations'][0]['documents']->sole()->id)->toBe($exact->id);
});

test('cancelled pending returns and invalid invoices are not suggested', function (array $payload) {
    $invoice = suggestedInvoice($this);
    $invoice->forceFill(['rs_import_payload' => $payload])->save();
    $bank = suggestedBank();
    expect($this->advice->browse($bank)['documents'])->toBeEmpty()->and($this->advice->browse($bank)['combinations'])->toBeEmpty();
    expect(fn () => $this->matching->confirm($bank->id, [['purchase_id' => $invoice->id, 'amount' => 100]], auth()->user()))->toThrow(ValidationException::class);
})->with([[['document_kind' => 'cancelled']], [['document_kind' => 'return']], [['pending_import' => true]], [['validation_errors' => ['Synthetic invalid import']]]]);

test('non GEL bank amounts have no invented currency matches', function (string $currency) {
    suggestedInvoice($this);
    $bank = suggestedBank(extra: ['currency' => $currency]);
    $result = $this->advice->browse($bank, showAll: true);
    expect($result['documents'])->toBeEmpty()->and($result['combinations'])->toBeEmpty()
        ->and($result['errors'])->not->toBeEmpty();
})->with(['USD', 'EUR']);

test('validated retained RS rows are batch checked and confirmation still rereads source items', function () {
    $invoice = suggestedInvoice($this);
    $item = $invoice->items()->sole();
    $hash = Str::random(64);
    $item->update(['source_row_hash' => $hash]);
    $invoice->forceFill(['rs_import_payload' => ['validated' => true, 'document_total' => '100.00',
        'source_rows' => [$hash => ['mapped' => ['quantity' => '1.000000', 'unit_price' => '100.00', 'line_total' => '100.00']]]]])->save();
    $bank = suggestedBank();
    expect($this->advice->browse($bank)['combinations'])->toHaveCount(1);
    $invoice->load('items');
    DB::table('purchase_items')->where('id', $item->id)->update(['line_total' => 80]);
    expect(fn () => app(PurchaseReconciliation::class)->assertValid($invoice))->toThrow(ValidationException::class)
        ->and($this->advice->browse($bank)['documents'])->toBeEmpty();
    expect(fn () => $this->matching->confirm($bank->id, [['purchase_id' => $invoice->id, 'amount' => 80]], auth()->user()))->toThrow(ValidationException::class);
});

test('no exact match leaves all eligible supplier documents accessible for manual partial payment', function () {
    $invoice = suggestedInvoice($this, '130.00');
    $result = $this->advice->browse(suggestedBank());
    expect($result['combinations'])->toBeEmpty()->and($result['documents']->sole()->id)->toBe($invoice->id);
});

test('large supplier lists are paginated independently of the bounded combination pool', function () {
    foreach (range(1, 85) as $unused) {
        suggestedInvoice($this, '3.00');
    }
    $bank = suggestedBank('10.00');
    $first = $this->advice->browse($bank);
    $last = $this->advice->browse($bank, page: 5);
    expect($first['limited'])->toBeTrue()->and($first['documents'])->toHaveCount(20)->and($first['has_more'])->toBeTrue()
        ->and($last['documents'])->toHaveCount(5)->and($last['has_more'])->toBeFalse()
        ->and($first['combinations'])->toBeEmpty();
});

test('modal shows badges without selecting and show all preserves search', function () {
    $bank = suggestedBank();
    $one = suggestedInvoice($this, '60.00');
    $two = suggestedInvoice($this, '40.00');
    $other = Supplier::create(['name' => 'Other supplier']);
    $foreign = suggestedInvoice($this, extra: ['supplier_id' => $other->id]);
    $before = adviceStorage();
    $page = Livewire::test(Bank::class)->mountAction('rsMatching', ['transaction' => $bank->id])->assertSet('rsDocuments', []);
    $html = $page->instance()->getMountedAction()->getModalContent()->render();
    expect($html)->toContain('სავარაუდო', $one->document_number, $two->document_number)->not->toContain($foreign->document_number);
    expect(adviceStorage())->toBe($before);
    $page->set('rsShowAllSuppliers', true)->set('rsSearch', $foreign->document_number);
    expect($page->instance()->getMountedAction()->getModalContent()->render())->toContain($foreign->document_number);
    $page->call('toggleRsDocument', $one->id)->assertSet('rsDocuments.'.$one->id, '60.00');
    expect(DB::table('bank_purchase_matches')->count())->toBe(0);
    $page->call('toggleRsDocument', $two->id)->assertSet('rsDocuments.'.$two->id, '40.00')->callMountedAction()->assertHasNoErrors();
    expect(DB::table('bank_purchase_matches')->count())->toBe(2);
});

test('over allocation duplicate submissions and stale outstanding amounts remain protected', function () {
    $bank = suggestedBank();
    $invoice = suggestedInvoice($this, '130.00');
    $page = Livewire::test(Bank::class)->mountAction('rsMatching', ['transaction' => $bank->id]);
    $page->call('toggleRsDocument', $invoice->id)->assertSet('rsDocuments.'.$invoice->id, '100.00');
    $page->set('rsDocuments.'.$invoice->id, '101.00')->callMountedAction()->assertHasErrors('rsDocuments');
    expect(DB::table('bank_purchase_matches')->count())->toBe(0);
    $other = suggestedBank('60.00');
    $this->matching->confirm($other->id, [['purchase_id' => $invoice->id, 'amount' => 60]], auth()->user());
    expect(fn () => $this->matching->confirm($bank->id, [['purchase_id' => $invoice->id, 'amount' => 100]], auth()->user()))->toThrow(ValidationException::class);
    $this->matching->confirm($bank->id, [['purchase_id' => $invoice->id, 'amount' => 70]], auth()->user());
    $this->matching->confirm($bank->id, [['purchase_id' => $invoice->id, 'amount' => 70]], auth()->user());
    expect(DB::table('bank_purchase_matches')->where('bank_transaction_id', $bank->id)->count())->toBe(1);
    expect(fn () => $this->matching->confirm($bank->id, [['purchase_id' => $invoice->id, 'amount' => 30], ['purchase_id' => $invoice->id, 'amount' => 30]], auth()->user()))->toThrow(ValidationException::class);
});
