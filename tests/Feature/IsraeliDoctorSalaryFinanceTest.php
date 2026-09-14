<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\LabCase;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use App\Services\FinanceUsdUsageService;
use App\Services\IsraeliSalaryCarryService;
use App\Services\IsraeliSalaryPayoutService;
use App\Services\SalarySettlementService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake([config('services.nbg.rates_url') => Http::response('<Rates><CurrencyRate><Code>USD</Code><Quantity>1</Quantity><Rate>2.61</Rate></CurrencyRate></Rates>')]);
});

function israeliSalaryDoctor(string $first = 'David', string $last = 'Chumburidze'): Doctor
{
    return Doctor::create([
        'first_name' => $first,
        'last_name' => $last,
        'compensation_percentage' => 40,
        'israeli_lab_zircon_rate' => 100,
        'is_active' => true,
    ]);
}

function israeliSalaryPatient(string $first = 'Israeli', string $last = 'Patient'): Patient
{
    return Patient::create([
        'first_name' => $first,
        'last_name' => $last,
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
}

function israeliZirconWork(Doctor $doctor, Patient $patient, int $quantity = 2, string $date = '2026-09-01')
{
    $case = LabCase::create([
        'doctor_id' => $doctor->getKey(),
        'patient_id' => $patient->getKey(),
        'case_date' => $date,
        'source' => 'israeli',
    ]);

    return $case->mainWorks()->create([
        'material' => 'zircon',
        'quantity' => $quantity,
        'shade' => 'A1',
    ]);
}

function fundIsraeliSalary(Patient $patient, float $amount, string $currency): void
{
    $patient->partnerPayments()->create([
        'amount' => $amount,
        'currency' => $currency,
        'payment_method' => 'cash',
        'paid_at' => '2026-09-01',
    ]);
}

function settleIsraeliUsd(Doctor $doctor, ?float $actual = null): SalarySettlement
{
    return app(SalarySettlementService::class)->settle(
        $doctor->getKey(), '2026-09-01', '2026-09-30', 40, null, null,
        PatientGroup::ISRAEL_PARTNER_SLUG, 'USD', 2.5, $actual,
    )[0];
}

test('PMMA only Israeli work pays 25 GEL per unit once and keeps its rate snapshot', function () {
    $doctor = israeliSalaryDoctor();
    $doctor->update(['israeli_lab_zircon_rate' => null]);
    $patient = israeliSalaryPatient();
    $case = LabCase::create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'source' => 'israeli', 'case_date' => '2026-09-01']);
    $work = $case->mainWorks()->create(['material' => 'pmma', 'quantity' => 20]);
    fundIsraeliSalary($patient, 1000, 'USD');
    expect(app(DoctorCompensationCalculator::class)->defaultPeriodStart($doctor->id, PatientGroup::ISRAEL_PARTNER_SLUG))->toBe('2026-09-01');

    $settlement = settleIsraeliUsd($doctor);
    $item = $settlement->items()->sole();
    expect((float) $settlement->salary_total)->toBe(500.0)
        ->and((float) $settlement->actual_paid_usd)->toBe(200.0)
        ->and($item->lab_main_work_id)->toBe($work->id)
        ->and((float) $item->unit_rate_snapshot)->toBe(25.0)
        ->and($item->quantity_snapshot)->toBe(20)
        ->and(fn () => settleIsraeliUsd($doctor))->toThrow(ValidationException::class)
        ->and(SalarySettlementItem::query()->count())->toBe(1)
        ->and(PartnerFinanceTransaction::query()->count())->toBe(1);
    app(SalarySettlementService::class)->undo($settlement->id, $doctor->id);
    expect($work->fresh()->salarySettlementItem)->toBeNull()
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli')['USD'])->toBe(1000.0);
});

