<?php

use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\ExternalLabCharge;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\SalaryPayout;
use App\Models\SalarySettlement;
use App\Models\User;
use App\Services\EmployeeSalaryService;
use App\Services\ExternalLabDebt;
use App\Services\IsraeliLabSalaryItems;
use App\Services\LabPartyAutocomplete;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/EmployeeSalaryFunding.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs($this->owner = User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Billing', 'last_name' => 'Doctor', 'is_active' => true,
        'external_lab_enabled' => true, 'external_lab_zircon_rate' => 100, 'external_lab_pmma_rate' => 25]);
    $this->patient = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Patient']);
    $this->case = LabCase::create(['source' => 'external', 'case_date' => today(),
        'external_doctor_name' => $this->doctor->full_name, 'external_patient_name' => 'Historical Outside Patient',
        'external_billing_doctor_id' => $this->doctor->id, 'created_by' => $this->owner->id]);
    $this->work = $this->case->mainWorks()->create(['material' => 'zircon', 'quantity' => 2]);
    $this->secondWork = $this->case->mainWorks()->create(['material' => 'pmma', 'quantity' => 3]);
    $this->additional = $this->case->additionalWorks()->create(['work_type' => 'milling', 'quantity' => 2]);
});

test('Clinic edit removes only unused external charges and preserves work IDs with an audit', function () {
    Log::spy();
    $other = LabCase::create(['source' => 'external', 'case_date' => today(), 'external_billing_doctor_id' => $this->doctor->id]);
    $otherWork = $other->mainWorks()->create(['material' => 'zircon', 'quantity' => 1]);
    $ids = ExternalLabCharge::whereIn('lab_main_work_id', [$this->work->id, $this->secondWork->id])->pluck('id')->all();
    $patientCount = Patient::count();
    $page = Livewire::test(EditLabCase::class, ['record' => $this->case->id])->set('data.source', 'clinic');
    $key = array_key_first($page->get('data.mainWorks'));
    $field = 'mainWorks.'.$key.'.patient_search';
    $label = app(LabPartyAutocomplete::class)->patientSuggestions('Patient', 'clinic')[0];
    $page->set('data.'.$field, 'Patient')
        ->assertFormFieldExists($field, fn ($component) => in_array($label, $component->getDatalistOptions(), true))
        ->set('data.'.$field, $label)->call('save')->assertHasNoFormErrors();
    expect($this->case->fresh()->source)->toBe('clinic')->and($this->case->fresh()->patient_id)->toBe($this->patient->id)
        ->and($this->case->fresh()->external_billing_doctor_id)->toBeNull()->and(Patient::count())->toBe($patientCount)
        ->and($this->case->mainWorks()->pluck('id')->all())->toBe([$this->work->id, $this->secondWork->id])
        ->and($this->case->additionalWorks()->pluck('id')->all())->toBe([$this->additional->id])
        ->and(ExternalLabCharge::whereIn('id', $ids)->count())->toBe(0)
        ->and(ExternalLabCharge::where('lab_main_work_id', $otherWork->id)->count())->toBe(1)
        ->and(app(ExternalLabDebt::class)->balance($this->doctor->id))->toBe(100.0);
});

test('correction schedules removed charge snapshots for logging only after commit', function () {
    Log::spy();
    $afterCommit = null;
    $database = DB::getFacadeRoot();
    DB::swap(Mockery::mock($database));
    DB::shouldReceive('afterCommit')->once()->andReturnUsing(function ($callback) use (&$afterCommit): void {
        $afterCommit = $callback;
    });
    $ids = ExternalLabCharge::orderBy('id')->pluck('id')->all();
    $this->case->update(['source' => 'clinic', 'patient_id' => $this->patient->id]);
    Log::shouldNotHaveReceived('info');
    DB::swap($database);
    expect($afterCommit)->toBeInstanceOf(Closure::class);
    $afterCommit();
    Log::shouldHaveReceived('info')->once()->with('External laboratory case corrected to Clinic',
        Mockery::on(fn ($audit) => $audit['case_id'] === $this->case->id && $audit['previous_source'] === 'external'
            && $audit['previous_external_billing_doctor_id'] === $this->doctor->id && $audit['new_source'] === 'clinic'
            && $audit['new_patient_id'] === $this->patient->id && $audit['cancelled_external_charge_ids'] === $ids
            && $audit['user_id'] === $this->owner->id && filled($audit['corrected_at'])));
});

