<?php

use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\SalarySettlement;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\IsraeliDoctorPayoutHistory;
use App\Services\IsraeliSalaryPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function historySalaryWorks(Doctor $doctor, string $prefix): array
{
    $works = [];
    foreach ([2, 3] as $index => $quantity) {
        $patient = Patient::create(['first_name' => $prefix, 'last_name' => 'Patient'.($index + 1),
            'patient_group_id' => PatientGroup::israelPartnerId()]);
        $case = LabCase::create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'case_date' => today(), 'source' => 'israeli']);
        $works[] = $case->mainWorks()->create(['material' => 'zircon', 'quantity' => $quantity]);
    }

    return $works;
}

beforeEach(function () {
    $this->travelTo('2026-10-12 12:00:00');
    Http::preventStrayRequests();
    app()->setLocale('ka');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'History', 'last_name' => 'Doctor', 'is_active' => true,
        'compensation_percentage' => 40, 'israeli_lab_zircon_rate' => 100, 'israeli_salary_payment_method' => 'bank_transfer']);
    $this->works = historySalaryWorks($this->doctor, 'Included');
    $this->history = app(IsraeliDoctorPayoutHistory::class);
    $this->service = app(IsraeliSalaryPayoutService::class);
    $this->pay = fn (array $rows) => $this->service->finalizeAndPay($this->doctor->id, '2026-10-01', '2026-10-12',
        array_map(fn ($work) => $work->id, $this->works), $rows, (string) Str::uuid(), $this->owner);
});

test('Israeli profile history shows only its doctors payouts and exact currency method and date', function () {
    $payout = ($this->pay)([['source' => 'israeli', 'currency' => 'USD', 'amount' => 100, 'exchange_rate' => 5]]);
    $other = Doctor::create(['first_name' => 'Other', 'last_name' => 'Doctor', 'is_active' => true,
        'compensation_percentage' => 40, 'israeli_lab_zircon_rate' => 100, 'israeli_salary_payment_method' => 'bank_transfer']);
    $otherWorks = historySalaryWorks($other, 'Excluded');
    $otherPayout = $this->service->finalizeAndPay($other->id, '2026-10-01', '2026-10-12',
        array_map(fn ($work) => $work->id, $otherWorks), [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 500]], (string) Str::uuid(), $this->owner);
    $entry = $this->history->forDoctor($this->doctor->id)->sole();
    expect($entry['key'])->toBe('payout:'.$payout->id)
        ->and($entry['payments'][0]['amount'])->toBe(100.0)->and($entry['payments'][0]['currency'])->toBe('USD')
        ->and($entry['payments'][0]['method'])->toBe('bank_transfer')->and($entry['payments'][0]['rate'])->toBe('5.000000')
        ->and($entry['payments'][0]['equivalent'])->toBe(500.0)->and($entry['status'])->toBe('paid')
        ->and($entry['date']->toDateString())->toBe('2026-10-12');
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->assertSee('ისრაელის ანაზღაურება')->assertSee('$100.00')
        ->mountAction('israeliPayoutDetails', ['key' => $entry['key']])
        ->assertMountedActionModalSee(['Included Patient1', 'Included Patient2', 'ZIRCON', 'USD', '5', '500.00', 'Israeli'])
        ->assertMountedActionModalDontSee('Excluded Patient1');
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction('israeliPayoutDetails', ['key' => 'payout:'.$otherPayout->id])->assertNotFound();
});