test('PMMA is suppressed only by Zircon in its existing salary group even after Zircon settlement', function (string $relationship, float $expected) {
    $doctor = israeliSalaryDoctor();
    $patient = israeliSalaryPatient();
    fundIsraeliSalary($patient, 2000, 'USD');
    $zircon = israeliZirconWork($doctor, $patient, 20);
    $case = $relationship === 'same_row_group' ? $zircon->labCase : LabCase::create([
        'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'case_date' => '2026-09-01',
        'source' => 'israeli', 'related_case_id' => $zircon->lab_case_id, 'case_relationship' => $relationship,
    ]);
    $pmma = $case->mainWorks()->create(['material' => 'pmma', 'quantity' => 20]);
    $settlement = settleIsraeliUsd($doctor);
    expect((float) $settlement->salary_total)->toBe($expected)
        ->and($settlement->items()->where('lab_main_work_id', $pmma->id)->exists())->toBe($relationship === 'new_case');

    $remaining = app(DoctorCompensationCalculator::class)->calculate($doctor->id, '2026-09-01', '2026-09-30', 40, null, PatientGroup::ISRAEL_PARTNER_SLUG);
    expect($remaining['details'])->toBeEmpty()
        ->and(fn () => settleIsraeliUsd($doctor))->toThrow(ValidationException::class);
})->with(['same Lab Work' => ['same_row_group', 2000.0], 'linked same case' => ['same_case', 2000.0], 'separate case' => ['new_case', 2500.0]]);

test('PMMA checkbox exclusion remains pending and the modal renders calendars and payment allocations', function () {
    $this->travelTo('2026-09-07 10:00:00');
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $doctor = israeliSalaryDoctor();
    $patient = israeliSalaryPatient('Temporary', 'Patient');
    $case = LabCase::create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'source' => 'israeli', 'case_date' => '2026-09-01']);
    $pmma = $case->mainWorks()->create(['material' => 'pmma', 'quantity' => 20]);
    $zircon = israeliZirconWork($doctor, israeliSalaryPatient('Zircon', 'Patient'), 2);
    fundIsraeliSalary($patient, 1000, 'USD');
    $action = TestAction::make('calculateSalary')->schemaComponent('compensation');
    $component = Livewire::actingAs($owner)->test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])
        ->mountAction($action)->set('mountedActions.0.data.patient_group', PatientGroup::ISRAEL_PARTNER_SLUG)
        ->assertMountedActionModalSee(['Temporary Patient', 'PMMA', '500.00 ₾', __('salary-payout.salary'), __('salary-payout.add')])
        ->assertMountedActionModalDontSee(['სხვაობა', 'ექიმის %'])
        ->assertMountedActionModalSee(['fi-fo-date-time-picker-trigger', 'togglePanelVisibility()', 'DD.MM.YYYY',
            'fi-fo-date-time-picker-calendar', 'querySelector', 'კალენდარი'])
        ->set('mountedActions.0.data.allocations', [['currency' => 'USD', 'source' => 'israeli', 'amount' => 80, 'exchange_rate' => 2.5]])
        ->assertMountedActionModalSee([__('salary-payout.rate'), '700.00 ₾', '200.00 ₾'])
        ->set('mountedActions.0.data.selected_lab_work_ids', [(string) $zircon->id])
        ->assertMountedActionModalSee('200.00 ₾')
        ->callMountedAction()->assertHasNoActionErrors();
    expect($pmma->fresh()->salarySettlementItem)->toBeNull()
        ->and(SalarySettlement::query()->sole()->items()->sole()->lab_main_work_id)->toBe($zircon->id);

    $next = Livewire::actingAs($owner)->test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])
        ->mountAction($action)->set('mountedActions.0.data.patient_group', PatientGroup::ISRAEL_PARTNER_SLUG);
    expect(data_get($next->get('mountedActions'), '0.data.selected_lab_work_ids'))->toBe([(string) $pmma->id]);
});

