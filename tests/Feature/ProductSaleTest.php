<?php

use App\Filament\Pages\Cashbox;
use App\Filament\Pages\Finance;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Filament\Resources\Visits\Pages\EditVisit;
use App\Models\CashboxTransaction;
use App\Models\Patient;
use App\Models\Product;
use App\Models\ProductSale;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\ProductSaleService;
use App\Support\CashboxManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function saleProduct(string $name = 'Toothbrush', float $price = 15): Product
{
    return Product::create(['name' => $name, 'selling_price' => $price, 'is_active' => true]);
}

test('cash and card product sales post once to their matching cashier totals', function (string $method, float $cashExpected, float $cardExpected, int $transactionCount) {
    $product = saleProduct();
    $sale = app(ProductSaleService::class)->create([
        'items' => [['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price' => 15]],
        'payment_method' => $method,
    ]);

    expect($sale->patient_id)->toBeNull()
        ->and($sale->visit_id)->toBeNull()
        ->and((float) $sale->total)->toBe(30.0)
        ->and(CashboxTransaction::query()->where('product_sale_id', $sale->getKey())->count())->toBe($transactionCount)
        ->and((float) CashboxTransaction::query()->where('product_sale_id', $sale->getKey())->sum('amount'))->toBe($cashExpected + $cardExpected)
        ->and(app(CashboxManager::class)->today()->summary()['cashIncome'])->toBe($cashExpected)
        ->and(app(CashboxManager::class)->today()->summary()['cardIncome'])->toBe($cardExpected)
        ->and(app(CashboxManager::class)->today()->summary()['expected'])->toBe($cashExpected);
})->with([
    'cash' => ['cash', 30, 0, 1],
    'card' => ['card', 0, 30, 1],
    'bank transfer' => ['bank_transfer', 0, 0, 0],
]);

test('card product sale appears in cashier history with its persisted method currency and amount', function () {
    $this->actingAs(User::factory()->create());
    $product = saleProduct();
    $sale = app(ProductSaleService::class)->create([
        'items' => [['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price' => 15]],
        'payment_method' => 'card',
        'currency' => 'GEL',
    ]);
    $transaction = CashboxTransaction::query()->where('product_sale_id', $sale->getKey())->sole();

    expect($transaction->type)->toBe('product_sale')
        ->and($transaction->payment_method)->toBe('card')
        ->and($transaction->currency)->toBe('GEL')
        ->and((float) $transaction->amount)->toBe(30.0);

    Livewire::test(Cashbox::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$transaction])
        ->assertSee('Toothbrush ×2')
        ->assertSee('30.00 ₾');
});

test('usd product sale preserves gel value and posts one native usd cashier row', function () {
    $this->actingAs(User::factory()->create());
    $product = saleProduct('Gengigel', 40);

    $sale = app(ProductSaleService::class)->create([
        'items' => [['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price' => 40]],
        'payment_method' => 'cash',
        'currency' => 'USD',
        'exchange_rate' => 2.70,
    ]);
    $transaction = CashboxTransaction::query()->where('product_sale_id', $sale->getKey())->sole();

    expect((float) $sale->base_total)->toBe(80.0)
        ->and((float) $sale->total)->toBe(29.63)
        ->and($sale->currency)->toBe('USD')
        ->and((float) $sale->exchange_rate)->toBe(2.7)
        ->and($transaction->currency)->toBe('USD')
        ->and((float) $transaction->amount)->toBe(29.63)
        ->and(CashboxTransaction::query()->where('product_sale_id', $sale->getKey())->count())->toBe(1)
        ->and(CashboxTransaction::query()->where('product_sale_id', $sale->getKey())->where('currency', 'GEL')->exists())->toBeFalse();

    Livewire::test(Cashbox::class)
        ->assertSuccessful()
        ->assertSee('Gengigel ×2')
        ->assertSee('$29.63');
});

test('multiple product quantities and default quantity produce correct totals', function () {
    $brush = saleProduct();
    $paste = saleProduct('Toothpaste', 20);
    $sale = app(ProductSaleService::class)->create([
        'items' => [
            ['product_id' => $brush->getKey(), 'quantity' => 2, 'unit_price' => 15],
            ['product_id' => $paste->getKey(), 'unit_price' => 20],
        ],
        'payment_method' => 'card',
    ]);

    expect((float) $sale->total)->toBe(50.0)
        ->and($sale->items)->toHaveCount(2)
        ->and($sale->items->last()->quantity)->toBe(1)
        ->and((float) $sale->items->last()->line_total)->toBe(20.0);
});

