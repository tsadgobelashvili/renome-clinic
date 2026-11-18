<?php

use App\Filament\Pages\DoctorCompensation;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\IsraeliSalaryPayoutCarryEntry;
use App\Models\LabCase;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\SalaryPayout;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Services\IsraeliSalaryCarryService;
use App\Services\IsraeliSalaryPayoutCarry;
use App\Services\IsraeliSalaryPayoutService;
use App\Services\PartnerFinanceSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-11-17 12:00:00');
    Http::preventStrayRequests();
    Cache::put('nbg:official-rate:USD:2026-11-17', 2.5, now()->endOfDay());
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Rounded', 'last_name' => 'Doctor', 'is_active' => true,
        'compensation_percentage' => 40, 'israeli_lab_zircon_rate' => 4995]);
    $this->patient = Patient::create(['first_name' => 'Synthetic', 'last_name' => 'Israeli', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    foreach (['cash', 'card'] as $method) {
        foreach (['USD', 'GEL'] as $currency) {
            $this->patient->partnerPayments()->create(['amount' => 20000, 'currency' => $currency,
                'payment_method' => $method, 'paid_at' => today()]);
        }
    }
    $this->service = app(IsraeliSalaryPayoutService::class);
    $this->credits = app(IsraeliSalaryPayoutCarry::class);
    $this->settlement = fn (float $gel, ?Doctor $doctor = null, string $method = 'cash') => SalarySettlement::create([
        'doctor_id' => ($doctor ?? $this->doctor)->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-17',
        'settled_at' => now(), 'patient_group_slug' => PatientGroup::ISRAEL_PARTNER_SLUG, 'currency' => 'GEL',
        'salary_total' => $gel, 'uses_allocations' => true, 'status' => 'confirmed', 'israeli_payment_method' => $method,
        'performed_total' => $gel, 'paid_amount' => 0, 'outstanding_amount' => 0, 'direct_expense_total' => 0,
        'base_total' => $gel, 'percentage' => 100,
    ]);
    $this->pay = fn (SalarySettlement $settlement, float $amount, string $currency = 'USD', float $rate = 2.5, ?string $key = null) => $this->service->payRemaining(
        $settlement->id, [['source' => 'israeli', 'currency' => $currency, 'amount' => $amount, 'exchange_rate' => $currency === 'USD' ? $rate : null]],
        $key ?? (string) Str::uuid(), $this->owner);
});