test('Israeli modal settles only checked lab rows and selects skipped work on the next calculation', function () {
    $this->travelTo('2026-09-07 10:00:00');
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $doctor = israeliSalaryDoctor();
    $patient = israeliSalaryPatient('Sharon', 'David');
    $first = israeliZirconWork($doctor, $patient, 26);
    $skipped = israeliZirconWork($doctor, israeliSalaryPatient('Avraham', 'Cohen'), 26);
    $third = israeliZirconWork($doctor, israeliSalaryPatient('Moshe', 'Cohen'), 12);
    fundIsraeliSalary($patient, 5000, 'USD');

    // An eligible Israeli visit must not leak into this Lab-only flow.
    $catalog = TreatmentCase::create(['name' => 'Unrelated Visit Work', 'category' => 'therapy', 'is_active' => true]);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id,
        'visit_date' => '2026-09-01', 'currency' => 'GEL', 'total_price' => 500]);
    $visitItem = $visit->treatmentCaseItems()->create(['treatment_case_id' => $catalog->id, 'quantity' => 1, 'unit_price' => 500]);

    $action = TestAction::make('calculateSalary')->schemaComponent('compensation');
    $component = Livewire::actingAs($owner)->test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])
        ->mountAction($action)
        ->set('mountedActions.0.data.patient_group', PatientGroup::ISRAEL_PARTNER_SLUG)
        ->assertMountedActionModalSee(['Sharon David', 'Avraham Cohen', 'Moshe Cohen', '6,400.00 ₾'])
        ->assertMountedActionModalDontSee(['Unrelated Visit Work', 'ვიზიტის ჩათვლით', 'Opening carry', 'Closing carry', 'Converted salary']);

    expect(data_get($component->get('mountedActions'), '0.data.selected_lab_work_ids'))
        ->toEqualCanonicalizing(array_map('strval', [$first->id, $skipped->id, $third->id]));

    $component->set('mountedActions.0.data.selected_lab_work_ids', [(string) $first->id, (string) $skipped->id])
        ->set('mountedActions.0.data.allocations', [['currency' => 'USD', 'source' => 'israeli', 'amount' => 2000, 'exchange_rate' => 2.61]])
        ->assertMountedActionModalSee(['5,220.00 ₾', '20.00 ₾', __('salary-payout.advance')])
        ->set('mountedActions.0.data.selected_lab_work_ids', [(string) $first->id, (string) $third->id])
        ->set('mountedActions.0.data.allocations', [['currency' => 'USD', 'source' => 'israeli', 'amount' => 1520, 'exchange_rate' => 2.5]])
        ->assertMountedActionModalSee('3,800.00 ₾')
        ->callMountedAction()->assertHasNoActionErrors();

    $settlement = SalarySettlement::query()->sole();
    expect((float) $settlement->salary_total)->toBe(3800.0)
        ->and($settlement->items()->pluck('lab_main_work_id')->all())->toEqualCanonicalizing([$first->id, $third->id])
        ->and($skipped->fresh()->salarySettlementItem)->toBeNull()
        ->and(SalarySettlementItem::query()->where('visit_treatment_case_id', $visitItem->id)->exists())->toBeFalse()
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli')['USD'])->toBe(3480.0);

    $next = Livewire::actingAs($owner)->test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])
        ->mountAction($action)
        ->set('mountedActions.0.data.patient_group', PatientGroup::ISRAEL_PARTNER_SLUG)
        ->set('mountedActions.0.data.allocations', [['currency' => 'USD', 'source' => 'israeli', 'amount' => 1040, 'exchange_rate' => 2.5]])
        ->assertMountedActionModalSee(['Avraham Cohen', '2,600.00 ₾']);
    expect(data_get($next->get('mountedActions'), '0.data.selected_lab_work_ids'))->toBe([(string) $skipped->id]);
    $next->callMountedAction()->assertHasNoActionErrors();
    expect($skipped->fresh()->salarySettlementItem)->not->toBeNull()
        ->and(app(IsraeliSalaryCarryService::class)->balance($doctor->id))->toBe(0.0);
});

