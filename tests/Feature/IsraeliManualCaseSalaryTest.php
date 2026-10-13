<?php

use App\Filament\Pages\DoctorCompensation;
use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Services\DoctorCompensationCalculator;
use App\Services\IsraeliDoctorPayoutHistory;
use App\Services\IsraeliLabSalaryItems;
use App\Services\IsraeliManualLabSalaryCases;
use App\Services\IsraeliSalaryPayoutService;
use App\Services\SalarySettlementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-13 12:00:00');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Manual', 'last_name' => 'Doctor', 'is_active' => true,
        'specialties' => ['orthopedics'], 'israeli_visit_salary_type' => 'fixed', 'israeli_visit_salary_rate' => 999,
        'israeli_salary_payment_method' => 'bank_transfer']);
    $this->patient = Patient::create(['first_name' => 'Meital', 'last_name' => 'Shkuri', 'birth_date' => '1983-07-28',
        'patient_group_id' => PatientGroup::israelPartnerId()]);
    $this->case = LabCase::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'source' => 'israeli', 'case_date' => today()]);
    $this->zircon = $this->case->mainWorks()->create(['material' => 'zircon', 'quantity' => 27, 'shade' => 'B12']);
    $this->pmma = $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 27, 'shade' => 'B14']);
    $this->manual = app(IsraeliManualLabSalaryCases::class);
    $this->payouts = app(IsraeliSalaryPayoutService::class);
});

function payManualCaseSalary($test, array $amounts, float $paid, ?string $key = null)
{
    return $test->payouts->finalizeAndPay($test->doctor->id, '2026-10-01', '2026-10-13', [],
        $paid > 0 ? [['source' => 'israeli', 'currency' => 'GEL', 'amount' => $paid]] : [],
        $key ?? (string) Str::uuid(), $test->owner, manualCaseAmounts: $amounts, manualCaseIds: array_keys($amounts));
}

test('fixed doctor sees one manual case with all main works without unit rate or quantity-based salary', function () {
    $this->zircon->update(['payable_quantity' => 0]);
    $this->case->mainWorks()->create(['material' => 'other', 'quantity' => 3]);
    $this->doctor->update(['israeli_lab_pmma_rate' => 25]);
    $groups = $this->manual->eligible($this->doctor);
    expect($groups)->toHaveCount(1)->and($groups->sole()['works'])->toHaveCount(3)
        ->and(app(IsraeliLabSalaryItems::class)->eligible($this->doctor))->toBeEmpty();
    $report = app(DoctorCompensationCalculator::class)->calculate($this->doctor->id, '2026-10-01', '2026-10-13',
        patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG, israeliLabOnly: true,
        manualCaseAmounts: [$this->case->id => 1000], selectedManualCaseIds: [$this->case->id]);
    expect($report['details'])->toHaveCount(1)->and($report['details'][0]['works'])->toHaveCount(3)
        ->and($report['totals']['GEL']['doctor_share'])->toBe(1000.0)->and($this->zircon->fresh()->quantity)->toBe(27);
});

test('unrelated cases stay independent while explicit same-case members share one manual fee', function () {
    $related = LabCase::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'source' => 'israeli',
        'case_date' => today(), 'related_case_id' => $this->case->id, 'case_relationship' => 'same_case']);
    $related->mainWorks()->create(['material' => 'other', 'quantity' => 2]);
    $other = LabCase::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'source' => 'israeli', 'case_date' => today()]);
    $other->mainWorks()->create(['material' => 'zircon', 'quantity' => 90]);
    expect($this->manual->eligible($this->doctor))->toHaveCount(2);
    $payout = payManualCaseSalary($this, [$this->case->id => 1000, $other->id => 500], 1500);
    expect((float) $payout->settlement->salary_total)->toBe(1500.0)
        ->and($payout->settlement->items()->whereNotNull('manual_lab_case_id')->pluck('doctor_share')->map(fn ($value) => (float) $value)->all())->toEqual([1000.0, 500.0])
        ->and($this->manual->eligible($this->doctor))->toBeEmpty()
        ->and((float) collect($payout->composition_snapshot['items'])->sum('accrued'))->toBe(1500.0);
});