test('partial payout contains only covered items and later payments preserve the first snapshot', function () {
    $first = ($this->pay)([['source' => 'israeli', 'currency' => 'GEL', 'amount' => 150]]);
    $before = $this->history->find($this->doctor->id, 'payout:'.$first->id);
    expect($before['accrued'])->toBe(500.0)->and($before['paid_basis'])->toBe(150.0)
        ->and($before['covered'])->toBe(150.0)->and($before['remaining'])->toBe(350.0)
        ->and($before['status'])->toBe('partial')->and($before['items'])->toHaveCount(1)
        ->and($before['items'][0]['patient'])->toBe('Included Patient1')
        ->and($before['items'][0]['accrued'])->toBe(200.0)->and($before['items'][0]['paid'])->toBe(150.0)
        ->and($before['items'][0]['remaining'])->toBe(50.0);
    $second = $this->service->payRemaining($first->salary_settlement_id,
        [['source' => 'clinic', 'currency' => 'GEL', 'amount' => 350]], (string) Str::uuid(), $this->owner);
    $after = $this->history->find($this->doctor->id, 'payout:'.$first->id);
    $last = $this->history->find($this->doctor->id, 'payout:'.$second->id);
    expect($after)->toEqual($before)->and($last['items'])->toHaveCount(2)
        ->and(array_column($last['items'], 'paid'))->toBe([50.0, 300.0])
        ->and(array_sum(array_column($last['items'], 'paid')))->toBe(350.0)
        ->and($last['remaining'])->toBe(0.0)->and($last['source'])->toBe('israeli')
        ->and($last['payments'][0]['funding'])->toBe('clinic');
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction('israeliPayoutDetails', ['key' => 'payout:'.$first->id])
        ->assertMountedActionModalSee(['Included Patient1', '150.00', '350.00'])
        ->assertMountedActionModalDontSee('Included Patient2');
});

