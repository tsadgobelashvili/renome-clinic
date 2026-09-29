<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Models\Doctor;
use App\Models\ExternalLabDeduction;
use App\Models\LabCase;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\SalaryPayout;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Services\ExternalLabCaseData;
use App\Services\ExternalLabDebt;
use App\Services\IsraeliSalaryPayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 9, 29));
    Cache::put('nbg:official-rate:USD:2026-09-29', 2.7, now()->endOfDay());
    Http::preventStrayRequests();
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'External', 'last_name' => 'Doctor', 'is_active' => true,
        'israeli_lab_zircon_rate' => 100, 'external_lab_enabled' => true, 'external_lab_zircon_rate' => 100, 'external_lab_pmma_rate' => 25]);
    $this->patient = Patient::create(['first_name' => 'Test', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $case = LabCase::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id, 'case_date' => today(), 'source' => 'israeli']);
    $this->work = $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 10]);
    $this->external = LabCase::create(['source' => 'external', 'case_date' => today(), 'external_patient_name' => 'Outside patient', 'external_billing_doctor_id' => $this->doctor->id]);
    $this->outsideWork = $this->external->mainWorks()->create(['material' => 'zircon', 'quantity' => 3]);
    $this->patient->partnerPayments()->create(['amount' => 3000, 'currency' => 'GEL', 'payment_method' => 'cash', 'paid_at' => now()]);
    $this->pay = fn ($amount, $deduct, $key = null) => app(IsraeliSalaryPayoutService::class)->finalizeAndPay(
        $this->doctor->id, '2026-09-01', '2026-09-29', [$this->work->id],
        $amount ? [['source' => 'israeli', 'currency' => 'GEL', 'amount' => $amount]] : [],
        $key ?? (string) Str::uuid(), $this->owner, $deduct);
});

test('external rates snapshot on entry and quantity edits retain the old rate', function () {
    expect(app(ExternalLabDebt::class)->balance($this->doctor->id))->toBe(300.0);
    $this->doctor->update(['external_lab_zircon_rate' => 120]);
    $this->outsideWork->update(['quantity' => 4]);
    expect(app(ExternalLabDebt::class)->balance($this->doctor->id))->toBe(400.0);
    $this->external->mainWorks()->create(['material' => 'pmma', 'quantity' => 2]);
    expect(app(ExternalLabDebt::class)->balance($this->doctor->id))->toBe(450.0);
});

test('unchecked debt stays pending while the full salary is paid', function () {
    $payout = ($this->pay)(1000, false);
    expect((float) $payout->external_deduction_gel)->toBe(0.0)
        ->and(app(ExternalLabDebt::class)->balance($this->doctor->id))->toBe(300.0)
        ->and(ExternalLabDeduction::count())->toBe(0);
});

test('offset consumes debt once and only the net amount becomes a money outflow', function () {
    $key = (string) Str::uuid();
    $payout = ($this->pay)(700, true, $key);
    expect((float) $payout->total_gel)->toBe(1000.0)->and((float) $payout->external_deduction_gel)->toBe(300.0)
        ->and(app(IsraeliSalaryPayoutService::class)->remaining($payout->settlement))->toBe(0.0)
        ->and(app(ExternalLabDebt::class)->balance($this->doctor->id))->toBe(0.0)
        ->and((float) PartnerFinanceTransaction::whereNotNull('salary_payout_allocation_id')->sum('amount'))->toBe(700.0);
    expect(($this->pay)(700, true, $key)->id)->toBe($payout->id)->and(ExternalLabDeduction::count())->toBe(1);
});

test('debt above salary carries forward with no cash transaction', function () {
    $this->outsideWork->update(['quantity' => 15]);
    $payout = ($this->pay)(0, true);
    expect((float) $payout->external_deduction_gel)->toBe(1000.0)
        ->and(app(ExternalLabDebt::class)->balance($this->doctor->id))->toBe(500.0)
        ->and($payout->allocations)->toHaveCount(0)
        ->and(PartnerFinanceTransaction::whereNotNull('salary_payout_allocation_id')->count())->toBe(0);
});

test('failed excessive payout rolls back settlement and deduction', function () {
    expect(fn () => ($this->pay)(1000, true))->toThrow(ValidationException::class);
    expect(ExternalLabDeduction::count())->toBe(0)->and(SalarySettlement::count())->toBe(0)
        ->and(app(ExternalLabDebt::class)->balance($this->doctor->id))->toBe(300.0);
});

test('deducted work and billing doctor cannot be changed or deleted', function () {
    ($this->pay)(700, true);
    expect(fn () => $this->outsideWork->update(['quantity' => 1]))->toThrow(ValidationException::class);
    expect(fn () => $this->outsideWork->fresh()->delete())->toThrow(ValidationException::class);
    expect(fn () => $this->external->update(['external_billing_doctor_id' => null]))->toThrow(ValidationException::class);
    expect(fn () => $this->external->fresh()->delete())->toThrow(ValidationException::class);
});

