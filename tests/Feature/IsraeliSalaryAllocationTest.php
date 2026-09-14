<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\LabCase;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\SalaryPayout;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Services\DoctorCompensationCalculator;
use App\Services\DoctorSalaryHistory;
use App\Services\Finance\LiquidityReport;
use App\Services\FinanceManager;
use App\Services\FinanceUsdUsageService;
use App\Services\IsraeliSalaryPayoutService;
use App\Services\SalarySettlementService;
use App\Support\CashboxManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 12:00:00'));
    Cache::put('nbg:official-rate:USD:2026-09-14', 2.61, now()->endOfDay());
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Allocation', 'last_name' => 'Doctor', 'is_active' => true, 'compensation_percentage' => 40, 'israeli_lab_zircon_rate' => 100]);
    $this->patient = Patient::create(['first_name' => 'Israeli', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $case = LabCase::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'case_date' => today(), 'source' => 'israeli']);
    $this->work = $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 52]);
    foreach (['GEL' => 10000, 'USD' => 2000] as $currency => $amount) {
        app(FinanceManager::class)->create(['type' => 'income', 'category' => 'other_income', 'transaction_date' => now(),
            'amount' => $amount, 'currency' => $currency, 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    }
    foreach (['GEL' => 5000, 'USD' => 3000] as $currency => $amount) {
        $this->patient->partnerPayments()->create(['amount' => $amount, 'currency' => $currency, 'payment_method' => 'cash', 'paid_at' => now()]);
    }
    $this->service = app(IsraeliSalaryPayoutService::class);
    $this->pay = fn (array $rows, ?string $key = null) => $this->service->finalizeAndPay($this->doctor->id, '2026-09-01', '2026-09-14', [$this->work->id], $rows, $key ?? (string) Str::uuid(), $this->owner);
});

function allocationRow(string $source, string $currency, float $amount, ?float $rate = null): array
{
    return ['source' => $source, 'currency' => $currency, 'amount' => $amount, 'exchange_rate' => $rate];
}

test('mixed source currency payout reduces exactly the selected cash balances', function () {
    $payout = ($this->pay)([allocationRow('israeli', 'USD', 1000, 2.7), allocationRow('clinic', 'GEL', 2500)]);
    $settlement = $payout->settlement;
    expect((float) $settlement->salary_total)->toBe(5200.0)->and((float) $payout->total_gel)->toBe(5200.0)
        ->and($this->service->remaining($settlement))->toBe(0.0)->and($payout->allocations)->toHaveCount(2);
    $cash = app(FinanceUsdUsageService::class);
    expect($cash->cashBalances('clinic')['GEL'])->toEqual(7500)->and($cash->cashBalances('clinic')['USD'])->toEqual(2000)
        ->and($cash->cashBalances('israeli')['USD'])->toEqual(2000)->and($cash->cashBalances('israeli')['GEL'])->toEqual(5000);
    expect(PartnerFinanceTransaction::whereNotNull('salary_payout_allocation_id')->count())->toBe(1)
        ->and(FinanceTransaction::whereNotNull('salary_payout_allocation_id')->count())->toBe(1)
        ->and(FinanceTransaction::whereNotNull('salary_payout_allocation_id')->sole()->cashboxTransaction()->count())->toBe(1)
        ->and(PartnerFinanceTransaction::where('salary_settlement_id', $settlement->id)->count())->toBe(0);
    $liquidity = app(LiquidityReport::class);
    expect($liquidity->current()['cash']['GEL']['amount'])->toEqual(12500)
        ->and($liquidity->current('clinic')['cash']['GEL']['amount'])->toEqual(7500)
        ->and($liquidity->current('israeli')['cash']['USD']['amount'])->toEqual(2000);
});

test('single full payout supports all source and currency combinations', function ($source, $currency, $amount, $rate) {
    $this->patient->partnerPayments()->create(['amount' => 1000, 'currency' => 'GEL', 'payment_method' => 'cash', 'paid_at' => now()]);
    $before = app(FinanceUsdUsageService::class)->cashBalances($source)[$currency];
    $payout = ($this->pay)([allocationRow($source, $currency, $amount, $rate)]);
    expect($this->service->remaining($payout->settlement))->toBe(0.0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances($source)[$currency])->toEqual($before - $amount);
})->with([['clinic', 'GEL', 5200, null], ['israeli', 'GEL', 5200, null], ['israeli', 'USD', 2000, 2.6], ['clinic', 'USD', 2000, 2.6]]);