test('manual partial payouts preserve snapshots and history after work patient or configuration changes', function () {
    $first = payManualCaseSalary($this, [$this->case->id => 1000], 200);
    $settlement = $first->settlement;
    $items = $settlement->items()->get()->toArray();
    $snapshot = $first->composition_snapshot;
    $this->zircon->update(['quantity' => 99]);
    $this->case->mainWorks()->create(['material' => 'other', 'quantity' => 7]);
    $this->patient->update(['last_name' => 'Changed']);
    $this->doctor->update(['israeli_visit_salary_rate' => 1234]);
    expect($this->manual->eligible($this->doctor))->toBeEmpty()
        ->and($settlement->items()->get()->toArray())->toEqual($items)
        ->and($first->fresh()->composition_snapshot)->toEqual($snapshot)
        ->and($this->payouts->remaining($settlement))->toBe(800.0);
    $next = $this->payouts->payRemaining($settlement->id, [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 800]], (string) Str::uuid(), $this->owner);
    $entry = app(IsraeliDoctorPayoutHistory::class)->find($this->doctor->id, 'payout:'.$next->id);
    expect($entry['items'])->toHaveCount(1)->and($entry['items'][0]['patient'])->toBe('Meital Shkuri')
        ->and($entry['items'][0]['works'][0]['quantity'])->toBe(27)
        ->and($entry['items'][0]['accrued'])->toBe(1000.0)->and($entry['remaining'])->toBe(0.0)
        ->and($entry['items'][0]['paid'])->toBe(800.0);
});

test('zero manual salary is explicitly confirmed without finance movements', function () {
    $before = DB::table('partner_finance_transactions')->count();
    $payout = payManualCaseSalary($this, [$this->case->id => 0], 0);
    expect((float) $payout->settlement->salary_total)->toBe(0.0)->and((float) $payout->total_gel)->toBe(0.0)
        ->and($this->manual->eligible($this->doctor))->toBeEmpty()
        ->and(DB::table('partner_finance_transactions')->count())->toBe($before)
        ->and($payout->composition_snapshot['items'])->toHaveCount(1);
});

test('blank negative fractional precision and stale case requests cannot be finalized', function ($amount) {
    expect(fn () => payManualCaseSalary($this, [$this->case->id => $amount], 1))->toThrow(ValidationException::class);
    expect(SalarySettlement::count())->toBe(0);
})->with([null, '', -1, 1.123]);

test('duplicate manual requests replay safely but changed amounts or new requests cannot pay the case twice', function () {
    $key = (string) Str::uuid();
    $payout = payManualCaseSalary($this, [$this->case->id => 1000], 1000, $key);
    expect(payManualCaseSalary($this, [$this->case->id => 1000], 1000, $key)->id)->toBe($payout->id);
    expect(fn () => payManualCaseSalary($this, [$this->case->id => 500], 500, $key))->toThrow(ValidationException::class);
    expect(fn () => payManualCaseSalary($this, [$this->case->id => 1000], 1000))->toThrow(ValidationException::class);
    expect(SalarySettlement::count())->toBe(1);
});

test('non Owners cannot submit manual amounts at either payout or settlement boundary', function (string $role) {
    $this->actingAs($actor = User::factory()->create(['role' => $role]));
    expect(fn () => $this->payouts->finalizeAndPay($this->doctor->id, '2026-10-01', '2026-10-13', [],
        [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 1000]], (string) Str::uuid(), $actor,
        manualCaseAmounts: [$this->case->id => 1000], manualCaseIds: [$this->case->id]))->toThrow(AuthorizationException::class);
    expect(fn () => app(SalarySettlementService::class)->settle($this->doctor->id, '2026-10-01', '2026-10-13', 0, $actor->id,
        patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG, israeliLabOnly: true, deferIsraeliPayment: true,
        manualCaseAmounts: [$this->case->id => 1000], selectedManualCaseIds: [$this->case->id]))->toThrow(AuthorizationException::class);
    expect(SalarySettlement::count())->toBe(0);
})->with([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('Owner salary UI exposes case inputs and selected manual total', function () {
    $page = Livewire::test(DoctorCompensation::class)->assertSee(__('manual-lab-salary.pending'))
        ->call('openDoctorSalary', $this->doctor->id, 'israeli')->assertMountedActionModalSee('Meital Shkuri');
    $page->set('mountedActions.0.data.manual_case_amounts.'.$this->case->id, 1000)
        ->set('mountedActions.0.data.selected_manual_case_ids', [(string) $this->case->id])
        ->assertMountedActionModalSee('1,000.00 ₾')->callMountedAction()->assertHasNoActionErrors();
    expect((float) SalarySettlement::sole()->salary_total)->toBe(1000.0);
});

test('Admin salary UI has no manual amount input or editable amount state', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $page = Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli');
    $page->assertMountedActionModalSee(__('manual-lab-salary.owner_only'));
    expect($page->html())->not->toContain('wire:model.live.debounce.200ms="mountedActions.0.data.manual_case_amounts.');
    $page->set('mountedActions.0.data.manual_case_amounts.'.$this->case->id, 1000)
        ->set('mountedActions.0.data.selected_manual_case_ids', [(string) $this->case->id])
        ->set('mountedActions.0.data.allocations', [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 1000]])
        ->callMountedAction();
    expect(SalarySettlement::count())->toBe(0);
});