test('an empty Israeli lab selection cannot finalize work or post finance', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $doctor = israeliSalaryDoctor();
    $work = israeliZirconWork($doctor, israeliSalaryPatient());
    $action = TestAction::make('calculateSalary')->schemaComponent('compensation');
    Livewire::actingAs($owner)->test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])
        ->mountAction($action)
        ->set('mountedActions.0.data.patient_group', PatientGroup::ISRAEL_PARTNER_SLUG)
        ->set('mountedActions.0.data.selected_lab_work_ids', [])
        ->callMountedAction()->assertHasActionErrors(['selected_lab_work_ids']);

    expect(fn () => app(SalarySettlementService::class)->settle(
        $doctor->id, '2026-09-01', '2026-09-30', 40, null, null,
        PatientGroup::ISRAEL_PARTNER_SLUG, 'USD', 2.5, null, [], true,
    ))->toThrow(ValidationException::class)
        ->and(SalarySettlement::query()->count())->toBe(0)
        ->and(PartnerFinanceTransaction::query()->count())->toBe(0)
        ->and($work->fresh()->salarySettlementItem)->toBeNull();
});

test('Israeli actual USD payment stores differences and posts only physical cash', function (float $actual, float $difference) {
    $doctor = israeliSalaryDoctor();
    $doctor->update(['israeli_lab_zircon_rate' => 1490]);
    $patient = israeliSalaryPatient();
    $work = israeliZirconWork($doctor, $patient, 1);
    fundIsraeliSalary($patient, 2000, 'USD');

    $settlement = settleIsraeliUsd($doctor, $actual);
    $finance = app(FinanceUsdUsageService::class);
    $carry = app(IsraeliSalaryCarryService::class);
    $finance->recordIsraeliDoctorSalary($settlement);

    expect((float) $settlement->calculated_usd)->toBe(596.0)
        ->and((float) $settlement->actual_paid_usd)->toBe($actual)
        ->and((float) $settlement->difference_usd)->toBe($difference)
        ->and((float) $settlement->opening_carry_usd)->toBe(0.0)
        ->and((float) $settlement->closing_carry_usd)->toBe($difference)
        ->and((float) $settlement->gel_salary_basis)->toBe(1490.0)
        ->and((float) $settlement->payment_exchange_rate)->toBe(2.5)
        ->and($carry->balance($doctor->id))->toBe($difference)
        ->and(PartnerFinanceTransaction::query()->count())->toBe(1)
        ->and((float) PartnerFinanceTransaction::query()->sole()->amount)->toBe($actual)
        ->and($finance->cashBalances('israeli')['USD'])->toBe(2000 - $actual)
        ->and(CashboxTransaction::query()->count())->toBe(0);

    expect(fn () => settleIsraeliUsd($doctor, $actual))->toThrow(ValidationException::class)
        ->and(DB::table('israeli_salary_carry_entries')->count())->toBe(1);

    $service = app(SalarySettlementService::class);
    expect($service->undo($settlement->id, $doctor->id))->toBeTrue()
        ->and($service->undo($settlement->id, $doctor->id))->toBeFalse()
        ->and($finance->recordIsraeliDoctorSalary($settlement))->toBeNull()
        ->and($carry->balance($doctor->id))->toBe(0.0)
        ->and($finance->cashBalances('israeli')['USD'])->toBe(2000.0)
        ->and(DB::table('israeli_salary_carry_entries')->count())->toBe(2);

    $snapshot = json_decode(DB::table('israeli_salary_carry_entries')->where('kind', 'settlement')->sole()->snapshot, true);
    expect((float) $snapshot['actual_paid_usd'])->toBe($actual)
        ->and($snapshot['settled_at'])->not->toBeNull()
        ->and(settleIsraeliUsd($doctor)->items()->sole()->lab_main_work_id)->toBe($work->id);
})->with(['exact' => [596.0, 0.0], 'advance' => [600.0, 4.0], 'remaining' => [590.0, -6.0]]);

