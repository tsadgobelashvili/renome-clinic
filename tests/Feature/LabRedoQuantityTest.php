<?php

use App\Filament\Pages\FinanceReports;
use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\LabTechnicianRate;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use App\Services\DoctorCompensationCalculator;
use App\Services\EmployeeSalaryService;
use App\Services\IsraeliLabSalaryItems;
use App\Services\IsraeliSalaryPayoutService;
use App\Services\LabPartyAutocomplete;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/EmployeeSalaryFunding.php';
uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-10-12 12:00:00');
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Redo', 'last_name' => 'Doctor', 'is_active' => true, 'specialties' => ['orthopedics'],
        'israeli_lab_zircon_rate' => 70, 'israeli_lab_pmma_rate' => 25, 'israeli_salary_payment_method' => 'bank_transfer']);
    $patient = Patient::create(['first_name' => 'Redo', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $this->case = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'source' => 'israeli', 'case_date' => today()]);
    $this->work = $this->case->mainWorks()->create(['material' => 'zircon', 'quantity' => 28]);
    $this->salary = app(EmployeeSalaryService::class);
});

function redoTechnician(array $roles, array $rates): Employee
{
    $employee = Employee::create(['first_name' => 'Redo', 'last_name' => 'Technician',
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id, 'is_active' => true,
        'salary_active' => true, 'salary_type' => 'performance', ...$roles]);
    foreach ($rates as $type => $rate) {
        $employee->salaryRates()->create(['work_type' => $type, 'amount' => $rate, 'basis' => 'per_unit', 'is_active' => true]);
    }

    return $employee;
}

test('payable quantity uses persisted redo without changing actual lab quantities', function () {
    expect($this->work->fresh()->redo_quantity)->toBe(0)->and($this->work->payableQuantity())->toBe(28);
    $this->work->update(['redo_quantity' => 17]);
    expect($this->work->fresh()->payableQuantity())->toBe(11)->and($this->work->fresh()->quantity)->toBe(28)
        ->and($this->case->fresh()->quantity)->toBe(28)->and($this->case->mainWorks()->sum('quantity'))->toBe(28);
    $page = Livewire::test(FinanceReports::class)->set('source', 'partner');
    $statistics = (new ReflectionMethod(FinanceReports::class, 'israeliOrthopedicStatistics'))->invoke($page->instance(), today()->startOfMonth(), today());
    expect($statistics['quantity'])->toBe(28);
});

test('redo rejects negative fractional and excess values', function ($redo) {
    expect(fn () => $this->work->update(['redo_quantity' => $redo]))->toThrow(ValidationException::class);
    expect($this->work->fresh()->redo_quantity)->toBe(0);
})->with([-1, 29, 1.5]);

test('Owner form shows redo and reacts to quantity changes', function () {
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id]);
    $key = array_key_first($page->get('data.mainWorks'));
    $page->assertFormFieldExists('mainWorks.'.$key.'.redo_quantity')
        ->set('data.mainWorks.'.$key.'.redo_quantity', 17)
        ->assertSee('11')->set('data.mainWorks.'.$key.'.quantity', 30)->assertSee('13')
        ->set('data.mainWorks.'.$key.'.quantity', 28)
        ->call('save')->assertHasNoFormErrors();
    expect($this->work->fresh()->redo_quantity)->toBe(17)->and($this->work->fresh()->quantity)->toBe(28);
});

test('non Owners cannot change redo at the model boundary and serialization hides it', function (string $role) {
    $this->work->update(['redo_quantity' => 17]);
    $this->actingAs(User::factory()->create(['role' => $role]));
    expect($this->work->fresh()->toArray())->not->toHaveKey('redo_quantity');
    expect(fn () => $this->work->fresh()->update(['redo_quantity' => 0]))->toThrow(AuthorizationException::class);
    expect($this->work->fresh()->redo_quantity)->toBe(17);
})->with([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('technician edit form omits redo and rejects a crafted redo request', function () {
    $this->work->update(['redo_quantity' => 17]);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id]);
    $key = array_key_first($page->get('data.mainWorks'));
    $page->assertFormFieldDoesNotExist('mainWorks.'.$key.'.redo_quantity')->assertFormFieldDoesNotExist('mainWorks.'.$key.'.payable_quantity')
        ->assertDontSee(__('lab.redo_quantity'))->assertDontSee(__('lab.payable_quantity'));
    $page->set('data.mainWorks.'.$key.'.redo_quantity', 0)->call('save');
    expect($this->work->fresh()->redo_quantity)->toBe(17)->and($this->work->fresh()->quantity)->toBe(28);
});