test('unpaid external work can be removed and missing tariff cannot create a work', function () {
    $this->outsideWork->delete();
    expect(app(ExternalLabDebt::class)->balance($this->doctor->id))->toBe(0.0);
    $this->doctor->update(['external_lab_pmma_rate' => null]);
    expect(fn () => $this->external->mainWorks()->create(['material' => 'pmma', 'quantity' => 2]))->toThrow(ValidationException::class);
    expect($this->external->mainWorks()->count())->toBe(0);
});

test('remaining payout can offset pending debt after an initial cash payout', function () {
    $first = ($this->pay)(500, false);
    $next = app(IsraeliSalaryPayoutService::class)->payRemaining($first->salary_settlement_id,
        [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 200]], (string) Str::uuid(), $this->owner, true);
    expect((float) $next->external_deduction_gel)->toBe(300.0)
        ->and(app(IsraeliSalaryPayoutService::class)->remaining($first->settlement))->toBe(0.0);
});

test('salary form previews and applies external deduction with no cash when fully offset', function () {
    $this->outsideWork->update(['quantity' => 15]);
    $page = Livewire::test(DoctorCompensation::class)
        ->call('openDoctorSalary', $this->doctor->id, 'israeli')
        ->assertActionMounted('calculateSalary')
        ->assertMountedActionModalSee('გარე სამუშაოების დაქვითვა')
        ->set('mountedActions.0.data.deduct_external', true)
        ->assertMountedActionModalSee('1,500.00')
        ->callMountedAction()->assertHasNoActionErrors();
    expect((float) SalaryPayout::sole()->external_deduction_gel)->toBe(1000.0)
        ->and(app(ExternalLabDebt::class)->balance($this->doctor->id))->toBe(500.0);
});

test('lab creation form links the selected billing doctor without creating a patient', function () {
    $patientsBefore = Patient::count();
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'external',
        'mainWorks' => [['doctor_search' => ExternalLabCaseData::doctorLabel($this->doctor), 'patient_search' => 'New external patient', 'material' => 'pmma', 'quantity' => 2]],
    ])->callMountedAction()->assertHasNoActionErrors();
    expect(app(ExternalLabDebt::class)->balance($this->doctor->id))->toBe(350.0)
        ->and(Patient::count())->toBe($patientsBefore);
});

test('lab form failure rolls back the entire new case', function () {
    $before = LabCase::count();
    $this->doctor->update(['external_lab_pmma_rate' => null]);
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'external',
        'mainWorks' => [['doctor_search' => ExternalLabCaseData::doctorLabel($this->doctor), 'patient_search' => 'New external patient', 'material' => 'pmma', 'quantity' => 2]],
    ])->callMountedAction()->assertHasFormErrors();
    expect(LabCase::count())->toBe($before);
});

test('external doctor suggestions require profile opt in and ignore forged billing IDs', function () {
    $label = ExternalLabCaseData::doctorLabel($this->doctor);
    expect(ExternalLabCaseData::doctorOptions())->toContain($label);
    $this->doctor->update(['external_lab_enabled' => false]);
    expect(ExternalLabCaseData::doctorOptions())->not->toContain($label);
    $data = ExternalLabCaseData::prepare([
        'external_doctor_name' => $label, 'external_patient_name' => 'Outside',
        'external_billing_doctor_id' => $this->doctor->id,
    ]);
    expect($data['external_billing_doctor_id'])->toBeNull();
});

test('deduction detail is hidden until selected and lab has no second doctor selector', function () {
    Livewire::test(ListLabCases::class)->mountAction('create')
        ->fillForm(['source' => 'external'])->assertFormFieldDoesNotExist('external_billing_doctor_id');
    $page = Livewire::test(DoctorCompensation::class)
        ->call('openDoctorSalary', $this->doctor->id, 'israeli')
        ->assertMountedActionModalDontSee('Outside patient');
    $page->set('mountedActions.0.data.deduct_external', true)->assertMountedActionModalSee('Outside patient');
});


test('external doctor labels omit IDs and legacy names retain their salary link on edit', function () {
    $label = ExternalLabCaseData::doctorLabel($this->doctor);
    expect($label)->toBe($this->doctor->labDisplayName(app()->getLocale()))->not->toContain('#');
    $this->external->update(['external_doctor_name' => $label.' — #'.$this->doctor->id]);
    $this->doctor->update(['external_lab_enabled' => false]);
    $case = $this->external->fresh();
    expect($case->external_doctor_name)->toBe($label)->and($case->doctor_display)->toBe($label);
    $data = ExternalLabCaseData::prepare(ExternalLabCaseData::defaults($case), $case);
    expect($data['external_billing_doctor_id'])->toBe($this->doctor->id)
        ->and($data['external_doctor_name'])->toBe($label);
});