test('Israeli carry adjusts the next payout and survives out of order undo', function (float $actual, float $opening, float $nextPayout) {
    $doctor = israeliSalaryDoctor();
    $doctor->update(['israeli_lab_zircon_rate' => 1490]);
    $patient = israeliSalaryPatient();
    fundIsraeliSalary($patient, 3000, 'USD');
    israeliZirconWork($doctor, $patient, 1);
    $first = settleIsraeliUsd($doctor, $actual);
    israeliZirconWork($doctor, $patient, 1, '2026-09-07');
    $second = settleIsraeliUsd($doctor);
    $carry = app(IsraeliSalaryCarryService::class);

    expect((float) $second->opening_carry_usd)->toBe($opening)
        ->and((float) $second->calculated_usd)->toBe($nextPayout)
        ->and((float) $second->actual_paid_usd)->toBe($nextPayout)
        ->and((float) $second->closing_carry_usd)->toBe(0.0)
        ->and($carry->balance($doctor->id))->toBe(0.0);

    app(SalarySettlementService::class)->undo($first->id, $doctor->id);
    expect($carry->balance($doctor->id))->toBe(-$opening)
        ->and((float) $second->fresh()->opening_carry_usd)->toBe($opening)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli')['USD'])->toBe(3000 - $nextPayout);

    app(SalarySettlementService::class)->undo($second->id, $doctor->id);
    expect($carry->balance($doctor->id))->toBe(0.0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli')['USD'])->toBe(3000.0);
})->with(['advance' => [600.0, 4.0, 592.0], 'remaining' => [590.0, -6.0, 602.0]]);

test('an advance larger than the next salary carries the unused credit without negative cash', function () {
    $doctor = israeliSalaryDoctor();
    $patient = israeliSalaryPatient();
    fundIsraeliSalary($patient, 500, 'USD');
    israeliZirconWork($doctor, $patient, 1);
    settleIsraeliUsd($doctor, 200);
    israeliZirconWork($doctor, $patient, 1, '2026-09-07');
    $next = settleIsraeliUsd($doctor);

    expect((float) $next->calculated_usd)->toBe(0.0)
        ->and((float) $next->actual_paid_usd)->toBe(0.0)
        ->and((float) $next->opening_carry_usd)->toBe(160.0)
        ->and((float) $next->closing_carry_usd)->toBe(120.0)
        ->and(PartnerFinanceTransaction::query()->count())->toBe(1)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli')['USD'])->toBe(300.0);
});

test('invalid actual USD payouts roll back settlement and carry', function () {
    $doctor = israeliSalaryDoctor();
    $patient = israeliSalaryPatient();
    israeliZirconWork($doctor, $patient);

    expect(fn () => settleIsraeliUsd($doctor, -1))->toThrow(ValidationException::class)
        ->and(SalarySettlement::query()->count())->toBe(0)
        ->and(DB::table('israeli_salary_carry_entries')->count())->toBe(0)
        ->and(PartnerFinanceTransaction::query()->count())->toBe(0);
});

test('both salary interfaces accept allocations and preserve the unpaid GEL remainder', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $doctor = israeliSalaryDoctor();
    $doctor->update(['israeli_lab_zircon_rate' => 1490]);
    $patient = israeliSalaryPatient();
    fundIsraeliSalary($patient, 3000, 'USD');
    israeliZirconWork($doctor, $patient, 1);

    Livewire::actingAs($owner)->test(DoctorCompensation::class)
        ->call('openDoctorSalary', $doctor->id, 'israeli')
        ->set('mountedActions.0.data.from', '2026-09-01')
        ->set('mountedActions.0.data.until', '2026-09-30')
        ->set('mountedActions.0.data.allocations', [['currency' => 'USD', 'source' => 'israeli', 'amount' => 596, 'exchange_rate' => 2.5]])
        ->assertMountedActionModalSee('1,490.00 ₾')
        ->callMountedAction()->assertHasNoActionErrors();

    expect((float) SalarySettlement::query()->sole()->payouts()->sole()->total_gel)->toBe(1490.0);
    israeliZirconWork($doctor, $patient, 1, '2026-09-07');

    $action = TestAction::make('calculateSalary')->schemaComponent('compensation');
    $component = Livewire::actingAs($owner)->test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])
        ->mountAction($action)
        ->set('mountedActions.0.data.patient_group', PatientGroup::ISRAEL_PARTNER_SLUG)
        ->set('mountedActions.0.data.from', '2026-09-01')
        ->set('mountedActions.0.data.until', '2026-09-30')
        ->set('mountedActions.0.data.allocations', [['currency' => 'USD', 'source' => 'israeli', 'amount' => 590, 'exchange_rate' => 2.5]])
        ->assertMountedActionModalSee(['1,490.00 ₾', '1,475.00 ₾', '15.00 ₾'])
        ->assertMountedActionModalDontSee(['Opening carry', 'Closing carry', 'Converted salary', 'ვიზიტის ჩათვლით']);

    $component->callMountedAction()->assertHasNoActionErrors();
    expect(app(IsraeliSalaryPayoutService::class)->remaining(SalarySettlement::query()->latest('id')->first()))->toBe(15.0)
        ->and(app(IsraeliSalaryCarryService::class)->balance($doctor->id))->toBe(0.0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances('israeli')['USD'])->toBe(1814.0);
});