test('a product sale can link to a patient and an existing visit without becoming a manipulation', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Retail', 'last_name' => 'Patient']);
    $visit = Visit::create(['patient_id' => $patient->getKey(), 'visit_date' => today(), 'total_price' => 0]);
    $product = saleProduct();
    $sale = app(ProductSaleService::class)->create([
        'patient_id' => $patient->getKey(), 'visit_id' => $visit->getKey(),
        'items' => [['product_id' => $product->getKey(), 'quantity' => 1, 'unit_price' => 15]],
        'payment_method' => 'cash',
    ]);

    expect($patient->productSales()->whereKey($sale)->exists())->toBeTrue()
        ->and($visit->productSales()->whereKey($sale)->exists())->toBeTrue()
        ->and($visit->treatmentCaseItems()->count())->toBe(0);

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->assertSee('პროდუქტების შეძენა')
        ->assertSee('Toothbrush ×1');
});

test('a product staged during new visit creation is linked only after the visit exists', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'New', 'last_name' => 'Retail']);
    $treatment = TreatmentCase::create(['name' => 'Cleaning', 'category' => 'therapy', 'is_active' => true]);
    $product = saleProduct();
    $component = Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $patient->getKey(), 'visit_type' => 'treatment',
        'treatmentCaseItems' => [[
            'service_choice' => (string) $treatment->getKey(), 'treatment_case_id' => $treatment->getKey(),
            'quantity' => 1, 'unit_price' => 100,
        ]],
    ]);

    $component->call('submitCombinedPayment', [
        'amount' => 130, 'currency' => 'GEL',
        'products' => [['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price' => 15]],
        'splits' => [['payment_method' => 'card', 'amount' => 130, 'currency' => 'GEL']],
    ]);
    expect(ProductSale::query()->count())->toBe(0);

    $component->call('create')->assertHasNoErrors();
    $visit = Visit::query()->sole();
    $sale = ProductSale::query()->sole();

    expect($sale->visit_id)->toBe($visit->getKey())
        ->and($sale->patient_id)->toBe($patient->getKey())
        ->and((float) $visit->payments()->sole()->amount)->toBe(100.0)
        ->and($sale->items()->sole()->quantity)->toBe(2)
        ->and((float) CashboxTransaction::query()->where('product_sale_id', $sale->getKey())->where('payment_method', 'card')->sole()->amount)->toBe(30.0)
        ->and((float) CashboxTransaction::query()->where('visit_id', $visit->getKey())->sum('amount'))->toBe(130.0);
});

test('visit form has no standalone product sale action', function () {
    $this->actingAs(User::factory()->create());

    Livewire::test(CreateVisit::class)
        ->assertSuccessful()
        ->assertActionDoesNotExist(TestAction::make('productSale')->schemaComponent());
});

test('saved visit payment popup combines a cash product sale without duplicate cashier amount', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Combined', 'last_name' => 'Cash']);
    $visit = Visit::create(['patient_id' => $patient->getKey(), 'visit_date' => today(), 'total_price' => 100, 'currency' => 'GEL']);
    $treatment = TreatmentCase::create(['name' => 'Combined service', 'category' => 'therapy', 'is_active' => true]);
    $visit->treatmentCaseItems()->create(['treatment_case_id' => $treatment->getKey(), 'quantity' => 1, 'unit_price' => 100]);
    $product = saleProduct();

    Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()])
        ->callAction(TestAction::make('makePayment')->schemaComponent(), [
            'service_amount' => 100, 'amount' => 130, 'currency' => 'GEL',
            'products' => [['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price' => 15]],
            'splits' => [['payment_method' => 'cash', 'amount' => 130, 'currency' => 'GEL']],
        ])->assertHasNoActionErrors();

    $sale = $visit->productSales()->with('items')->sole();
    expect((float) $visit->payments()->sole()->amount)->toBe(100.0)
        ->and((float) $sale->total)->toBe(30.0)
        ->and($sale->items->sole()->quantity)->toBe(2)
        ->and((float) CashboxTransaction::where('visit_id', $visit->getKey())->sum('amount'))->toBe(130.0)
        ->and(CashboxTransaction::where('visit_id', $visit->getKey())->count())->toBe(2);
});