test('rounded USD payments settle only the salary and store the exact excess as a fixed GEL credit', function (float $dueUsd, float $advanceUsd, float $remainingUsd) {
    $settlement = ($this->settlement)($dueUsd * 2.5);
    $original = $settlement->fresh()->getRawOriginal();
    $payout = ($this->pay)($settlement, 2000);
    expect((float) $payout->actual_paid_gel)->toBe(5000.0)
        ->and((float) $payout->total_gel)->toBe(min($dueUsd, 2000) * 2.5)
        ->and((float) $payout->advance_created_gel)->toBe($advanceUsd * 2.5)
        ->and($this->credits->balances($this->doctor->id)['GEL'])->toBe($advanceUsd * 2.5)
        ->and($this->service->remaining($settlement))->toBe($remainingUsd * 2.5)
        ->and($settlement->fresh()->getRawOriginal())->toBe($original)
        ->and(PartnerFinanceTransaction::count())->toBe(1)
        ->and((float) PartnerFinanceTransaction::sole()->amount)->toBe(2000.0)
        ->and(app(PartnerFinanceSummary::class)->accountBalances()['cash']['USD'])->toBe(18000.0)
        ->and(FinanceTransaction::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
})->with(['1998 paid 2000' => [1998.0, 2.0, 0.0], '2002 paid 2000' => [2002.0, 0.0, 2.0], '1995 paid 2000' => [1995.0, 5.0, 0.0]]);

test('future Israeli salary automatically uses the same doctors GEL credit without a second financial posting', function () {
    ($this->pay)(($this->settlement)(4995), 2000);
    $entry = IsraeliSalaryPayoutCarryEntry::sole()->getRawOriginal();
    $next = ($this->settlement)(3750);
    $payout = ($this->pay)($next, 1498);
    expect((float) $payout->advance_applied_gel)->toBe(5.0)->and((float) $payout->total_gel)->toBe(3750.0)
        ->and((float) $payout->actual_paid_gel)->toBe(3745.0)->and($this->service->remaining($next))->toBe(0.0)
        ->and($this->credits->balances($this->doctor->id)['GEL'])->toBe(0.0)
        ->and(IsraeliSalaryPayoutCarryEntry::oldest('id')->first()->getRawOriginal())->toBe($entry)
        ->and((float) PartnerFinanceTransaction::sum('amount'))->toBe(3498.0)
        ->and(PartnerFinanceTransaction::count())->toBe(2)->and(FinanceTransaction::count())->toBe(0);
});

test('a GEL credit keeps its original value when a future USD exchange rate changes', function () {
    ($this->pay)(($this->settlement)(4995), 2000);
    $next = ($this->settlement)(4500);
    $payout = ($this->pay)($next, 1498.33, 'USD', 3);
    expect((float) $payout->advance_applied_gel)->toBe(5.0)->and((float) $payout->actual_paid_gel)->toBe(4494.99)
        ->and($this->service->remaining($next))->toBe(0.01)->and($this->credits->balances($this->doctor->id)['GEL'])->toBe(0.0);
});

test('multiple rounded payouts replace consumed credit without double applying it', function () {
    ($this->pay)(($this->settlement)(4995), 2000); // 5 GEL credit.
    ($this->pay)(($this->settlement)(3750), 1500); // Apply 5; create 5 again.
    ($this->pay)(($this->settlement)(3750), 1505); // Apply 5; create 17.50.
    expect($this->credits->balances($this->doctor->id)['GEL'])->toBe(17.5);
    $last = ($this->settlement)(250);
    ($this->pay)($last, 93);
    expect($this->credits->balances($this->doctor->id)['GEL'])->toBe(0.0)->and($this->service->remaining($last))->toBe(0.0)
        ->and((float) IsraeliSalaryPayoutCarryEntry::where('kind', 'applied')->sum('amount_gel'))->toBe(-27.5);
});

test('partial payments remain outstanding across new periods and can be paid later', function () {
    $old = ($this->settlement)(5005);
    ($this->pay)($old, 2000);
    $new = ($this->settlement)(3750);
    ($this->pay)($new, 1000);
    expect($this->service->remaining($old))->toBe(5.0)->and($this->service->remaining($new))->toBe(1250.0)
        ->and($this->credits->balances($this->doctor->id)['GEL'])->toBe(0.0);
    ($this->pay)($old, 2);
    ($this->pay)($new, 500);
    expect($this->service->remaining($old))->toBe(0.0)->and($this->service->remaining($new))->toBe(0.0)
        ->and(PartnerFinanceTransaction::count())->toBe(4)->and(IsraeliSalaryPayoutCarryEntry::count())->toBe(0);
});

test('credit is isolated by doctor and cannot be used on a Clinic settlement', function () {
    ($this->pay)(($this->settlement)(4995), 2000);
    $other = Doctor::create(['first_name' => 'Other', 'last_name' => 'Recipient', 'is_active' => true]);
    $payout = ($this->pay)(($this->settlement)(3750, $other), 1500);
    expect((float) $payout->advance_applied_gel)->toBe(0.0)->and($this->credits->balances($this->doctor->id)['GEL'])->toBe(5.0)
        ->and($this->credits->balances($other->id)['GEL'])->toBe(0.0);
    $clinic = SalarySettlement::create(['doctor_id' => $this->doctor->id, 'period_start' => today(), 'period_end' => today(),
        'settled_at' => now(), 'salary_total' => 100, 'currency' => 'GEL', 'patient_group_slug' => PatientGroup::CLINIC_SLUG,
        'performed_total' => 100, 'paid_amount' => 0, 'outstanding_amount' => 0, 'direct_expense_total' => 0, 'base_total' => 100, 'percentage' => 100,
        'status' => 'confirmed', 'uses_allocations' => false]);
    expect(fn () => ($this->pay)($clinic, 40))->toThrow(ValidationException::class);
    expect($this->credits->balances($this->doctor->id)['GEL'])->toBe(5.0);
});

test('GEL payment and advance-only payment have one exact effect per actual cash allocation', function () {
    $first = ($this->pay)(($this->settlement)(1995), 2000, 'GEL');
    $next = ($this->settlement)(3);
    $payout = $this->service->payRemaining($next->id, [], (string) Str::uuid(), $this->owner);
    expect((float) $first->advance_created_gel)->toBe(5.0)->and((float) $payout->actual_paid_gel)->toBe(0.0)
        ->and((float) $payout->advance_applied_gel)->toBe(3.0)->and($this->credits->balances($this->doctor->id)['GEL'])->toBe(2.0)
        ->and(PartnerFinanceTransaction::count())->toBe(1)->and($this->service->remaining($next))->toBe(0.0);
});

test('bank payouts check and reduce only the Israeli bank balance', function () {
    ($this->pay)(($this->settlement)(4995, method: 'bank_transfer'), 2000);
    $balances = app(PartnerFinanceSummary::class)->accountBalances();
    expect($balances['bank']['USD'])->toBe(18000.0)->and($balances['cash']['USD'])->toBe(20000.0)
        ->and(PartnerFinanceTransaction::sole()->from_account)->toBe('bank')
        ->and(FinanceTransaction::count())->toBe(0)->and(CashboxTransaction::count())->toBe(0);
});

test('insufficient funds and Clinic sources cannot be used to fund an Israeli rounded payout', function (string $source, float $amount, string $method) {
    $settlement = ($this->settlement)(4995, method: $method);
    expect(fn () => $this->service->payRemaining($settlement->id,
        [['source' => $source, 'currency' => 'USD', 'amount' => $amount, 'exchange_rate' => 2.5]], (string) Str::uuid(), $this->owner))
        ->toThrow(ValidationException::class);
    expect(SalaryPayout::count())->toBe(0)->and(PartnerFinanceTransaction::count())->toBe(0)
        ->and(IsraeliSalaryPayoutCarryEntry::count())->toBe(0)->and(FinanceTransaction::count())->toBe(0);
})->with(['cash shortfall' => ['israeli', 20001.0, 'cash'], 'bank shortfall' => ['israeli', 20001.0, 'bank_transfer'],
    'Clinic rejected' => ['clinic', 2000.0, 'cash']]);

test('retry cannot duplicate salary expenses or credits and a new request cannot overpay a closed obligation', function () {
    $settlement = ($this->settlement)(4995);
    $key = (string) Str::uuid();
    $first = ($this->pay)($settlement, 2000, key: $key);
    $retry = ($this->pay)($settlement, 2000, key: $key);
    expect($retry->id)->toBe($first->id)->and(SalaryPayout::count())->toBe(1)
        ->and(IsraeliSalaryPayoutCarryEntry::count())->toBe(1)->and(PartnerFinanceTransaction::count())->toBe(1);
    expect(fn () => ($this->pay)($settlement, 2001, key: $key))->toThrow(ValidationException::class);
    expect(fn () => ($this->pay)($settlement, 2000))->toThrow(ValidationException::class);
    expect(fn () => IsraeliSalaryPayoutCarryEntry::sole()->update(['amount_gel' => 999]))->toThrow(ValidationException::class);
});

test('a carry insertion failure rolls back payout allocation and financial movement together', function () {
    $settlement = ($this->settlement)(4995);
    $event = 'eloquent.creating: '.IsraeliSalaryPayoutCarryEntry::class;
    Event::listen($event, fn () => throw new RuntimeException('Synthetic carry failure'));
    try { expect(fn () => ($this->pay)($settlement, 2000))->toThrow(RuntimeException::class); }
    finally { Event::forget($event); }
    expect(SalaryPayout::count())->toBe(0)->and(PartnerFinanceTransaction::count())->toBe(0)
        ->and(IsraeliSalaryPayoutCarryEntry::count())->toBe(0)->and($this->service->remaining($settlement))->toBe(4995.0)
        ->and(app(PartnerFinanceSummary::class)->accountBalances()['cash']['USD'])->toBe(20000.0);
});

test('the real salary modal accepts a rounded payout and the next modal deducts the GEL credit', function () {
    $case = LabCase::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'source' => 'israeli', 'case_date' => today()]);
    $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 1]);
    $page = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli');
    $key = array_key_first($page->get('mountedActions')[0]['data']['allocations']);
    $page->set("mountedActions.0.data.allocations.$key.amount", 2000)
        ->assertMountedActionModalSee(['5.00', __('salary-payout.new_advance')])->callMountedAction()->assertHasNoActionErrors();
    expect((float) SalarySettlement::sole()->salary_total)->toBe(4995.0)->and($this->credits->balances($this->doctor->id)['GEL'])->toBe(5.0);
    $this->doctor->update(['israeli_lab_zircon_rate' => 3750]);
    $later = LabCase::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'source' => 'israeli', 'case_date' => today()]);
    $later->mainWorks()->create(['material' => 'zircon', 'quantity' => 1]);
    $next = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli');
    $row = collect($next->get('mountedActions')[0]['data']['allocations'])->first();
    expect((float) $row['amount'])->toBe(1498.0);
    $next->assertMountedActionModalSee([__('salary-payout.previous_advance'), __('salary-payout.advance_applied')]);
});