test('Israeli USD carry is isolated per doctor and is not consumed by GEL payouts', function () {
    $doctor = israeliSalaryDoctor();
    $other = israeliSalaryDoctor('Other', 'Doctor');
    $patient = israeliSalaryPatient();
    fundIsraeliSalary($patient, 1000, 'USD');
    fundIsraeliSalary($patient, 1000, 'GEL');
    israeliZirconWork($doctor, $patient, 1);
    settleIsraeliUsd($doctor, 50);
    israeliZirconWork($other, $patient, 1);
    expect((float) settleIsraeliUsd($other)->calculated_usd)->toBe(40.0);

    israeliZirconWork($doctor, $patient, 1, '2026-09-07');
    $gel = app(SalarySettlementService::class)->settle(
        $doctor->id, '2026-09-01', '2026-09-30', 40, null, null, PatientGroup::ISRAEL_PARTNER_SLUG, 'GEL',
    )[0];
    expect($gel->actual_paid_usd)->toBeNull()
        ->and((float) $gel->payment_amount)->toBe(100.0)
        ->and(app(IsraeliSalaryCarryService::class)->balance($doctor->id))->toBe(10.0)
        ->and(app(IsraeliSalaryCarryService::class)->balance($other->id))->toBe(0.0);
});

test('Israeli filtering keeps every lab item by its work source snapshot', function () {
    $this->travelTo('2026-09-06 10:00:00');
    $doctor = israeliSalaryDoctor();
    $patient = israeliSalaryPatient();
    $first = israeliZirconWork($doctor, $patient, 2, '2026-09-01');
    $second = $first->labCase->mainWorks()->create([
        'material' => 'zircon',
        'quantity' => 1,
        'shade' => 'A2',
    ]);

    $patient->update(['patient_group_id' => PatientGroup::clinicId()]);

    $calculator = app(DoctorCompensationCalculator::class);
    $israeli = $calculator->calculate(
        $doctor->getKey(),
        '2026-09-01',
        '2026-09-06',
        40,
        null,
        PatientGroup::ISRAEL_PARTNER_SLUG,
    );
    $clinic = $calculator->calculate(
        $doctor->getKey(),
        '2026-09-01',
        '2026-09-06',
        40,
        null,
        PatientGroup::CLINIC_SLUG,
    );

    expect(collect($israeli['details'])->pluck('source_key')->all())->toBe([
        'lab-'.$first->getKey(),
        'lab-'.$second->getKey(),
    ])->and(collect($israeli['details'])->flatMap(fn (array $row): array => $row['items'])->pluck('id')->all())
        ->toBe([$first->getKey(), $second->getKey()])
        ->and($clinic['details'])->toBeEmpty()
        ->and($calculator->defaultPeriodStart($doctor->getKey(), PatientGroup::ISRAEL_PARTNER_SLUG))
        ->toBe('2026-09-01');
});

