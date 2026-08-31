<?php

use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Models\CashboxTransaction;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Services\NbgExchangeRate;
use App\Services\PaymentProcessor;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function nbgSoapResponse(string $rate = '2.7000'): string
{
    return <<<XML
<?xml version="1.0" encoding="utf-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/">
  <soap:Body><GetCurrentRatesResponse xmlns="http://www.nbg.ge/"><GetCurrentRatesResult>
    <CurrencyRate><Code>USD</Code><Quantity>1</Quantity><Rate>{$rate}</Rate><Name>US Dollar</Name></CurrencyRate>
  </GetCurrentRatesResult></GetCurrentRatesResponse></soap:Body>
</soap:Envelope>
XML;
}

test('official USD rate is loaded from NBG and cached for the business day', function () {
    Cache::clear();
    Http::fake([config('services.nbg.rates_url') => Http::response(nbgSoapResponse(), 200)]);

    expect(app(NbgExchangeRate::class)->usdGel())->toBe(2.7)
        ->and(app(NbgExchangeRate::class)->usdGel())->toBe(2.7);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request): bool => str_contains($request->body(), '<Currencies>USD</Currencies>'));
});

test('NBG failure does not invent an exchange rate', function () {
    Cache::clear();
    Http::fake([config('services.nbg.rates_url') => Http::response('', 503)]);

    expect(fn () => app(NbgExchangeRate::class)->usdGel())->toThrow(RuntimeException::class);
});

test('foreign amount recalculates from rate until administrator overrides amount', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Rate', 'last_name' => 'Patient']);
    $service = TreatmentCase::create(['name' => 'Rate service', 'category' => 'therapy', 'is_active' => true]);

    $component = Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $patient->getKey(),
        'currency' => 'GEL',
        'visit_type' => 'treatment',
        'treatmentCaseItems' => [[
            'service_choice' => (string) $service->getKey(), 'treatment_case_id' => $service->getKey(),
            'quantity' => 1, 'unit_price' => 130, 'currency' => 'GEL',
        ]],
    ])->mountAction(TestAction::make('makePayment')->schemaComponent());

    $key = array_key_first($component->get('mountedActions.0.data.splits'));
    $path = "mountedActions.0.data.splits.{$key}";
    $component->set("{$path}.currency", 'USD')->set("{$path}.exchange_rate", 2.70);

    expect((float) $component->get("{$path}.amount"))->toBe(48.15)
        ->and($component->get("{$path}.amount_manually_overridden"))->toBeFalsy();

    $component->set("{$path}.amount", 40)->set("{$path}.exchange_rate", 2.60);

    expect((float) $component->get("{$path}.amount"))->toBe(40.0)
        ->and($component->get("{$path}.amount_manually_overridden"))->toBeTrue();
});

test('rounded USD amount can settle the GEL debt within one minor unit', function () {
    $prepared = app(PaymentProcessor::class)->prepare(130, [[
        'payment_method' => 'cash', 'amount' => 48.15, 'currency' => 'USD', 'exchange_rate' => 2.70,
    ]], 'GEL');

    expect($prepared['amount'])->toBe(130.0)
        ->and($prepared['rows'][0]['amount'])->toBe(48.15);
});

test('rounded staged USD payment persists native amount and clears the GEL debt', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'USD', 'last_name' => 'Cashier']);
    $service = TreatmentCase::create(['name' => 'USD payment service', 'category' => 'therapy', 'is_active' => true]);

    $component = Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $patient->getKey(), 'currency' => 'GEL', 'visit_type' => 'treatment',
        'treatmentCaseItems' => [[
            'service_choice' => (string) $service->getKey(), 'treatment_case_id' => $service->getKey(),
            'quantity' => 1, 'unit_price' => 130, 'currency' => 'GEL',
        ]],
    ])->callAction(TestAction::make('makePayment')->schemaComponent(), [
        'amount' => 130, 'currency' => 'GEL',
        'splits' => [['payment_method' => 'cash', 'amount' => 48.15, 'currency' => 'USD', 'exchange_rate' => 2.70]],
    ])->assertHasNoActionErrors();

    expect($component->instance()->getStagedPaidAmount())->toBe(130.0)
        ->and($component->instance()->getCurrentRemainingAmount())->toBe(0.0)
        ->and(Payment::query()->count())->toBe(0);

    $component->call('create')->assertHasNoErrors();
    $payment = Payment::query()->with('splits')->sole();
    $cashier = CashboxTransaction::query()->where('payment_id', $payment->getKey())->sole();

    expect((float) $payment->amount)->toBe(130.0)
        ->and((float) $payment->splits->sole()->amount)->toBe(48.15)
        ->and((float) $payment->splits->sole()->exchange_rate)->toBe(2.7)
        ->and($payment->splits->sole()->currency)->toBe('USD')
        ->and((float) $cashier->amount)->toBe(48.15)
        ->and($cashier->currency)->toBe('USD')
        ->and($payment->visit->remaining_amount)->toBe(0.0);
});
