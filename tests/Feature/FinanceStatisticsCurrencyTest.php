<?php

use App\Filament\Pages\FinanceReports;
use App\Models\ExchangeRate;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\User;
use App\Services\NbgExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 22));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    Cache::clear();
    Http::preventStrayRequests();
});

function datedNbgPayload(string $date, float $rate): array
{
    return [['date' => $date.'T00:00:00.000Z', 'currencies' => [['code' => 'USD', 'quantity' => 1, 'rate' => $rate, 'validFromDate' => $date.'T00:00:00.000Z']]]];
}

function statisticsCurrencyEntry(string $date, string $currency, string $type, float $amount): void
{
    FinanceTransaction::create(['transaction_date' => $date, 'currency' => $currency, 'type' => $type, 'amount' => $amount,
        'category' => $type === 'income' ? 'other_income' : 'materials', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
}

test('finance defaults to dated GEL equivalents and original currency views need no rates', function () {
    Http::fake(fn ($request) => Http::response(datedNbgPayload($request['date'], $request['date'] === '2026-09-15' ? 2.5 : 3)));
    statisticsCurrencyEntry('2026-09-15', 'GEL', 'income', 100);
    statisticsCurrencyEntry('2026-09-15', 'USD', 'income', 100);
    statisticsCurrencyEntry('2026-09-15', 'USD', 'expense', 20);
    statisticsCurrencyEntry('2026-09-16', 'USD', 'income', 100);
    statisticsCurrencyEntry('2026-09-16', 'GEL', 'expense', 10);
    PartnerFinanceTransaction::create(['source' => 'clinic', 'type' => 'owner_withdrawal', 'transacted_at' => '2026-09-15',
        'from_account' => 'cash', 'amount' => 40, 'currency' => 'USD']);
    PartnerFinanceTransaction::create(['source' => 'clinic', 'type' => 'currency_exchange', 'transacted_at' => '2026-09-15',
        'from_account' => 'cash', 'to_account' => 'cash', 'from_currency' => 'USD', 'to_currency' => 'GEL', 'from_amount' => 10, 'to_amount' => 25, 'exchange_rate' => 2.5]);
    $this->artisan('exchange-rates:backfill')->assertSuccessful();
    $this->artisan('exchange-rates:backfill')->assertSuccessful();
    expect(ExchangeRate::count())->toBe(2);
    $page = Livewire::test(FinanceReports::class)->assertSet('financialCurrency', 'all')
        ->assertSee('GEL ეკვივალენტი')
        ->assertViewHas('analytics', function ($data) {
            expect($data['incomeTotal'])->toBe(650.0)->and($data['expenseTotal'])->toBe(60.0)->and($data['profit'])->toBe(590.0)
                ->and(array_sum($data['income']))->toBe(650.0)->and(array_sum($data['expense']))->toBe(60.0)
                ->and(array_sum($data['trend']['profit']))->toBe(590.0);

            return true;
        });
    Http::assertSentCount(2);
    $page->set('financialCurrency', 'USD')->assertSee('$200.00')
        ->assertViewHas('analytics', fn ($data) => $data['incomeTotal'] === 200.0 && $data['expenseTotal'] === 20.0 && $data['displayCurrency'] === 'USD')
        ->set('financialCurrency', 'GEL')
        ->assertViewHas('analytics', fn ($data) => $data['incomeTotal'] === 100.0 && $data['expenseTotal'] === 10.0)
        ->set('financialCurrency', 'all')->set('dateFrom', '2026-01-01')
        ->assertViewHas('analytics', fn ($data) => count($data['trend']['labels']) === 9 && array_sum($data['trend']['profit']) === 590.0)
        ->set('reportTab', 'cash_out')
        ->assertViewHas('analytics', fn ($data) => $data['expenseTotal'] === 60.0 && $data['cashOutTotal'] === 185.0)
        ->set('reportTab', 'expense')->assertViewHas('analytics', fn ($data) => $data['profit'] === 590.0)
        ->call('selectSectionTab', 'doctors')->assertSet('currency', 'GEL');
    Http::assertSentCount(2);
});

test('missing historical rates do not silently drop dollars or use todays rate', function () {
    Http::fake(['*' => Http::response([], 503)]);
    statisticsCurrencyEntry('2026-09-15', 'USD', 'income', 100);
    Livewire::test(FinanceReports::class)->assertOk()->assertViewHas('analytics', ['rateUnavailable' => true])
        ->assertSee('NBG კურსი ვერ ჩაიტვირთა')->set('financialCurrency', 'USD')
        ->assertViewHas('analytics', fn ($data) => $data['incomeTotal'] === 100.0);
    Http::assertNothingSent();
});

test('dated NBG rates use persistent storage and reject a response for the wrong date', function () {
    Http::fake(fn ($request) => Http::response(datedNbgPayload($request['date'] === '2026-09-14' ? '2026-09-22' : $request['date'], 2.7)));
    $service = app(NbgExchangeRate::class);
    expect($service->usdGelForDates(['2026-09-15', '2026-09-15', '2026-09-16']))->toBe(['2026-09-15' => 2.7, '2026-09-16' => 2.7]);
    $service->usdGelForDates(['2026-09-16', '2026-09-15']);
    Http::assertSentCount(2);
    expect(fn () => $service->usdGelForDates(['2026-09-14']))->toThrow(RuntimeException::class);
});

test('missing weekend rates fall back to the last official rate and persist its effective date', function () {
    Http::fake(fn ($request) => Http::response($request['date'] === '2026-09-18' ? datedNbgPayload('2026-09-18', 2.6) : []));
    $rates = app(NbgExchangeRate::class)->usdGelForDates(['2026-09-20']);
    expect($rates)->toBe(['2026-09-20' => 2.6]);
    $this->assertDatabaseHas('exchange_rates', ['date' => '2026-09-20', 'currency' => 'USD', 'source' => 'NBG', 'effective_date' => '2026-09-18']);
    Http::assertSentCount(3);
    Cache::clear();
    expect(app(NbgExchangeRate::class)->usdGelForDates(['2026-09-20']))->toBe($rates);
    Http::assertSentCount(3);
});

test('stored historical rates are bulk loaded without network requests', function () {
    ExchangeRate::create(['date' => '2026-09-15', 'currency' => 'USD', 'rate_to_gel' => 2.8, 'source' => 'NBG', 'effective_date' => '2026-09-15']);
    statisticsCurrencyEntry('2026-09-15', 'USD', 'income', 100);
    Http::fake();
    DB::enableQueryLog();
    expect(app(NbgExchangeRate::class)->usdGelForDates(['2026-09-15', '2026-09-15'], false))->toBe(['2026-09-15' => 2.8]);
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
    Livewire::test(FinanceReports::class)->assertViewHas('analytics', fn ($data) => $data['incomeTotal'] === 280.0);
    $this->artisan('exchange-rates:backfill')->assertSuccessful();
    Http::assertNothingSent();
});

test('backfill ignores GEL only dates and fails safely on NBG outage', function () {
    statisticsCurrencyEntry('2026-09-15', 'GEL', 'income', 100);
    statisticsCurrencyEntry('2026-09-16', 'USD', 'income', 100);
    Http::fake(['*' => Http::response([], 503)]);
    $this->artisan('exchange-rates:backfill')->assertFailed();
    expect(ExchangeRate::count())->toBe(0);
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['date'] === '2026-09-16');
});