test('doctor compensation and compact salary overview use payable units and zero redo keeps defaults', function () {
    expect(app(IsraeliLabSalaryItems::class)->amount($this->work, $this->doctor))->toBe(1960.0);
    $this->work->update(['redo_quantity' => 17]);
    $report = app(DoctorCompensationCalculator::class)->calculate($this->doctor->id, '2026-10-01', '2026-10-12', patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG, israeliLabOnly: true);
    $item = $report['details'][0]['items'][0];
    expect($item['quantity'])->toBe(11)->and($item['doctor_share'])->toBe(770.0)
        ->and(app(IsraeliLabSalaryItems::class)->eligibleForDoctors(collect([$this->doctor]), compact: true)->sole()->payableQuantity())->toBe(11);
    $this->work->update(['redo_quantity' => 28]);
    expect(app(IsraeliLabSalaryItems::class)->eligible($this->doctor))->toBeEmpty();
});

test('production and modeling use payable main work units while independent milling stays unchanged', function () {
    $tech = redoTechnician(['salary_main_technician' => true, 'salary_modeler' => true, 'salary_milling_eligible' => true], ['zircon' => 2, 'zircon_modeling' => 3, 'milling' => 4]);
    $this->work->update(['technician_id' => $tech->id, 'redo_quantity' => 17]);
    $this->case->additionalWorks()->create(['work_type' => 'milling', 'quantity' => 28, 'technician_id' => $tech->id]);
    $rows = $this->salary->pending($tech)->keyBy('work_type');
    expect($rows['zircon']['quantity'])->toBe(11)->and($rows['zircon']['amount_gel'])->toBe(22.0)
        ->and($rows['zircon_modeling']['quantity'])->toBe(11)->and($rows['zircon_modeling']['amount_gel'])->toBe(33.0)
        ->and($rows['milling']['quantity'])->toBe(28)->and($rows['milling']['amount_gel'])->toBe(112.0)
        ->and($this->salary->pendingTotals(collect([$tech]))[$tech->id])->toBe(167.0);
});

test('paid production remains frozen while unpaid modeling uses an Owner redo correction', function () {
    seedTechnicianClinicCash();
    $tech = redoTechnician(['salary_main_technician' => true, 'salary_modeler' => true], ['zircon' => 2, 'zircon_modeling' => 3]);
    $this->work->update(['technician_id' => $tech->id, 'redo_quantity' => 17]);
    $key = 'main-'.$this->work->id.'-employee-'.$tech->id.'-zircon';
    $settlement = settleTechnicianWithClinicCash($tech, [$key]);
    $items = $settlement->items()->get()->toArray();
    $finance = DB::table('finance_transactions')->get()->toArray();
    $this->work->update(['redo_quantity' => 21]);
    $pending = $this->salary->pending($tech)->sole();
    expect($pending['work_type'])->toBe('zircon_modeling')->and($pending['quantity'])->toBe(7)->and($pending['amount_gel'])->toBe(21.0)
        ->and($settlement->items()->get()->toArray())->toEqual($items)->and(DB::table('finance_transactions')->get()->toArray())->toEqual($finance);
    expect(fn () => settleTechnicianWithClinicCash($tech, [$key]))->toThrow(ValidationException::class);
    settleTechnicianWithClinicCash($tech, $this->salary->pending($tech)->keys()->all());
    $financeAfter = DB::table('finance_transactions')->get()->toArray();
    $this->work->update(['redo_quantity' => 0]);
    expect($this->salary->pending($tech))->toBeEmpty()->and(DB::table('finance_transactions')->get()->toArray())->toEqual($financeAfter);
});

test('doctor partial payout snapshots and remaining payment stay frozen after redo correction', function () {
    $this->work->update(['redo_quantity' => 17]);
    $service = app(IsraeliSalaryPayoutService::class);
    $payout = $service->finalizeAndPay($this->doctor->id, '2026-10-01', '2026-10-12', [$this->work->id],
        [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 200]], (string) Str::uuid(), $this->owner);
    $settlement = $payout->settlement;
    $items = $settlement->items()->get()->toArray();
    $snapshot = $payout->composition_snapshot;
    $finance = DB::table('partner_finance_transactions')->get()->toArray();
    $this->work->update(['redo_quantity' => 20]);
    expect(app(IsraeliLabSalaryItems::class)->eligible($this->doctor))->toBeEmpty()
        ->and($settlement->items()->get()->toArray())->toEqual($items)->and($payout->fresh()->composition_snapshot)->toEqual($snapshot)
        ->and(DB::table('partner_finance_transactions')->get()->toArray())->toEqual($finance)->and($service->remaining($settlement))->toBe(570.0);
    $next = $service->payRemaining($settlement->id, [['source' => 'israeli', 'currency' => 'GEL', 'amount' => 570]], (string) Str::uuid(), $this->owner);
    expect((float) $next->total_gel)->toBe(570.0)->and($next->composition_snapshot['items'][0]['quantity'])->toBe(11)
        ->and($service->remaining($settlement))->toBe(0.0)->and($this->work->fresh()->quantity)->toBe(28);
});

