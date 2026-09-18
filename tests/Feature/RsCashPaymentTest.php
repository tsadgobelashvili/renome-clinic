<?php

use App\Filament\Resources\Purchases\Pages\EditPurchase;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\CashboxTransaction;
use App\Models\FinanceOpeningBalance;
use App\Models\FinanceTransaction;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use App\Services\FinanceUsdUsageService;
use App\Services\PurchaseCashPayment;
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
    FinanceOpeningBalance::create(['source' => 'cash', 'account_identifier' => 'cash', 'currency' => 'GEL', 'effective_date' => today(), 'amount' => 5000]);
});

function cashRsDocument(array $groups = ['surgery' => 500, 'orthopedics' => 200, 'therapy' => 300]): Purchase
{
    $supplier = Supplier::firstOrCreate(['name' => 'Cash RS supplier']);
    $purchase = Purchase::create(['supplier_id' => $supplier->id, 'source' => 'rs', 'purchase_date' => today(), 'document_number' => Str::random(8)]);
    foreach ($groups as $code => $amount) {
        $product = app(PurchaseCatalog::class)->resolve($supplier->id, Str::random(12));
        if ($code !== 'unknown') {
            app(PurchaseCatalog::class)->assignDirection($product, app(ExpenseDimensions::class)->id('direction', $code));
        }
        $purchase->items()->create(['purchase_product_id' => $product->id, 'quantity' => 1, 'unit_price' => $amount]);
    }

    return $purchase->fresh();
}

function cashRsBank(): BankTransaction
{
    return BankTransaction::create([
        'transaction_date' => today(), 'direction' => 'outflow', 'amount' => 1000, 'currency' => 'GEL',
        'operation_type' => 'PMD', 'source' => 'api', 'bank_category_id' => BankCategory::where('code', 'uncategorized')->value('id'),
        'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64),
    ]);
}

