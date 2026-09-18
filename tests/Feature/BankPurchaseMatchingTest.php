<?php

use App\Filament\Pages\Bank;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\Bank\BankReport;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\PurchaseCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
});

function rsBank(float $amount = 1000, array $extra = []): BankTransaction
{
    return BankTransaction::create([...[
        'transaction_date' => today(), 'direction' => 'outflow', 'amount' => $amount, 'currency' => 'GEL',
        'counterparty_name' => 'Dentstal', 'operation_type' => 'PMD', 'source' => 'api',
        'bank_category_id' => BankCategory::where('code', 'uncategorized')->value('id'),
        'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64), 'raw_data' => ['original' => 'unchanged'],
    ], ...$extra]);
}

function rsDocument(array $directions): Purchase
{
    $supplier = Supplier::firstOrCreate(['name' => 'Dentstal']);
    $purchase = Purchase::create(['supplier_id' => $supplier->id, 'source' => 'rs', 'purchase_date' => today(), 'document_number' => Str::random(8)]);
    foreach ($directions as $code => $amount) {
        $product = app(PurchaseCatalog::class)->resolve($supplier->id, Str::random(12));
        if ($code !== 'unknown') {
            app(PurchaseCatalog::class)->assignDirection($product, app(ExpenseDimensions::class)->id('direction', $code));
        }
        $purchase->items()->create(['purchase_product_id' => $product->id, 'quantity' => 1, 'unit_price' => $amount]);
    }

    return $purchase->fresh();
}

function confirmRs(BankTransaction $bank, array $documents): void
{
    app(BankPurchaseMatching::class)->confirm($bank->id, collect($documents)->map(fn ($amount, $id) => ['purchase_id' => $id, 'amount' => $amount])->values()->all(), auth()->user());
}

test('one RS document allocates one bank debit without creating financial movements', function () {
    $bank = rsBank();
    $purchase = rsDocument(['surgery' => 500, 'orthopedics' => 200, 'therapy' => 300]);
    $before = $bank->fresh()->getAttributes();
    confirmRs($bank, [$purchase->id => 1000]);
    confirmRs($bank, [$purchase->id => 1000]);
    $matching = app(BankPurchaseMatching::class);
    $summary = $matching->summary($bank->id);
    expect($summary->status)->toBe('matched')->and((float) $summary->allocated)->toBe(1000.0)
        ->and((float) $summary->unallocated)->toBe(0.0)
        ->and($bank->fresh()->getAttributes())->toBe($before)
        ->and(DB::table('bank_transactions')->count())->toBe(1)
        ->and(DB::table('bank_purchase_matches')->count())->toBe(1);
    foreach (['finance_transactions', 'cashbox_transactions', 'payments'] as $table) {
        expect(DB::table($table)->count())->toBe(0);
    }
    $ledger = app(AccountingLedger::class);
    expect($ledger->pnl(null, null, 'bank')->count())->toBe(1)
        ->and((float) $ledger->pnlTotals(null, null, 'bank')->sole()->expenses)->toBe(1000.0);
    $entries = $ledger->dimensionEntries(null, null, 'bank');
    $groups = $ledger->dimensionGroups($entries)->keyBy('dimension_code');
    expect((float) $groups['surgery']->amount)->toBe(500.0)
        ->and((float) $groups['orthopedics']->amount)->toBe(200.0)
        ->and((float) $groups['therapy']->amount)->toBe(300.0)
        ->and((float) $ledger->dimensionEntries(null, null, 'bank', grouping: 'type')->sum('amount'))->toBe(1000.0);
});

test('multiple documents and unknown mappings update live without reposting expense', function () {
    $bank = rsBank();
    $one = rsDocument(['surgery' => 500]);
    $two = rsDocument(['therapy' => 300, 'unknown' => 200]);
    confirmRs($bank, [$one->id => 500, $two->id => 500]);
    $matching = app(BankPurchaseMatching::class);
    expect($matching->summary($bank->id)->status)->toBe('partial')
        ->and((float) $matching->summary($bank->id)->allocated)->toBe(800.0)
        ->and((float) $matching->summary($bank->id)->unallocated)->toBe(200.0);
    app(PurchaseCatalog::class)->assignDirection($two->items()->latest('id')->first()->purchaseProduct, app(ExpenseDimensions::class)->id('direction', 'orthopedics'));
    expect($matching->summary($bank->id)->status)->toBe('matched')
        ->and((float) app(AccountingLedger::class)->dimensionEntries(null, null, 'bank')->sum('amount'))->toBe(1000.0);
});