test('explicit unit pricing remains per unit even alongside a fixed visit fee', function () {
    $this->doctor->update(['israeli_lab_zircon_rate' => 100, 'israeli_lab_pmma_rate' => 25]);
    $this->zircon->update(['payable_quantity' => 5]);
    expect($this->doctor->usesManualIsraeliLabSalary())->toBeFalse()
        ->and($this->manual->eligible($this->doctor))->toBeEmpty();
    $rows = app(IsraeliLabSalaryItems::class)->eligible($this->doctor);
    expect($rows)->toHaveCount(1)->and($rows->sole()->id)->toBe($this->zircon->id)
        ->and(app(IsraeliLabSalaryItems::class)->amount($rows->sole(), $this->doctor))->toBe(500.0);
});

test('historical manual group cannot become new unit work after compensation settings change', function () {
    $payout = payManualCaseSalary($this, [$this->case->id => 1000], 1000);
    $before = $payout->composition_snapshot;
    $this->doctor->update(['israeli_lab_zircon_rate' => 100]);
    $this->case->mainWorks()->create(['material' => 'zircon', 'quantity' => 4]);
    expect(app(IsraeliLabSalaryItems::class)->eligible($this->doctor))->toBeEmpty()
        ->and($payout->fresh()->composition_snapshot)->toEqual($before);
});

test('selected case must belong to the doctor source and root date range', function () {
    $this->case->update(['case_date' => '2026-09-01']);
    expect(fn () => payManualCaseSalary($this, [$this->case->id => 1000], 1000))->toThrow(ValidationException::class);
    $this->case->update(['case_date' => today(), 'source' => 'clinic']);
    expect(fn () => payManualCaseSalary($this, [$this->case->id => 1000], 1000))->toThrow(ValidationException::class);
    $other = Doctor::create(['first_name' => 'Other', 'last_name' => 'Doctor', 'is_active' => true]);
    $this->case->update(['source' => 'israeli', 'doctor_id' => $other->id]);
    expect(fn () => payManualCaseSalary($this, [$this->case->id => 1000], 1000))->toThrow(ValidationException::class);
    expect(SalarySettlement::count())->toBe(0);
});

test('Owner can confirm explicit zero through the manual case form', function () {
    Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'israeli')
        ->set('mountedActions.0.data.manual_case_amounts.'.$this->case->id, 0)
        ->set('mountedActions.0.data.selected_manual_case_ids', [(string) $this->case->id])
        ->callMountedAction()->assertHasNoActionErrors();
    expect((float) SalarySettlement::sole()->salary_total)->toBe(0.0)->and($this->manual->eligible($this->doctor))->toBeEmpty();
});

test('saved manual settlement amounts and composition cannot be edited retrospectively', function () {
    $payout = payManualCaseSalary($this, [$this->case->id => 1000], 1000);
    $item = $payout->settlement->items()->whereNotNull('manual_lab_case_id')->sole();
    $snapshot = $item->manual_case_snapshot;
    expect(fn () => $item->update(['doctor_share' => 500]))->toThrow(ValidationException::class);
    expect(fn () => $item->fresh()->update(['manual_case_snapshot' => []]))->toThrow(ValidationException::class);
    expect($item->fresh()->manual_case_snapshot)->toEqual($snapshot)->and((float) $item->fresh()->doctor_share)->toBe(1000.0);
});