test('doctor salary calculation defaults to Clinic while the overview shows both source amounts', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $doctor = israeliSalaryDoctor('Filter', 'Doctor');
    $patient = israeliSalaryPatient('Preview', 'Patient');
    $work = israeliZirconWork($doctor, $patient, 2);
    $otherPatient = israeliSalaryPatient('Second', 'Lab Patient');
    $otherWork = israeliZirconWork($doctor, $otherPatient, 1);

    Livewire::actingAs($owner)->test(DoctorCompensation::class)
        ->assertSet('staffTypeFilter', 'doctors')
        ->assertSee(__('salaries.clinic'))->assertSee(__('salaries.israeli'))
        ->assertDontSeeHtml('<option value="all">');

    $action = TestAction::make('calculateSalary')->schemaComponent('compensation');
    $component = Livewire::actingAs($owner)
        ->test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])
        ->mountAction($action)
        ->assertMountedActionModalDontSee('ყველა');

    expect(data_get($component->get('mountedActions'), '0.data.patient_group'))
        ->toBe(PatientGroup::CLINIC_SLUG);

    $component->set('mountedActions.0.data.patient_group', PatientGroup::ISRAEL_PARTNER_SLUG)
        ->assertMountedActionModalSee([
            'Preview Patient',
            'Second Lab Patient',
            '300.00 ₾',
        ])->assertMountedActionModalDontSee('Lab #');
});

test('Israeli GEL salary can be paid in USD with immutable payout snapshots', function () {
    $doctor = israeliSalaryDoctor();
    $patient = israeliSalaryPatient();
    israeliZirconWork($doctor, $patient, 2);
    fundIsraeliSalary($patient, 500, 'USD');

    expect(fn () => app(SalarySettlementService::class)->settle(
        $doctor->getKey(),
        '2026-09-01',
        '2026-09-06',
        40,
        null,
        null,
        PatientGroup::ISRAEL_PARTNER_SLUG,
        'USD',
    ))->toThrow(ValidationException::class)
        ->and(SalarySettlement::query()->count())->toBe(0)
        ->and(PartnerFinanceTransaction::query()->count())->toBe(0);

    $settlement = app(SalarySettlementService::class)->settle(
        $doctor->getKey(),
        '2026-09-01',
        '2026-09-06',
        40,
        null,
        null,
        PatientGroup::ISRAEL_PARTNER_SLUG,
        'USD',
        2.5,
    )[0];
    $movement = PartnerFinanceTransaction::query()->sole();
    $historyEntry = PartnerFinanceEntry::query()->where('entry_key', 'transaction-'.$movement->getKey())->sole();

    expect($settlement->currency)->toBe('GEL')
        ->and((float) $settlement->salary_total)->toBe(200.0)
        ->and($settlement->payment_currency)->toBe('USD')
        ->and((float) $settlement->payment_exchange_rate)->toBe(2.5)
        ->and((float) $settlement->payment_amount)->toBe(80.0)
        ->and($settlement->items()->sole()->patient_group_slug)->toBe(PatientGroup::ISRAEL_PARTNER_SLUG)
        ->and($settlement->settled_at)->not->toBeNull()
        ->and($movement->category)->toBe('doctor_salary')
        ->and($movement->doctor_id)->toBe($doctor->getKey())
        ->and($movement->salary_settlement_id)->toBe($settlement->getKey())
        ->and($movement->currency)->toBe('USD')
        ->and((float) $movement->amount)->toBe(80.0)
        ->and($historyEntry->category)->toBe('doctor_salary')
        ->and($historyEntry->doctor_id)->toBe($doctor->getKey())
        ->and($historyEntry->salary_settlement_id)->toBe($settlement->getKey())
        ->and(app(FinanceUsdUsageService::class)->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI)['USD'])
        ->toBe(420.0)
        ->and(FinanceTransaction::query()->count())->toBe(0)
        ->and(CashboxTransaction::query()->count())->toBe(0);
});