test('combined product payment validates split rows against grand total and preserves revenue parts', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Combined', 'last_name' => 'Split']);
    $visit = Visit::create(['patient_id' => $patient->getKey(), 'visit_date' => today(), 'total_price' => 300, 'currency' => 'GEL']);
    $treatment = TreatmentCase::create(['name' => 'Split service', 'category' => 'therapy', 'is_active' => true]);
    $visit->treatmentCaseItems()->create(['treatment_case_id' => $treatment->getKey(), 'quantity' => 1, 'unit_price' => 300]);
    $product = saleProduct('Gengigel', 30);

    Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()])
        ->callAction(TestAction::make('makePayment')->schemaComponent(), [
            'service_amount' => 300, 'amount' => 360, 'currency' => 'GEL',
            'products' => [['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price' => 30]],
            'splits' => [
                ['payment_method' => 'cash', 'amount' => 200, 'currency' => 'GEL'],
                ['payment_method' => 'card', 'amount' => 160, 'currency' => 'GEL'],
            ],
        ])->assertHasNoActionErrors();

    $sale = $visit->productSales()->sole();
    $summary = app(CashboxManager::class)->today()->summary();
    app(CashboxManager::class)->syncProductSale($sale);
    $resyncedSummary = app(CashboxManager::class)->today()->summary();
    Livewire::test(Finance::class)
        ->assertViewHas('incomeByMethod', fn (array $totals): bool => $totals['cash'] === 200.0 && $totals['card'] === 160.0);
    expect((float) $visit->payments()->sole()->amount)->toBe(300.0)
        ->and((float) $sale->total)->toBe(60.0)
        ->and($summary['cashIncome'])->toBe(200.0)
        ->and($summary['cardIncome'])->toBe(160.0)
        ->and((float) CashboxTransaction::where('visit_id', $visit->getKey())->sum('amount'))->toBe(360.0)
        ->and($resyncedSummary['cashIncome'])->toBe(200.0)
        ->and($resyncedSummary['cardIncome'])->toBe(160.0);
});

test('combined product flow preserves supported partial and later visit payments', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Partial', 'last_name' => 'Combined']);
    $visit = Visit::create(['patient_id' => $patient->getKey(), 'visit_date' => today(), 'total_price' => 300, 'currency' => 'GEL']);
    $treatment = TreatmentCase::create(['name' => 'Partial service', 'category' => 'therapy', 'is_active' => true]);
    $visit->treatmentCaseItems()->create(['treatment_case_id' => $treatment->getKey(), 'quantity' => 1, 'unit_price' => 300]);
    $product = saleProduct('Partial product', 30);

    Livewire::test(EditVisit::class, ['record' => $visit->getRouteKey()])
        ->mountAction(TestAction::make('makePayment')->schemaComponent())
        ->set('mountedActions.0.data.products', [['product_id' => $product->getKey(), 'quantity' => 1, 'unit_price' => 30]])
        ->set('mountedActions.0.data.amount', 100)
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect((float) $visit->productSales()->sole()->total)->toBe(30.0)
        ->and((float) $visit->payments()->sole()->amount)->toBe(70.0)
        ->and((float) CashboxTransaction::where('visit_id', $visit->getKey())->sum('amount'))->toBe(100.0)
        ->and($visit->fresh()->remaining_amount)->toBe(230.0);
});

test('finance derives each product sale exactly once and keeps it separate from medical revenue', function () {
    $this->actingAs(User::factory()->create());
    $product = saleProduct();
    $sale = app(ProductSaleService::class)->create([
        'items' => [['product_id' => $product->getKey(), 'quantity' => 2, 'unit_price' => 15]],
        'payment_method' => 'bank_transfer',
    ]);

    Livewire::test(Finance::class)
        ->assertViewHas('income', 30.0)
        ->assertViewHas('entries', fn ($entries): bool => $entries->where('key', 'product-sale-'.$sale->getKey())->count() === 1)
        ->assertSee('პროდუქტის გაყიდვა');

    expect(ProductSale::query()->count())->toBe(1)
        ->and(CashboxTransaction::query()->where('product_sale_id', $sale->getKey())->count())->toBe(0);
});

test('cashbox synchronization is idempotent for the same sale', function () {
    $product = saleProduct();
    $sale = app(ProductSaleService::class)->create([
        'items' => [['product_id' => $product->getKey(), 'quantity' => 1, 'unit_price' => 15]],
        'payment_method' => 'cash',
    ]);

    app(CashboxManager::class)->syncProductSale($sale);

    expect(CashboxTransaction::query()->where('product_sale_id', $sale->getKey())->count())->toBe(1);
});

test('visit direct expense UI keeps quantity in state and uses clear after-expense wording', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Expense', 'last_name' => 'UI']);
    $treatment = TreatmentCase::create(['name' => 'Crown', 'category' => 'orthopedics', 'is_active' => true]);
    $component = Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $patient->getKey(), 'visit_type' => 'treatment',
        'treatmentCaseItems' => [[
            'service_choice' => (string) $treatment->getKey(), 'treatment_case_id' => $treatment->getKey(),
            'quantity' => 1, 'unit_price' => 3250,
            'directExpenses' => [['name' => 'ლაბორატორია', 'quantity' => 1, 'amount' => 500, 'currency' => 'GEL']],
        ]],
    ]);
    $item = collect($component->instance()->form->getRawState()['treatmentCaseItems'])->first();

    expect(collect($item['directExpenses'])->first()['quantity'])->toBe(1);
    $component->assertSee('ხარჯის შემდეგ')->assertDontSee('· ნეტო');
});