test('Alexs paid technician salary and snapshots stay intact and the works cannot be paid again', function () {
    seedTechnicianClinicCash();
    $alex = Employee::create(['first_name' => 'Alex', 'last_name' => 'Melukhin',
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id, 'is_active' => true,
        'salary_type' => 'performance', 'salary_active' => true, 'salary_main_technician' => true]);
    foreach (['zircon', 'pmma'] as $type) {
        $alex->salaryRates()->create(['work_type' => $type, 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true]);
    }
    $salary = app(EmployeeSalaryService::class);
    $payout = settleTechnicianWithClinicCash($alex, $salary->pending($alex)->keys()->all());
    $before = [];
    foreach (['employee_salary_settlements', 'employee_salary_settlement_items', 'finance_transactions', 'partner_finance_transactions', 'cashbox_transactions'] as $table) {
        $before[$table] = DB::table($table)->orderBy('id')->get()->toArray();
    }
    expect($payout->items()->pluck('patient_name')->all())->toBe(['Historical Outside Patient', 'Historical Outside Patient']);
    $this->case->update(['source' => 'clinic', 'patient_id' => $this->patient->id]);
    foreach ($before as $table => $records) {
        expect(DB::table($table)->orderBy('id')->get()->toArray())->toEqual($records);
    }
    expect($salary->pending($alex))->toBeEmpty()
        ->and(app(IsraeliLabSalaryItems::class)->eligible($this->doctor))->toBeEmpty()
        ->and($this->case->mainWorks()->pluck('id')->all())->toBe([$this->work->id, $this->secondWork->id])
        ->and($this->case->additionalWorks()->pluck('id')->all())->toBe([$this->additional->id]);
    expect(fn () => settleTechnicianWithClinicCash($alex, ['main-'.$this->work->id.'-employee-'.$alex->id.'-zircon']))
        ->toThrow(ValidationException::class);
});

test('any deduction blocks correction including zero partial and full deductions', function (float $amount) {
    $settlement = SalarySettlement::create(['doctor_id' => $this->doctor->id, 'period_start' => today(), 'period_end' => today(),
        'settled_at' => now(), 'currency' => 'GEL', 'performed_total' => 0, 'paid_amount' => 0, 'outstanding_amount' => 0,
        'direct_expense_total' => 0, 'base_total' => 200, 'percentage' => 0, 'salary_total' => 200,
        'status' => 'confirmed', 'patient_group_slug' => PatientGroup::ISRAEL_PARTNER_SLUG, 'uses_allocations' => true]);
    $payout = SalaryPayout::create(['salary_settlement_id' => $settlement->id, 'total_gel' => 200,
        'external_deduction_gel' => $amount, 'request_key' => (string) Str::uuid(), 'request_hash' => 'test']);
    // Deduct the SECOND charge: checking only the first work is insufficient.
    $charge = $this->secondWork->externalCharge;
    $charge->deductions()->create(['salary_payout_id' => $payout->id, 'amount' => $amount]);
    $before = DB::table('external_lab_charges')->orderBy('id')->get()->toArray();
    expect(fn () => $this->case->update(['source' => 'clinic', 'patient_id' => $this->patient->id]))->toThrow(ValidationException::class);
    expect($this->case->fresh()->source)->toBe('external')
        ->and(DB::table('external_lab_charges')->orderBy('id')->get()->toArray())->toEqual($before)
        ->and($charge->deductions()->count())->toBe(1);
})->with([0.0, 25.0, 75.0]);

test('other source and billing doctor changes retain the original charge guard', function (array $changes) {
    expect(fn () => $this->case->update([...$changes, 'patient_id' => $this->patient->id]))->toThrow(ValidationException::class);
    expect($this->case->fresh()->source)->toBe('external')->and(ExternalLabCharge::count())->toBe(2);
})->with([[['source' => 'israeli']], [['external_billing_doctor_id' => null]]]);

test('failed Clinic validation rolls back cancelled charges and does not log a correction', function () {
    Log::spy();
    $this->case->fill(['source' => 'clinic', 'patient_id' => $this->patient->id,
        'doctor_id' => $this->doctor->id, 'assistant_employee_id' => 999]);
    expect(fn () => $this->case->save())->toThrow(ValidationException::class);
    expect($this->case->fresh()->source)->toBe('external')->and(ExternalLabCharge::count())->toBe(2);
    Log::shouldNotHaveReceived('info');
});

test('Israeli patient and stale case state cannot be used for a Clinic correction', function () {
    $israeli = Patient::create(['first_name' => 'Israel', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    expect(fn () => $this->case->update(['source' => 'clinic', 'patient_id' => $israeli->id]))->toThrow(ValidationException::class);
    $stale = $this->case->fresh();
    $this->case->fresh()->update(['source' => 'clinic', 'patient_id' => $this->patient->id]);
    expect(fn () => $stale->update(['source' => 'clinic', 'patient_id' => $this->patient->id]))->toThrow(ValidationException::class);
});

test('historical doctor work membership blocks correction even without external deductions', function () {
    $settlement = SalarySettlement::create(['doctor_id' => $this->doctor->id, 'period_start' => today(), 'period_end' => today(),
        'settled_at' => now(), 'currency' => 'GEL', 'performed_total' => 0, 'paid_amount' => 0, 'outstanding_amount' => 0,
        'direct_expense_total' => 0, 'base_total' => 200, 'percentage' => 0, 'salary_total' => 200,
        'status' => 'confirmed', 'patient_group_slug' => PatientGroup::ISRAEL_PARTNER_SLUG]);
    $settlement->items()->create(['lab_main_work_id' => $this->work->id, 'revenue' => 200, 'direct_expense' => 0,
        'salary_base' => 200, 'doctor_share' => 200]);
    expect(fn () => $this->case->update(['source' => 'clinic', 'patient_id' => $this->patient->id]))->toThrow(ValidationException::class);
    expect($this->case->fresh()->source)->toBe('external')->and(ExternalLabCharge::count())->toBe(2)
        ->and($settlement->items()->count())->toBe(1);
});