test('PMMA carry and fully paid credits use new payable Zircon units and immutable quantities', function (float $paid, float $reduction, float $carry) {
    seedTechnicianClinicCash();
    $tech = redoTechnician(['salary_modeler' => true], ['pmma_modeling' => 5, 'zircon_modeling' => 10]);
    $pmmaCase = LabCase::create(['source' => 'clinic', 'patient_id' => Patient::create(['first_name' => 'Credit', 'last_name' => 'Patient'])->id, 'case_date' => today()]);
    $pmma = $pmmaCase->mainWorks()->create(['material' => 'pmma', 'quantity' => 28, 'technician_id' => $tech->id]);
    $old = $this->salary->settle($tech, $this->salary->pending($tech)->keys()->all(), allocation: ['clinic_cash_gel' => $paid, 'israeli_cash_gel' => 0]);
    $items = $old->items()->get()->toArray();
    $pmmaCase->mainWorks()->create(['material' => 'zircon', 'quantity' => 28, 'redo_quantity' => 17, 'technician_id' => $tech->id]);
    $row = $this->salary->pending($tech)->sole();
    expect($row['quantity'])->toBe(11)->and($row['amount_gel'])->toBe(0.0)->and((float) $row['carry_reduction_gel'])->toBe($reduction)
        ->and((float) $row['overpayment_gel'])->toBe(30.0 - $reduction)
        ->and($this->salary->openingCarry($tech))->toBe($carry)->and($old->items()->get()->toArray())->toEqual($items)
        ->and($pmma->quantity)->toBe(28);
})->with([[50.0, 30.0, 60.0], [140.0, 0.0, 0.0]]);

test('non Owners cannot create nonzero redo and quantity edits must retain a valid payable quantity', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    expect(fn () => $this->case->mainWorks()->create(['material' => 'zircon', 'quantity' => 28, 'redo_quantity' => 17]))->toThrow(AuthorizationException::class);
    $this->actingAs($this->owner);
    $this->work->update(['redo_quantity' => 17]);
    expect(fn () => $this->work->fresh()->update(['quantity' => 16]))->toThrow(ValidationException::class);
    expect($this->work->fresh()->quantity)->toBe(28);
});

test('legacy case-level lab salary rows block ambiguous redo changes', function () {
    LabTechnicianRate::create(['technician_id' => $this->owner->id, 'work_type' => 'zirconia', 'component_type' => 'production',
        'rate_per_unit' => 2, 'effective_from' => today(), 'is_active' => true]);
    $legacy = $this->case->workItems()->create(['work_type' => 'zirconia', 'component_type' => 'production', 'quantity' => 28,
        'technician_id' => $this->owner->id, 'work_date' => today(), 'status' => 'completed']);
    $before = $legacy->fresh()->getAttributes();
    expect(fn () => $this->work->update(['redo_quantity' => 17]))->toThrow(ValidationException::class, __('lab.redo_legacy_locked'));
    expect($this->work->fresh()->redo_quantity)->toBe(0)->and($legacy->fresh()->getAttributes())->toEqual($before);
});

test('Owner creates a lab case with persisted redo and Admin cannot open the control', function () {
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'israeli', 'mainWorks' => [['doctor_search' => app(LabPartyAutocomplete::class)->practitionerLabel($this->doctor),
            'patient_search' => $this->case->patient->lab_selection_label, 'material' => 'zircon', 'quantity' => 28, 'redo_quantity' => 17]],
    ])->callMountedAction()->assertHasNoActionErrors();
    $created = LabCase::latest('id')->first()->mainWorks()->sole();
    expect($created->redo_quantity)->toBe(17)->and($created->quantity)->toBe(28)->and($created->payableQuantity())->toBe(11);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $this->case->update(['created_by' => auth()->id()]);
    Livewire::test(EditLabCase::class, ['record' => $this->case->id])->assertForbidden();
});