test('RS cash is off by default and saving or cancelling confirmation posts nothing', function () {
    $purchase = cashRsDocument();
    $page = Livewire::test(EditPurchase::class, ['record' => $purchase->id])->assertFormSet(['cash_paid' => false]);
    $page->set('data.cash_paid', true)->assertActionMounted('cashPayment');
    expect(FinanceTransaction::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
    $page->call('unmountAction')->fillForm(['notes' => 'Saved without payment'])->call('save')->assertHasNoFormErrors();
    expect(FinanceTransaction::count())->toBe(0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(5000.0);
});

test('confirmed cash deducts once and RS directions allocate one expense', function () {
    $purchase = cashRsDocument();
    Livewire::test(EditPurchase::class, ['record' => $purchase->id])->mountAction('cashPayment')
        ->callMountedAction()->assertHasNoActionErrors()->assertFormSet(['cash_paid' => true]);
    $service = app(PurchaseCashPayment::class);
    $posted = $service->post($purchase->id, auth()->user());
    expect($service->post($purchase->id, auth()->user())->id)->toBe($posted->id)
        ->and(FinanceTransaction::count())->toBe(1)->and(CashboxTransaction::count())->toBe(1)
        ->and(BankTransaction::count())->toBe(0)->and(DB::table('bank_purchase_matches')->count())->toBe(0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(4000.0);
    $ledger = app(AccountingLedger::class);
    expect((float) $ledger->pnlTotals(null, null)->sole()->expenses)->toBe(1000.0);
    foreach (['all', 'cash'] as $source) {
        $entries = $ledger->dimensionEntries(null, null, $source, 'GEL', 'clinic');
        $groups = $ledger->dimensionGroups($entries)->keyBy('dimension_code');
        expect((float) $groups['surgery']->amount)->toBe(500.0)
            ->and((float) $groups['orthopedics']->amount)->toBe(200.0)
            ->and((float) $groups['therapy']->amount)->toBe(300.0)
            ->and((float) $ledger->dimensionEntries(null, null, $source, grouping: 'type')->sum('amount'))->toBe(1000.0);
    }
    expect($ledger->dimensionEntries(null, null, 'cash', businessSource: 'israeli')->count())->toBe(0)
        ->and($ledger->dimensionEntries(today()->addDay()->toDateString(), null, 'cash')->count())->toBe(0);
});

test('unknown allocation updates after categorization without another deduction or save posting', function () {
    $purchase = cashRsDocument(['surgery' => 600, 'unknown' => 400]);
    app(PurchaseCashPayment::class)->post($purchase->id, auth()->user());
    $ledger = app(AccountingLedger::class);
    expect((float) $ledger->dimensionEntries(null, null)->whereNull('expense_direction_id')->sum('amount'))->toBe(400.0);
    $item = $purchase->items()->latest('id')->first();
    $therapy = app(ExpenseDimensions::class)->id('direction', 'therapy');
    $page = Livewire::test(EditPurchase::class, ['record' => $purchase->id])->assertSee('გაუნაწილებელი: 400.00');
    $data = $page->get('data.items');
    foreach ($data as &$row) {
        if ((int) $row['purchase_product_id'] === (int) $item->purchase_product_id) {
            $row['expense_direction_id'] = $therapy;
        }
    }
    unset($row);
    $page->fillForm(['items' => $data, 'notes' => 'Categorized later'])->call('save')->assertHasNoFormErrors();
    expect((float) $ledger->dimensionEntries(null, null)->where('expense_direction_id', $therapy)->sum('amount'))->toBe(400.0)
        ->and((float) $ledger->dimensionEntries(null, null)->whereNull('expense_direction_id')->sum('amount'))->toBe(0.0)
        ->and(FinanceTransaction::count())->toBe(1)->and(CashboxTransaction::count())->toBe(1)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(4000.0);
});

test('bank and cash payment are mutually exclusive in both directions', function () {
    $purchase = cashRsDocument();
    $bank = cashRsBank();
    $matching = app(BankPurchaseMatching::class);
    $documents = [['purchase_id' => $purchase->id, 'amount' => 1000]];
    $matching->confirm($bank->id, $documents, auth()->user());
    expect(fn () => app(PurchaseCashPayment::class)->post($purchase->id, auth()->user()))->toThrow(ValidationException::class);
    expect(FinanceTransaction::count())->toBe(0);
    $matching->confirm($bank->id, [], auth()->user());
    app(PurchaseCashPayment::class)->post($purchase->id, auth()->user());
    expect(fn () => $matching->confirm($bank->id, $documents, auth()->user()))->toThrow(ValidationException::class);
    expect($matching->suggestions($bank)->pluck('id')->all())->not->toContain($purchase->id)
        ->and(DB::table('bank_purchase_matches')->count())->toBe(0);
});

test('explicit reversal retains history restores cash and can safely precede a bank match', function () {
    $purchase = cashRsDocument();
    $service = app(PurchaseCashPayment::class);
    $posted = $service->post($purchase->id, auth()->user());
    $page = Livewire::test(EditPurchase::class, ['record' => $purchase->id])->set('data.cash_paid', false)->assertActionMounted('cashPayment');
    expect(FinanceTransaction::count())->toBe(1);
    $page->callMountedAction()->assertHasNoActionErrors()->assertFormSet(['cash_paid' => false]);
    $service->reverse($purchase->id, $posted->id, auth()->user());
    expect(FinanceTransaction::count())->toBe(2)->and(CashboxTransaction::count())->toBe(2)
        ->and($purchase->cashExpense()->exists())->toBeFalse()
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toBe(5000.0);
    $ledger = app(AccountingLedger::class);
    expect((float) $ledger->pnlTotals(null, null)->sole()->expenses)->toBe(0.0)
        ->and((float) $ledger->pnlTotals(null, null)->sole()->revenue)->toBe(0.0)
        ->and((float) $ledger->dimensionEntries(null, null)->sum('amount'))->toBe(0.0);
    app(BankPurchaseMatching::class)->confirm(cashRsBank()->id, [['purchase_id' => $purchase->id, 'amount' => 1000]], auth()->user());
    expect((float) $ledger->pnlTotals(null, null)->sole()->expenses)->toBe(1000.0)
        ->and((float) $ledger->dimensionEntries(null, null)->sum('amount'))->toBe(1000.0);
});

test('posted history and monetary lines cannot be edited or deleted invisibly', function () {
    $purchase = cashRsDocument();
    $posted = app(PurchaseCashPayment::class)->post($purchase->id, auth()->user());
    foreach ([
        fn () => $posted->update(['amount' => 1]), fn () => $posted->delete(),
        fn () => $posted->cashboxTransaction->update(['amount' => 1]), fn () => $posted->cashboxTransaction->delete(),
        fn () => $purchase->items()->first()->update(['line_total' => 1]), fn () => $purchase->items()->first()->delete(),
        fn () => $purchase->delete(),
    ] as $index => $change) {
        try {
            $change();
            $this->fail('Mutation '.$index.' was not rejected');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }
    expect((float) $posted->fresh()->amount)->toBe(1000.0);
});

test('insufficient cash or changed confirmation amount rolls back without posting', function () {
    $purchase = cashRsDocument(['unknown' => 6000]);
    expect(fn () => app(PurchaseCashPayment::class)->post($purchase->id, auth()->user()))->toThrow(ValidationException::class);
    $purchase = cashRsDocument();
    expect(fn () => app(PurchaseCashPayment::class)->post($purchase->id, auth()->user(), '999.00'))->toThrow(ValidationException::class);
    expect(FinanceTransaction::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
});

test('only owners can post or reverse RS cash', function () {
    $purchase = cashRsDocument();
    $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    expect(fn () => app(PurchaseCashPayment::class)->post($purchase->id, $admin))->toThrow(HttpException::class);
    $posting = app(PurchaseCashPayment::class)->post($purchase->id, auth()->user());
    expect(fn () => app(PurchaseCashPayment::class)->reverse($purchase->id, $posting->id, $admin))->toThrow(HttpException::class);
});