test('Israeli salary paid in GEL deducts only the Israeli GEL balance', function () {
    $doctor = israeliSalaryDoctor();
    $patient = israeliSalaryPatient();
    israeliZirconWork($doctor, $patient, 2);
    fundIsraeliSalary($patient, 500, 'GEL');
    fundIsraeliSalary($patient, 300, 'USD');

    $settlement = app(SalarySettlementService::class)->settle(
        $doctor->getKey(),
        '2026-09-01',
        '2026-09-06',
        40,
        null,
        null,
        PatientGroup::ISRAEL_PARTNER_SLUG,
        'GEL',
    )[0];

    expect($settlement->payment_currency)->toBe('GEL')
        ->and($settlement->payment_exchange_rate)->toBeNull()
        ->and((float) $settlement->payment_amount)->toBe(200.0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI))
        ->toBe(['GEL' => 300.0, 'USD' => 300.0]);
});

test('Israeli salary finance posting is idempotent and undo restores balance and eligibility', function () {
    $doctor = israeliSalaryDoctor();
    $patient = israeliSalaryPatient();
    $work = israeliZirconWork($doctor, $patient, 2);
    fundIsraeliSalary($patient, 500, 'USD');
    $service = app(SalarySettlementService::class);
    $finance = app(FinanceUsdUsageService::class);

    $settlement = $service->settle(
        $doctor->getKey(),
        '2026-09-01',
        '2026-09-06',
        40,
        null,
        null,
        PatientGroup::ISRAEL_PARTNER_SLUG,
        'USD',
        2.5,
    )[0];
    $finance->recordIsraeliDoctorSalary($settlement);

    expect(PartnerFinanceTransaction::query()->count())->toBe(1)
        ->and($finance->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI)['USD'])->toBe(420.0)
        ->and(fn () => $service->settle(
            $doctor->getKey(),
            '2026-09-01',
            '2026-09-06',
            40,
            null,
            null,
            PatientGroup::ISRAEL_PARTNER_SLUG,
            'USD',
            2.5,
        ))->toThrow(ValidationException::class)
        ->and(PartnerFinanceTransaction::query()->count())->toBe(1);

    expect($service->undo($settlement->getKey(), $doctor->getKey()))->toBeTrue()
        ->and(SalarySettlement::query()->count())->toBe(0)
        ->and(PartnerFinanceTransaction::query()->count())->toBe(0)
        ->and($finance->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI)['USD'])->toBe(500.0);

    $eligibleIds = collect(app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(),
        '2026-09-01',
        '2026-09-06',
        40,
        null,
        PatientGroup::ISRAEL_PARTNER_SLUG,
    )['details'])->flatMap(fn (array $row): array => $row['items'])->pluck('id')->all();

    expect($eligibleIds)->toBe([$work->getKey()]);
});

test('Clinic salary finalization never posts to Israeli Finance or changes Clinic Cashbox', function () {
    $doctor = israeliSalaryDoctor('Clinic', 'Doctor');
    $patient = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Patient']);
    $catalog = TreatmentCase::create(['name' => 'Clinic work', 'category' => 'therapy', 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => '2026-09-01',
        'currency' => 'GEL',
        'total_price' => 500,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $catalog->getKey(),
        'quantity' => 1,
        'unit_price' => 500,
    ]);
    $visit->payments()->create([
        'amount' => 500,
        'currency' => 'GEL',
        'payment_date' => '2026-09-01',
        'payment_method' => 'cash',
    ]);
    $cashboxCount = CashboxTransaction::query()->count();

    app(SalarySettlementService::class)->settle(
        $doctor->getKey(),
        '2026-09-01',
        '2026-09-06',
        40,
        null,
        null,
        PatientGroup::CLINIC_SLUG,
    );

    expect(PartnerFinanceTransaction::query()->count())->toBe(0)
        ->and(CashboxTransaction::query()->count())->toBe($cashboxCount);
});