test('new visits and changes to live work never alter recorded payout composition', function () {
    $payout = ($this->pay)([['source' => 'israeli', 'currency' => 'GEL', 'amount' => 500]]);
    $before = $this->history->find($this->doctor->id, 'payout:'.$payout->id);
    historySalaryWorks($this->doctor, 'New');
    $patient = Patient::create(['first_name' => 'New', 'last_name' => 'Visit', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $visit = Visit::create(['doctor_id' => $this->doctor->id, 'patient_id' => $patient->id,
        'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 1000]);
    $service = TreatmentCase::create(['name' => 'New therapy', 'category' => 'therapy', 'is_active' => true]);
    $visit->treatmentCaseItems()->create(['treatment_case_id' => $service->id, 'quantity' => 1, 'unit_price' => 1000]);
    $this->works[0]->update(['quantity' => 9]);
    $this->works[0]->labCase->patient->update(['first_name' => 'Renamed']);
    $this->doctor->update(['israeli_lab_zircon_rate' => 999, 'israeli_salary_payment_method' => 'cash']);
    expect($this->history->find($this->doctor->id, 'payout:'.$payout->id))->toEqual($before);
});

test('legacy full payout uses only explicitly linked salary items while legacy partials are not invented', function () {
    $payout = ($this->pay)([['source' => 'israeli', 'currency' => 'GEL', 'amount' => 500]]);
    DB::table('salary_payouts')->where('id', $payout->id)->update(['composition_snapshot' => null]);
    historySalaryWorks($this->doctor, 'Unlinked');
    $entry = $this->history->find($this->doctor->id, 'payout:'.$payout->id);
    expect($entry['composition'])->toBe('linked')->and($entry['items'])->toHaveCount(2)
        ->and(array_sum(array_column($entry['items'], 'paid')))->toBe(500.0);
    DB::table('salary_payouts')->where('id', $payout->id)->update(['total_gel' => 150]);
    $entry = $this->history->find($this->doctor->id, 'payout:'.$payout->id);
    expect($entry['composition'])->toBe('settlement')->and($entry['items'])->toHaveCount(2)
        ->and(array_column($entry['items'], 'paid'))->toBe([null, null])->and($entry['remaining'])->toBe(350.0);
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction('israeliPayoutDetails', ['key' => $entry['key']])
        ->assertMountedActionModalSee([__('israeli-payout-history.settlement_work'), __('israeli-payout-history.settlement_allocation_note'), 'Included Patient1', 'Included Patient2'])
        ->assertMountedActionModalDontSee([__('israeli-payout-history.unavailable'), __('israeli-payout-history.paid'), 'Unlinked Patient1']);
});

test('a payout after an unlinked historical partial does not fabricate item allocations', function () {
    $first = ($this->pay)([['source' => 'israeli', 'currency' => 'GEL', 'amount' => 150]]);
    DB::table('salary_payouts')->where('id', $first->id)->update(['composition_snapshot' => null]);
    $second = $this->service->payRemaining($first->salary_settlement_id,
        [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 350]], (string) Str::uuid(), $this->owner);
    expect($second->composition_snapshot)->toBeNull()
        ->and($this->history->find($this->doctor->id, 'payout:'.$second->id)['composition'])->toBe('settlement')
        ->and(array_column($this->history->find($this->doctor->id, 'payout:'.$second->id)['items'], 'paid'))->toBe([null, null]);
});

test('clinic salary stays separate and reading Israeli history changes no finance records', function () {
    ($this->pay)([['source' => 'israeli', 'currency' => 'GEL', 'amount' => 500]]);
    $clinic = SalarySettlement::create(['doctor_id' => $this->doctor->id, 'period_start' => today(), 'period_end' => today(),
        'settled_at' => now(), 'currency' => 'GEL', 'payment_currency' => 'GEL', 'payment_amount' => 999,
        'performed_total' => 0, 'paid_amount' => 0, 'outstanding_amount' => 0, 'direct_expense_total' => 0, 'base_total' => 0,
        'percentage' => 40, 'salary_total' => 999, 'status' => 'confirmed', 'patient_group_slug' => 'clinic']);
    $before = [DB::table('finance_transactions')->get()->toArray(), DB::table('partner_finance_transactions')->get()->toArray()];
    $entry = $this->history->forDoctor($this->doctor->id)->sole();
    expect($entry['key'])->not->toBe('settlement:'.$clinic->id);
    $this->history->find($this->doctor->id, $entry['key']);
    expect([DB::table('finance_transactions')->get()->toArray(), DB::table('partner_finance_transactions')->get()->toArray()])->toEqual($before);
});

test('legacy therapy payout reads performed manipulation snapshots without patient payment', function () {
    $patient = Patient::create(['first_name' => 'Therapy', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $visit = Visit::create(['doctor_id' => $this->doctor->id, 'patient_id' => $patient->id,
        'visit_date' => today(), 'currency' => 'USD', 'total_price' => 250]);
    $service = TreatmentCase::create(['name' => 'Performed therapy', 'category' => 'therapy', 'is_active' => true]);
    $work = $visit->treatmentCaseItems()->create(['treatment_case_id' => $service->id, 'quantity' => 2, 'unit_price' => 125]);
    $settlement = SalarySettlement::create(['doctor_id' => $this->doctor->id, 'period_start' => today(), 'period_end' => today(),
        'settled_at' => now(), 'currency' => 'USD', 'payment_currency' => 'USD', 'payment_amount' => 100,
        'performed_total' => 0, 'paid_amount' => 0, 'outstanding_amount' => 0, 'direct_expense_total' => 0, 'base_total' => 0,
        'percentage' => 40, 'salary_total' => 100, 'status' => 'confirmed', 'patient_group_slug' => PatientGroup::ISRAEL_PARTNER_SLUG]);
    $settlement->items()->create(['visit_id' => $visit->id, 'visit_treatment_case_id' => $work->id,
        'revenue' => 250, 'direct_expense' => 0, 'quantity_snapshot' => 2, 'salary_base' => 250, 'doctor_share' => 100, 'salary_percentage_snapshot' => 40,
        'patient_group_slug' => PatientGroup::ISRAEL_PARTNER_SLUG]);
    PartnerFinanceTransaction::create(['source' => 'israeli', 'type' => 'expense', 'category' => 'doctor_salary',
        'doctor_id' => $this->doctor->id, 'salary_settlement_id' => $settlement->id, 'transacted_at' => now(),
        'amount' => 100, 'currency' => 'USD', 'from_account' => 'cash']);
    $entry = $this->history->forDoctor($this->doctor->id)->sole();
    expect($entry['composition'])->toBe('linked')->and($entry['items'][0]['patient'])->toBe('Therapy Patient')
        ->and($entry['items'][0]['service'])->toBe('Performed therapy')->and($entry['items'][0]['quantity'])->toBe(2)
        ->and((float) $entry['items'][0]['percentage'])->toBe(40.0)->and($entry['items'][0]['paid'])->toBe(100.0)
        ->and($entry['payments'][0]['method'])->toBe('cash')->and($visit->payments()->count())->toBe(0);
});

test('legacy carry and detached movements show recorded amounts without inventing work composition', function () {
    $settlement = SalarySettlement::create(['doctor_id' => $this->doctor->id, 'period_start' => today(), 'period_end' => today(),
        'settled_at' => now(), 'currency' => 'USD', 'payment_currency' => 'USD', 'payment_amount' => 90,
        'actual_paid_usd' => 90, 'calculated_usd' => 100, 'opening_carry_usd' => 0, 'closing_carry_usd' => -10, 'difference_usd' => -10,
        'performed_total' => 0, 'paid_amount' => 0, 'outstanding_amount' => 0, 'direct_expense_total' => 0, 'base_total' => 0,
        'percentage' => 40, 'salary_total' => 100, 'status' => 'confirmed', 'patient_group_slug' => PatientGroup::ISRAEL_PARTNER_SLUG]);
    $movement = PartnerFinanceTransaction::create(['source' => 'israeli', 'type' => 'expense', 'category' => 'doctor_salary',
        'doctor_id' => $this->doctor->id, 'transacted_at' => now(), 'amount' => 75, 'currency' => 'USD', 'from_account' => 'bank']);
    $entry = $this->history->find($this->doctor->id, 'settlement:'.$settlement->id);
    expect($entry['composition'])->toBe('unavailable')->and($entry['payments'][0]['amount'])->toBe(90.0)
        ->and($entry['carry']['closing_carry_usd'])->toBe(-10.0);
    $settlement->items()->create(['lab_main_work_id' => $this->works[0]->id,
        'revenue' => 100, 'direct_expense' => 0, 'quantity_snapshot' => 2, 'unit_rate_snapshot' => 50,
        'base_snapshot' => 100, 'doctor_share_snapshot' => 100, 'salary_base' => 100, 'doctor_share' => 100]);
    $saved = $this->history->find($this->doctor->id, 'settlement:'.$settlement->id);
    expect($saved['composition'])->toBe('settlement')->and($saved['items'][0]['paid'])->toBeNull()
        ->and($saved['items'][0]['accrued'])->toBe(100.0)->and($saved['covered'])->toBeNull()
        ->and($saved['remaining'])->toBeNull()->and($saved['payments'][0]['amount'])->toBe(90.0);
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction('israeliPayoutDetails', ['key' => $saved['key']])
        ->assertMountedActionModalSee(['Included Patient1', '$90.00', __('israeli-payout-history.settlement_work')])
        ->assertMountedActionModalDontSee([__('israeli-payout-history.unavailable'), __('israeli-payout-history.paid')]);
    $entry = $this->history->find($this->doctor->id, 'movement:'.$movement->id);
    expect($entry['items'])->toBe([])->and($entry['payments'][0]['amount'])->toBe(75.0)
        ->and($entry['payments'][0]['method'])->toBe('bank_transfer');
});

test('non owners cannot access the Israeli profile history or its modal', function () {
    $payout = ($this->pay)([['source' => 'israeli', 'currency' => 'GEL', 'amount' => 500]]);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->assertDontSee('data-israeli-payout-history', false)->assertActionHidden('israeliPayoutDetails');
});

test('mixed currency payout reconciles work amounts in the saved GEL basis', function () {
    $payout = ($this->pay)([
        ['source' => 'israeli', 'currency' => 'USD', 'amount' => 50, 'exchange_rate' => 3],
        ['source' => 'clinic', 'currency' => 'GEL', 'amount' => 350],
    ]);
    $entry = $this->history->find($this->doctor->id, 'payout:'.$payout->id);
    expect(array_column($entry['payments'], 'currency'))->toBe(['USD', 'GEL'])
        ->and(array_sum(array_column($entry['payments'], 'equivalent')))->toBe(500.0)
        ->and(array_sum(array_column($entry['items'], 'paid')))->toBe(500.0)
        ->and($entry['basis_currency'])->toBe('GEL');
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction('israeliPayoutDetails', ['key' => $entry['key']])
        ->assertMountedActionModalSee(['$50.00', '350.00 ₾', '500.00 ₾', 'GEL ეკვივალენტი']);
});

test('external deduction is separate from actual paid work amounts and snapshot cannot be changed', function () {
    $this->doctor->update(['external_lab_enabled' => true, 'external_lab_zircon_rate' => 50]);
    $external = LabCase::create(['source' => 'external', 'case_date' => today(),
        'external_patient_name' => 'External Patient', 'external_billing_doctor_id' => $this->doctor->id]);
    $external->mainWorks()->create(['material' => 'zircon', 'quantity' => 1]);
    $payout = $this->service->finalizeAndPay($this->doctor->id, '2026-10-01', '2026-10-12',
        array_map(fn ($work) => $work->id, $this->works),
        [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 450]], (string) Str::uuid(), $this->owner, true);
    $entry = $this->history->find($this->doctor->id, 'payout:'.$payout->id);
    expect($entry['paid_basis'])->toBe(450.0)->and($entry['deducted'])->toBe(50.0)
        ->and($entry['covered'])->toBe(500.0)->and($entry['remaining'])->toBe(0.0)
        ->and(array_sum(array_column($entry['items'], 'paid')))->toBe(450.0)
        ->and(array_sum(array_column($entry['items'], 'deducted')))->toBe(50.0)
        ->and((float) PartnerFinanceTransaction::whereNotNull('salary_payout_allocation_id')->sum('amount'))->toBe(450.0);
    expect(fn () => $payout->update(['composition_snapshot' => []]))
        ->toThrow(ValidationException::class);
});

test('historical USD full payout displays saved settlement values without todays rates or new work', function () {
    $this->doctor->update(['israeli_lab_zircon_rate' => 1846]);
    $payout = ($this->pay)([['source' => 'israeli', 'currency' => 'USD', 'amount' => 3550, 'exchange_rate' => 2.6]]);
    DB::table('salary_payouts')->where('id', $payout->id)->update(['composition_snapshot' => null]);
    $this->doctor->update(['israeli_lab_zircon_rate' => 9999]);
    $this->works[0]->update(['quantity' => 9]);
    historySalaryWorks($this->doctor, 'Later');
    $entry = $this->history->find($this->doctor->id, 'payout:'.$payout->id);
    expect($entry['composition'])->toBe('linked')->and($entry['items'])->toHaveCount(2)
        ->and($entry['items'][0]['patient'])->toBe('Included Patient1')
        ->and($entry['items'][0]['service'])->toBe('ZIRCON')->and($entry['items'][0]['quantity'])->toBe(2)
        ->and((float) $entry['items'][0]['unit_rate'])->toBe(1846.0)
        ->and((float) $entry['items'][0]['base'])->toBe(3692.0)
        ->and($entry['items'][0]['accrued'])->toBe(3692.0)
        ->and(array_sum(array_column($entry['items'], 'paid')))->toBe(9230.0);
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction('israeliPayoutDetails', ['key' => $entry['key']])
        ->assertMountedActionModalSee(['Included Patient1', 'Included Patient2', 'ZIRCON', '12.10.2026', '1,846.00', '3,692.00', '9,230.00', '$3,550.00', '2.6'])
        ->assertMountedActionModalDontSee(['Later Patient1', '9,999.00', __('israeli-payout-history.unavailable')]);
});

test('multiple old partial payouts show complete settlement membership with no per work allocation', function () {
    $first = ($this->pay)([['source' => 'israeli', 'currency' => 'USD', 'amount' => 50, 'exchange_rate' => 3]]);
    $second = $this->service->payRemaining($first->salary_settlement_id,
        [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 100]], (string) Str::uuid(), $this->owner);
    DB::table('salary_payouts')->whereIn('id', [$first->id, $second->id])->update(['composition_snapshot' => null]);
    $before = [];
    foreach (['salary_payouts', 'salary_payout_allocations', 'salary_settlements', 'salary_settlement_items', 'finance_transactions', 'partner_finance_transactions', 'israeli_salary_carry_entries'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
    }
    $entry = $this->history->find($this->doctor->id, 'payout:'.$second->id);
    expect($entry['composition'])->toBe('settlement')->and($entry['accrued'])->toBe(500.0)
        ->and($entry['covered'])->toBe(250.0)->and($entry['remaining'])->toBe(250.0)
        ->and($entry['paid_basis'])->toBe(100.0)->and($entry['items'])->toHaveCount(2)
        ->and(array_column($entry['items'], 'paid'))->toBe([null, null])
        ->and(array_column($entry['items'], 'deducted'))->toBe([null, null])
        ->and(array_column($entry['items'], 'remaining'))->toBe([null, null]);
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction('israeliPayoutDetails', ['key' => 'payout:'.$first->id])
        ->assertMountedActionModalSee([__('israeli-payout-history.settlement_work'), __('israeli-payout-history.settlement_allocation_note'), 'Included Patient1', 'Included Patient2', '$50.00', '150.00', '350.00'])
        ->assertMountedActionModalDontSee([__('israeli-payout-history.paid'), __('israeli-payout-history.row_remaining'), __('israeli-payout-history.unavailable')]);
    foreach ($before as $table => $rows) {
        expect(DB::table($table)->orderBy('id')->get()->toArray())->toEqual($rows);
    }
});

test('new composition snapshot remains authoritative over different saved settlement items', function () {
    $payout = ($this->pay)([['source' => 'israeli', 'currency' => 'GEL', 'amount' => 150]]);
    $before = $this->history->find($this->doctor->id, 'payout:'.$payout->id);
    DB::table('salary_settlement_items')->where('salary_settlement_id', $payout->salary_settlement_id)->update(['doctor_share_snapshot' => 999, 'quantity_snapshot' => 99]);
    $entry = $this->history->find($this->doctor->id, 'payout:'.$payout->id);
    expect($entry)->toEqual($before)->and($entry['composition'])->toBe('snapshot')
        ->and($entry['items'])->toHaveCount(1)->and($entry['items'][0]['paid'])->toBe(150.0);
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction('israeliPayoutDetails', ['key' => $entry['key']])
        ->assertMountedActionModalSee('Included Patient1')->assertMountedActionModalDontSee(['Included Patient2', '999.00', __('israeli-payout-history.settlement_work')]);
});

test('historical payout genuinely missing both snapshot and settlement items stays unavailable', function () {
    $payout = ($this->pay)([['source' => 'israeli', 'currency' => 'GEL', 'amount' => 500]]);
    DB::table('salary_payouts')->where('id', $payout->id)->update(['composition_snapshot' => null]);
    DB::table('salary_settlement_items')->where('salary_settlement_id', $payout->salary_settlement_id)->delete();
    $entry = $this->history->find($this->doctor->id, 'payout:'.$payout->id);
    expect($entry['composition'])->toBe('unavailable')->and($entry['items'])->toBe([]);
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction('israeliPayoutDetails', ['key' => $entry['key']])
        ->assertMountedActionModalSee(__('israeli-payout-history.unavailable'))
        ->assertMountedActionModalDontSee(__('israeli-payout-history.settlement_work'));
});