test('differences remain explicit and overmatched documents are not silently scaled', function () {
    $bank = rsBank();
    $short = rsDocument(['surgery' => 980]);
    confirmRs($bank, [$short->id => 980]);
    $summary = app(BankPurchaseMatching::class)->summary($bank->id);
    expect((float) $summary->difference)->toBe(20.0)->and((float) $summary->unallocated)->toBe(20.0);
    $large = rsDocument(['therapy' => 1020]);
    confirmRs($bank, [$large->id => 1020]);
    $summary = app(BankPurchaseMatching::class)->summary($bank->id);
    expect((float) $summary->difference)->toBe(-20.0)->and((float) $summary->allocated)->toBe(0.0)
        ->and((float) $summary->unallocated)->toBe(1000.0)->and($summary->status)->toBe('partial');
    expect((float) app(AccountingLedger::class)->dimensionEntries(null, null, 'bank')->sum('amount'))->toBe(1000.0);
    confirmRs($bank, [$large->id => 1000]);
    expect((float) app(BankPurchaseMatching::class)->summary($bank->id)->allocated)->toBe(1000.0);
});

test('partial invoice payments conserve cents and cannot reuse already paid portions', function () {
    $purchase = rsDocument(['surgery' => 1, 'therapy' => 1, 'orthopedics' => 1]);
    $bank = rsBank(1);
    confirmRs($bank, [$purchase->id => 1]);
    $shares = app(BankPurchaseMatching::class)->distribution()->where('bank_transaction_id', $bank->id)->pluck('amount')->map(fn ($amount) => (float) $amount)->sort()->values()->all();
    expect($shares)->toBe([0.33, 0.33, 0.34]);
    $second = rsBank(2);
    confirmRs($second, [$purchase->id => 2]);
    expect(fn () => confirmRs(rsBank(1), [$purchase->id => 1]))->toThrow(ValidationException::class);
});

test('unmatching restores normal manual categories without changing bank amount', function () {
    $dimensions = app(ExpenseDimensions::class);
    $direction = $dimensions->id('direction', 'general');
    $bank = rsBank(1000, ['bank_category_id' => BankCategory::where('code', 'operating_expense')->value('id'),
        'expense_direction_id' => $direction, 'expense_type_id' => $dimensions->id('type', 'rent', $direction)]);
    $purchase = rsDocument(['surgery' => 1000]);
    confirmRs($bank, [$purchase->id => 1000]);
    confirmRs($bank, []);
    expect(app(BankPurchaseMatching::class)->summary($bank->id))->toBeNull()
        ->and((float) app(AccountingLedger::class)->pnlTotals(null, null, 'bank')->sole()->expenses)->toBe(1000.0)
        ->and(app(AccountingLedger::class)->dimensionEntries(null, null, 'bank')->sole()->expense_direction_id)->toBe($direction);
});

test('RS filters combine with bank date currency account and search filters', function () {
    $matched = rsBank(1000, ['account_identifier' => 'GEtest']);
    $partial = rsBank();
    $unmatched = rsBank();
    rsBank(1000, ['direction' => 'inflow']);
    $one = rsDocument(['surgery' => 1000]);
    $two = rsDocument(['unknown' => 1000]);
    confirmRs($matched, [$one->id => 1000]);
    confirmRs($partial, [$two->id => 1000]);
    $report = app(BankReport::class);
    expect($report->query(['rsStatus' => 'matched', 'currency' => 'GEL', 'dateFrom' => today()->toDateString(), 'account' => 'GEtest', 'search' => 'Dentstal'])->pluck('id')->all())->toBe([$matched->id])
        ->and($report->query(['rsStatus' => 'partial'])->pluck('id')->all())->toBe([$partial->id])
        ->and($report->query(['rsStatus' => 'unmatched'])->pluck('id')->all())->toBe([$unmatched->id]);
});