test('partial salary stays payable and later allocations complete the same snapshot', function () {
    $first = ($this->pay)([allocationRow('israeli', 'GEL', 3000)]);
    $settlement = $first->settlement;
    expect($this->service->remaining($settlement))->toBe(2200.0);
    $this->work->update(['quantity' => 70]);
    expect((float) $settlement->fresh()->salary_total)->toBe(5200.0);
    $summary = app(DoctorCompensationCalculator::class)->payableSummaries(collect([$this->doctor]));
    expect($summary[$this->doctor->id]['israeli']['GEL'])->toBe(2200.0);
    $this->service->payRemaining($settlement->id, [allocationRow('clinic', 'GEL', 2200)], (string) Str::uuid(), $this->owner);
    expect(SalarySettlement::count())->toBe(1)->and(SalaryPayout::count())->toBe(2)->and($this->service->remaining($settlement))->toBe(0.0);
    $history = app(DoctorSalaryHistory::class)->forDoctor($this->doctor->id)->sole();
    expect($history->payouts)->toHaveCount(2)->and($history->payouts->flatMap->allocations)->toHaveCount(2);
});

test('retrying initial and subsequent payouts never deducts twice', function () {
    $key = (string) Str::uuid();
    $rows = [allocationRow('israeli', 'USD', 1000, 2.7)];
    $first = ($this->pay)($rows, $key);
    expect(($this->pay)($rows, $key)->id)->toBe($first->id)->and(SalarySettlement::count())->toBe(1);
    $laterKey = (string) Str::uuid();
    $later = [allocationRow('clinic', 'GEL', 1000)];
    $second = $this->service->payRemaining($first->salary_settlement_id, $later, $laterKey, $this->owner);
    expect($this->service->payRemaining($first->salary_settlement_id, $later, $laterKey, $this->owner)->id)->toBe($second->id);
    expect(app(FinanceUsdUsageService::class)->cashBalances('israeli')['USD'])->toEqual(2000)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toEqual(9000);
    expect(fn () => ($this->pay)([allocationRow('clinic', 'GEL', 200)], $key))->toThrow(ValidationException::class);
});

test('overallocations insufficient funds and invalid rates roll back the whole payment', function ($rows) {
    expect(fn () => ($this->pay)($rows))->toThrow(ValidationException::class);
    expect(SalarySettlement::count())->toBe(0)->and(SalaryPayout::count())->toBe(0)
        ->and(PartnerFinanceTransaction::whereNotNull('salary_payout_allocation_id')->count())->toBe(0)
        ->and(FinanceTransaction::whereNotNull('salary_payout_allocation_id')->count())->toBe(0);
})->with([
    'too much salary' => [[allocationRow('clinic', 'GEL', 5200.01)]],
    'summed source balance' => [[allocationRow('israeli', 'USD', 2000, 1), allocationRow('israeli', 'USD', 1500, 1)]],
    'missing rate' => [[allocationRow('israeli', 'USD', 1000)]],
    'zero rate' => [[allocationRow('israeli', 'USD', 1000, 0)]],
    'negative amount' => [[allocationRow('clinic', 'GEL', -1)]],
]);

test('allocation history and linked cash expenses cannot be destructively edited', function () {
    $payout = ($this->pay)([allocationRow('clinic', 'GEL', 1000)]);
    expect(fn () => $payout->allocations->first()->update(['amount' => 1]))->toThrow(ValidationException::class);
    expect(fn () => app(SalarySettlementService::class)->undo($payout->salary_settlement_id))->toThrow(ValidationException::class);
    expect(fn () => app(FinanceManager::class)->delete(FinanceTransaction::whereNotNull('salary_payout_allocation_id')->sole()))->toThrow(ValidationException::class);
});

test('allocation UI replaces the single currency and can pay a remaining salary later', function () {
    $page = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli')
        ->assertActionMounted('calculateSalary')->assertMountedActionModalSee('5,200.00');
    $page->set('mountedActions.0.data.allocations', [allocationRow('israeli', 'USD', 1000, 2.7)])
        ->assertMountedActionModalSee(['2,700.00', '2,500.00'])->callMountedAction()->assertHasNoActionErrors();
    $settlement = SalarySettlement::sole();
    $page->call('openDoctorSalary', $this->doctor->id, 'israeli')->assertActionMounted('payIsraeliSalary')
        ->assertMountedActionModalSee(['5,200.00', '2,700.00', '2,500.00'])
        ->set('mountedActions.0.data.allocations', [allocationRow('clinic', 'GEL', 2500)])
        ->callMountedAction()->assertHasNoActionErrors();
    expect($this->service->remaining($settlement))->toBe(0.0);
});

test('a fresh request cannot pay already finalized work or exceed the remaining salary', function () {
    $first = ($this->pay)([allocationRow('clinic', 'GEL', 3000)]);
    expect(fn () => ($this->pay)([allocationRow('clinic', 'GEL', 3000)]))->toThrow(ValidationException::class);
    expect(fn () => $this->service->payRemaining($first->salary_settlement_id, [allocationRow('clinic', 'GEL', 2200.01)], (string) Str::uuid(), $this->owner))->toThrow(ValidationException::class);
    expect(SalaryPayout::count())->toBe(1)->and($this->service->remaining($first->settlement))->toBe(2200.0);
});