test('the existing legacy USD carry ledger remains separate from rounded GEL credits', function () {
    ($this->pay)(($this->settlement)(4995), 2000);
    expect(app(IsraeliSalaryCarryService::class)->balance($this->doctor->id))->toBe(0.0)
        ->and(DB::table('israeli_salary_carry_entries')->count())->toBe(0)
        ->and($this->credits->balances($this->doctor->id)['GEL'])->toBe(5.0);
});

test('partial work composition counts an applied rounding credit exactly once across later payouts', function () {
    ($this->pay)(($this->settlement)(4995), 2000);
    $this->doctor->update(['israeli_lab_zircon_rate' => 3750]);
    $case = LabCase::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'source' => 'israeli', 'case_date' => today()]);
    $work = $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 1]);
    $first = $this->service->finalizeAndPay($this->doctor->id, '2026-11-01', '2026-11-17', [$work->id],
        [['source' => 'israeli', 'currency' => 'USD', 'amount' => 1000, 'exchange_rate' => 2.5]], (string) Str::uuid(), $this->owner);
    $snapshot = $first->composition_snapshot;
    expect((float) $snapshot['items'][0]['rounding_advance_applied'])->toBe(5.0)
        ->and((float) $snapshot['items'][0]['remaining'])->toBe(1245.0);
    $last = ($this->pay)($first->settlement, 1245, 'GEL');
    expect((float) $last->composition_snapshot['items'][0]['paid'])->toBe(1245.0)
        ->and((float) $last->composition_snapshot['items'][0]['remaining'])->toBe(0.0)
        ->and((float) $last->composition_snapshot['remaining_after'])->toBe(0.0)
        ->and($first->fresh()->composition_snapshot)->toBe($snapshot);
});