test('non purchase movements and non owners cannot confirm matches', function () {
    $purchase = rsDocument(['surgery' => 1000]);
    foreach ([['direction' => 'inflow'], ['currency' => 'USD'], ['operation_type' => 'COM'],
        ['bank_category_id' => BankCategory::where('code', 'internal_transfer')->value('id')]] as $extra) {
        expect(fn () => confirmRs(rsBank(1000, $extra), [$purchase->id => 1000]))->toThrow(ValidationException::class);
    }
    $bank = rsBank();
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    expect(fn () => confirmRs($bank, [$purchase->id => 1000]))->toThrow(HttpException::class);
});

test('bank RS action suggests documents and saves only after confirmation', function () {
    $bank = rsBank();
    $purchase = rsDocument(['surgery' => 1000]);
    $page = Livewire::test(Bank::class)->mountAction('rsMatching', ['transaction' => $bank->id])->assertActionMounted('rsMatching');
    expect($page->instance()->getMountedAction()->getModalContent()->render())->toContain($purchase->document_number);
    $page->call('toggleRsDocument', $purchase->id);
    expect(DB::table('bank_purchase_matches')->count())->toBe(0);
    $page->callMountedAction()->assertHasNoErrors();
    expect(DB::table('bank_purchase_matches')->count())->toBe(1);
    $page->set('rsStatus', 'matched')->assertSee(__('bank-rs.matched'));
});

test('saved matching survives document edits safely and existing ERP exclusion still wins', function () {
    $bank = rsBank();
    $purchase = rsDocument(['surgery' => 1000]);
    confirmRs($bank, [$purchase->id => 1000]);
    $purchase->items()->first()->update(['line_total' => 900]);
    $summary = app(BankPurchaseMatching::class)->summary($bank->id);
    expect($summary->status)->toBe('partial')->and((float) $summary->unallocated)->toBe(1000.0)
        ->and((float) app(AccountingLedger::class)->dimensionEntries(null, null, 'bank')->sum('amount'))->toBe(1000.0);
    $bank->update(['exclude_from_pnl' => true]);
    expect(app(AccountingLedger::class)->pnl(null, null, 'bank')->count())->toBe(0)
        ->and(app(AccountingLedger::class)->dimensionEntries(null, null, 'bank')->count())->toBe(0);
});

test('RS suggestions prioritize supplier identity and allow tax or document search', function () {
    $bank = rsBank();
    $likely = rsDocument(['surgery' => 980]);
    $other = rsDocument(['therapy' => 1000]);
    $supplier = Supplier::create(['name' => 'Other vendor', 'tax_id' => '123456789']);
    $other->update(['supplier_id' => $supplier->id]);
    $matching = app(BankPurchaseMatching::class);
    expect($matching->suggestions($bank)->first()->id)->toBe($likely->id)
        ->and($matching->suggestions($bank, $supplier->tax_id)->sole()->id)->toBe($other->id)
        ->and($matching->suggestions($bank, $likely->document_number)->sole()->id)->toBe($likely->id);
});

test('allocation and Bank status reporting use aggregate queries without per-document reads', function () {
    $bank = rsBank();
    $one = rsDocument(['surgery' => 500]);
    $two = rsDocument(['therapy' => 500]);
    confirmRs($bank, [$one->id => 500, $two->id => 500]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(BankPurchaseMatching::class)->summary($bank->id);
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::flushQueryLog();
    app(BankReport::class)->query(['includeRs' => true])->get();
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
    expect(app(AccountingLedger::class)->dimensionEntries(null, null, 'cash')->toSql())->not->toContain('bank_purchase_matches');
});

test('invalid replacement leaves a previously confirmed match intact', function () {
    $bank = rsBank();
    $purchase = rsDocument(['surgery' => 1000]);
    confirmRs($bank, [$purchase->id => 1000]);
    $replacement = rsDocument(['therapy' => 500]);
    expect(fn () => confirmRs($bank, [$replacement->id => 1000]))->toThrow(ValidationException::class);
    expect(DB::table('bank_purchase_matches')->where('bank_transaction_id', $bank->id)->pluck('purchase_id')->all())->toBe([$purchase->id]);
    expect(app(BankPurchaseMatching::class)->summary($bank->id)->status)->toBe('matched');
});