test('closed Clinic cashier rolls back an earlier Israeli allocation in the same payout', function () {
    app(CashboxManager::class)->today()->update(['status' => 'closed']);
    expect(fn () => ($this->pay)([allocationRow('israeli', 'USD', 1000, 2.7), allocationRow('clinic', 'GEL', 2500)]))->toThrow(ValidationException::class);
    expect(SalarySettlement::count())->toBe(0)->and(SalaryPayout::count())->toBe(0)
        ->and(PartnerFinanceTransaction::whereNotNull('salary_payout_allocation_id')->count())->toBe(0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli')['USD'])->toEqual(3000);
});

test('overpayment errors remain visible in the allocation modal', function () {
    Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli')
        ->set('mountedActions.0.data.allocations', [allocationRow('clinic', 'GEL', 5200.01)])
        ->callMountedAction()->assertHasErrors(['allocations'])->assertMountedActionModalSee(__('salary-payout.overallocated'));
    expect(SalaryPayout::count())->toBe(0);
});

test('USD selection loads todays official rate and preserves a manual override', function () {
    Cache::forget('nbg:official-rate:USD:2026-09-14');
    Http::fake([config('services.nbg.rates_url') => Http::response('<Rates><CurrencyRate><Code>USD</Code><Quantity>1</Quantity><Rate>2.61</Rate></CurrencyRate></Rates>')]);
    $page = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli');
    $key = array_key_first($page->get('mountedActions')[0]['data']['allocations']);
    $path = "mountedActions.0.data.allocations.$key";
    $page->set($path.'.currency', 'USD')->assertSet($path.'.exchange_rate', 2.61)
        ->set($path.'.amount', 1000)->assertMountedActionModalSee(['2,610.00', '2,590.00'])
        ->set($path.'.exchange_rate', 2.7)->assertMountedActionModalSee(['2,700.00', '2,500.00'])
        ->set($path.'.source', 'clinic')->assertSet($path.'.exchange_rate', 2.7)
        ->set($path.'.currency', 'GEL')->assertSet($path.'.amount', 5200.0)->assertMountedActionModalSee(['5,200.00', '0.00'])
        ->set($path.'.currency', 'USD')->assertSet($path.'.exchange_rate', 2.7)
        ->set($path.'.amount', 1000)
        ->callMountedAction()->assertHasNoActionErrors();
    expect(SalaryPayout::sole()->allocations()->sole()->exchange_rate)->toBe('2.700000');
    Http::assertSentCount(1);
});

test('fill remaining replaces only its own row and safely completes mixed payments', function () {
    $page = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli')
        ->set('mountedActions.0.data.allocations', ['usd' => allocationRow('israeli', 'USD', 10, 2.61), 'gel' => allocationRow('clinic', 'GEL', 0)]);
    $page->call('mountAction', 'fillRemaining', ['item' => 'usd'], ['schemaComponent' => 'mountedActionSchema0.allocations'])
        ->assertSet('mountedActions.0.data.allocations.usd.amount', 1992.33)
        ->assertMountedActionModalSee(['5,199.98', '0.02']);
    $page->call('mountAction', 'fillRemaining', ['item' => 'gel'], ['schemaComponent' => 'mountedActionSchema0.allocations'])
        ->assertSet('mountedActions.0.data.allocations.gel.amount', 0.02)->assertMountedActionModalSee('0.00 ₾')
        ->callMountedAction()->assertHasNoActionErrors();
    expect($this->service->remaining(SalarySettlement::sole()))->toBe(0.0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli')['USD'])->toEqual(1007.67)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('clinic')['GEL'])->toEqual(9999.98);
});

test('fill remaining takes previous payouts into account and adding or deleting rows updates totals', function () {
    ($this->pay)([allocationRow('clinic', 'GEL', 3000)]);
    $page = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli')
        ->set('mountedActions.0.data.allocations', ['gel' => allocationRow('clinic', 'GEL', 100)])
        ->assertMountedActionModalSee('2,100.00');
    $page->call('mountAction', 'fillRemaining', ['item' => 'gel'], ['schemaComponent' => 'mountedActionSchema0.allocations'])
        ->assertSet('mountedActions.0.data.allocations.gel.amount', 2200.0)->assertMountedActionModalSee('0.00 ₾');
    $page->call('mountAction', 'add', [], ['schemaComponent' => 'mountedActionSchema0.allocations']);
    expect($page->get('mountedActions')[0]['data']['allocations'])->toHaveCount(2);
    $page->call('mountAction', 'delete', ['item' => 'gel'], ['schemaComponent' => 'mountedActionSchema0.allocations'])
        ->assertMountedActionModalSee('2,200.00');
    expect($page->get('mountedActions')[0]['data']['allocations'])->toHaveCount(1);
});

test('unavailable official rate leaves an editable field without inventing a rate', function () {
    Cache::forget('nbg:official-rate:USD:2026-09-14');
    Http::fake([config('services.nbg.rates_url') => Http::response('', 503)]);
    $page = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli');
    $key = array_key_first($page->get('mountedActions')[0]['data']['allocations']);
    $path = "mountedActions.0.data.allocations.$key";
    $page->set($path.'.currency', 'USD')->assertSet($path.'.exchange_rate', null)
        ->set($path.'.exchange_rate', 2.61)->set($path.'.amount', 1000)
        ->assertMountedActionModalSee('2,610.00')->callMountedAction()->assertHasNoActionErrors();
});

test('initial and added USD rows auto fill only the unpaid unallocated amount', function () {
    $page = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli');
    $rows = $page->get('mountedActions')[0]['data']['allocations'];
    $key = array_key_first($rows);
    expect($rows[$key]['currency'])->toBe('USD')->and((float) $rows[$key]['exchange_rate'])->toBe(2.61)
        ->and((float) $rows[$key]['amount'])->toBe(1992.33);
    $page->set("mountedActions.0.data.allocations.$key.amount", 1000)
        ->call('mountAction', 'add', [], ['schemaComponent' => 'mountedActionSchema0.allocations']);
    $rows = $page->get('mountedActions')[0]['data']['allocations'];
    $new = array_key_last($rows);
    expect($rows[$new]['currency'])->toBe('USD')->and((float) $rows[$new]['exchange_rate'])->toBe(2.61)
        ->and((float) $rows[$new]['amount'])->toBe(992.33);
    $page->set("mountedActions.0.data.allocations.$new.currency", 'GEL')
        ->assertSet("mountedActions.0.data.allocations.$new.amount", 2590.0)->assertMountedActionModalSee('data-allocation-status="complete"', false);
    $page->set("mountedActions.0.data.allocations.$new.amount", 2000)
        ->assertMountedActionModalSee('data-allocation-status="remaining"', false)
        ->set("mountedActions.0.data.allocations.$new.exchange_rate", 3)
        ->assertSet("mountedActions.0.data.allocations.$new.amount", 2000);
});

test('summary shows remaining complete and orange advance with identical value font styling', function () {
    app()->setLocale('ka');
    foreach ([900 => ['remaining', 'დარჩენილი', 'text-gray-900'], 0 => ['complete', 'დარჩენილი', 'text-success-600'], -150 => ['advance', 'ავანსი', 'text-orange-600']] as $remaining => [$state, $label, $color]) {
        $html = view('filament.resources.doctors.salary-allocation-totals', ['errors' => new ViewErrorBag, 'salary' => 5900, 'paid' => 0, 'allocated' => 5900 - $remaining, 'remaining' => $remaining])->render();
        expect($html)->toContain('data-allocation-status="'.$state.'"', $label, $color, 'text-xs')
            ->not->toContain('text-sm', 'text-lg', '-150.00');
        expect(substr_count($html, 'class="tabular-nums"'))->toBe(3);
    }
});

test('manual excess stays visible as an advance but cannot be saved', function () {
    app()->setLocale('ka');
    $page = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli');
    $key = array_key_first($page->get('mountedActions')[0]['data']['allocations']);
    $page->set("mountedActions.0.data.allocations.$key.amount", 2000)
        ->assertMountedActionModalSee(['ავანსი', '20.00', 'text-orange-600'])
        ->callMountedAction()->assertHasErrors(['allocations']);
    expect(SalaryPayout::count())->toBe(0);
});

test('switching the doctor profile to Israeli initializes USD without loading NBG for Clinic', function () {
    Cache::forget('nbg:official-rate:USD:2026-09-14');
    Http::fake([config('services.nbg.rates_url') => Http::response('<Rates><CurrencyRate><Code>USD</Code><Quantity>1</Quantity><Rate>2.61</Rate></CurrencyRate></Rates>')]);
    $page = Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction(TestAction::make('calculateSalary')->schemaComponent('compensation'));
    Http::assertNothingSent();
    $page->set('mountedActions.0.data.patient_group', PatientGroup::ISRAEL_PARTNER_SLUG);
    $row = collect($page->get('mountedActions')[0]['data']['allocations'])->first();
    expect($row['currency'])->toBe('USD')->and((float) $row['amount'])->toBe(1992.33)->and((float) $row['exchange_rate'])->toBe(2.61);
    Http::assertSentCount(1);
});
