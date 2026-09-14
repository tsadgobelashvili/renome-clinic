<?php

use App\Filament\Pages\DoctorCompensation;
use App\Models\ClinicPayrollCycle;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\PayrollEntry;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\User;
use App\Models\Visit;
use App\Services\ClinicPayrollCycleService;
use App\Services\DoctorCompensationCalculator;
use App\Services\EmployeePayrollService;
use App\Services\SalarySettlementService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-14 12:00:00'));
    $this->owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->actingAs($this->owner);
    $this->doctor = Doctor::create(['first_name' => 'Clinic', 'last_name' => 'Doctor', 'compensation_percentage' => 40, 'is_active' => true]);
    $this->visit = clinicCycleVisit($this->doctor);
    $this->employee = Employee::create(['first_name' => 'Clinic', 'last_name' => 'Employee', 'is_active' => true,
        'position_id' => EmployeePosition::where('name', 'Administrator')->sole()->id, 'salary_payout_day' => 16]);
    $this->setting = $this->employee->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'fixed_net',
        'currency' => 'GEL', 'net_amount' => 1000, 'default_payment_method' => 'bank_transfer', 'effective_from' => '2026-09-01', 'is_active' => true]);
    $this->service = app(ClinicPayrollCycleService::class);
});

function clinicCycleVisit(Doctor $doctor, float $amount = 1000, string $source = 'clinic'): Visit
{
    $patient = Patient::create(['first_name' => 'Cycle', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::where('slug', $source)->sole()->id]);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => $amount]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Cycle work', 'quantity' => 1, 'unit_price' => $amount]);
    $visit->payments()->create(['amount' => $amount, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);

    return $visit;
}

test('shared card is above both tabs and includes doctor salary plus employee required amount', function () {
    $doctor = app(DoctorCompensationCalculator::class)->calculate($this->doctor->id, '2026-09-01', '2026-09-14', patientGroup: 'clinic');
    expect($doctor['totals']['GEL']['doctor_share'])->toBe(400.0);
    $preview = $this->service->preview();
    expect($preview['doctor_totals']['GEL'])->toEqual(400)->and($preview['employee_totals']['GEL'])->toEqual(1301.02)
        ->and($preview['totals']['GEL'])->toEqual(1701.02)->and($preview['payroll_date'])->toBe('2026-09-16');
    expect($this->service->overview()['totals'])->toEqual($preview['totals']);
    Livewire::test(DoctorCompensation::class)->assertSeeHtml('data-clinic-payroll-date="2026-09-16"')->assertSee('1,701.02')
        ->assertViewHas('clinicPayroll', fn ($data) => $data['totals']['GEL'] === 1701.02)
        ->set('staffTypeFilter', 'employees')->assertSeeHtml('data-clinic-payroll-date="2026-09-16"')->assertSee('1,701.02')
        ->assertViewHas('clinicPayroll', fn ($data) => $data['totals']['GEL'] === 1701.02);
});

test('one reviewed action finalizes the complete Clinic cycle without recording payment', function () {
    $counts = collect(['payments', 'finance_transactions', 'partner_finance_transactions'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()]);
    Livewire::test(DoctorCompensation::class)->mountAction('clinicPayroll')
        ->assertMountedActionModalSee([$this->doctor->full_name, $this->employee->full_name, '1,701.02', '1,301.02', '1,000.00'])
        ->callMountedAction()->assertHasNoActionErrors()->assertSeeHtml('data-clinic-payroll-date="2026-10-01"');
    $cycle = ClinicPayrollCycle::sole();
    expect($cycle->status)->toBe('finalized')->and($cycle->payment_status)->toBe('pending')
        ->and($cycle->doctorSettlements()->count())->toBe(1)->and($cycle->employeeEntries()->count())->toBe(1)
        ->and($cycle->snapshot['totals']['GEL'])->toEqual(1701.02);
    $entry = $cycle->employeeEntries()->sole();
    expect($entry->net_amount)->toBe('1000.00')->and($entry->calculation_details['required_amount'])->toEqual(1301.02)
        ->and($entry->calculation_details['payout_date'])->toBe('2026-09-16')->and($entry->payment_method)->toBe('bank_transfer')
        ->and($entry->payout_status)->toBe('pending')->and($cycle->doctorSettlements()->sole()->payment_amount)->toBeNull();
    foreach ($counts as $table => $count) {
        expect(DB::table($table)->count())->toBe($count);
    }
    Livewire::test(DoctorCompensation::class)->set('staffTypeFilter', 'employees')->assertSee('16.10.2026')
        ->mountAction('clinicPayroll', ['cycle' => $cycle->id])->assertMountedActionModalSee(['1,701.02', __('clinic-payroll.finalized')]);
});

test('fixed items stay excluded and new same-day work moves to the next cycle', function () {
    $review = $this->service->preview();
    $cycle = $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    $snapshot = $cycle->snapshot;
    expect($this->service->preview()['doctors'])->toBe([])->and($this->service->preview()['employees'])->toBe([]);
    $this->setting->update(['net_amount' => 1500]);
    $this->visit->treatmentCaseItems()->first()->update(['unit_price' => 2000]);
    $newVisit = clinicCycleVisit($this->doctor, 500);
    $next = $this->service->preview();
    expect($next['payroll_date'])->toBe('2026-10-01')->and($next['doctor_totals']['GEL'])->toEqual(200)
        ->and(collect($next['doctors'][0]['report']['details'])->pluck('visit_id')->all())->toBe([$newVisit->id])
        ->and($cycle->fresh()->snapshot)->toBe($snapshot);
    expect(fn () => $cycle->update(['snapshot' => []]))->toThrow(ValidationException::class);
    expect(fn () => app(SalarySettlementService::class)->undo($cycle->doctorSettlements()->sole()->id))->toThrow(ValidationException::class);
    $this->service->finalize($next['payroll_date'], $next['fingerprint'], $this->owner);
    expect($this->service->nextDate()->toDateString())->toBe('2026-10-16');
    $employee = $this->service->preview()['employees'][0];
    expect($employee['payday'])->toBe('2026-10-16')->and($employee['required_amount'])->toEqual(1951.53);
});

test('duplicate and stale approvals cannot partially finalize payroll', function () {
    $review = $this->service->preview();
    $this->setting->update(['net_amount' => 1300]);
    expect(fn () => $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner))->toThrow(ValidationException::class);
    expect(ClinicPayrollCycle::count())->toBe(0)->and(SalarySettlement::count())->toBe(0)->and(PayrollEntry::count())->toBe(0);
    $review = $this->service->preview();
    $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    expect(fn () => $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner))->toThrow(ValidationException::class);
    expect(ClinicPayrollCycle::count())->toBe(1)->and(SalarySettlement::count())->toBe(1)->and(PayrollEntry::count())->toBe(1);
});

test('Israeli payroll future salaries missing payday and technicians are excluded', function () {
    clinicCycleVisit($this->doctor, 3000, PatientGroup::ISRAEL_PARTNER_SLUG);
    $this->employee->payrollSettings()->create(['source' => 'israeli', 'salary_model' => 'fixed_net', 'currency' => 'USD',
        'net_amount' => 2000, 'default_payment_method' => 'cash', 'effective_from' => '2026-09-01', 'is_active' => true]);
    foreach (['future', 'missing', 'technician'] as $kind) {
        $employee = $this->employee->replicate();
        $employee->salary_payout_day = $kind === 'missing' ? null : 1;
        if ($kind === 'technician') {
            $employee->position_id = EmployeePosition::where('is_technician', true)->firstOrFail()->id;
        }
        $employee->save();
        $setting = $this->setting->replicate();
        $setting->employee_id = $employee->id;
        $setting->effective_from = $kind === 'future' ? '2026-10-01' : '2026-09-01';
        $setting->save();
    }
    $israeliBefore = app(DoctorCompensationCalculator::class)->calculate($this->doctor->id, '2026-09-01', '2026-09-14', patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG);
    $review = $this->service->preview();
    expect($review['totals'])->toBe(['GEL' => 1701.02])->and($review['employees'])->toHaveCount(1);
    $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    expect(app(DoctorCompensationCalculator::class)->calculate($this->doctor->id, '2026-09-01', '2026-09-14', patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG))->toBe($israeliBefore);
    expect(PayrollEntry::where('source', 'israeli')->count())->toBe(0)->and(SalarySettlement::where('patient_group_slug', PatientGroup::ISRAEL_PARTNER_SLUG)->count())->toBe(0);
});

test('owner counterpart shares are reviewed and fixed in the same total', function () {
    $this->doctor->update(['owner_split_key' => 'levan', 'compensation_percentage' => 50]);
    $this->visit->update(['owner_split_override' => 'on']);
    $other = Doctor::create(['first_name' => 'Other', 'last_name' => 'Owner', 'owner_split_key' => 'nodar', 'compensation_percentage' => 50, 'is_active' => true]);
    $review = $this->service->preview();
    expect($review['doctors'])->toHaveCount(2)->and($review['doctor_totals']['GEL'])->toEqual(1000);
    expect($this->service->overview()['totals'])->toEqual($review['totals']);
    $cycle = $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    expect($cycle->doctorSettlements()->sum('salary_total'))->toEqual(1000)
        ->and($cycle->doctorSettlements()->where('doctor_id', $other->id)->count())->toBe(1)
        ->and($this->service->preview()['doctors'])->toBe([]);
});

test('administrator can review but cannot finalize Clinic payroll', function () {
    $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    $this->actingAs($admin);
    Livewire::test(DoctorCompensation::class)->mountAction('clinicPayroll')->assertMountedActionModalSee('1,701.02');
    $review = $this->service->preview();
    expect(fn () => $this->service->finalize($review['payroll_date'], $review['fingerprint'], $admin))->toThrow(HttpException::class);
    expect(ClinicPayrollCycle::count())->toBe(0);
});

test('new same-visit items remain eligible and review changes require fresh approval', function () {
    $review = $this->service->preview();
    $this->visit->treatmentCaseItems()->first()->update(['unit_price' => 1500]);
    expect(fn () => $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner))->toThrow(ValidationException::class);
    $review = $this->service->preview();
    $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    $item = $this->visit->treatmentCaseItems()->create(['custom_service_name' => 'Later work', 'quantity' => 1, 'unit_price' => 500]);
    $next = $this->service->preview();
    expect($next['payroll_date'])->toBe('2026-10-01')
        ->and(collect($next['doctors'][0]['report']['details'])->flatMap(fn ($row) => $row['items'])->pluck('id')->all())->toBe([$item->id]);
});

test('an employee failure rolls back doctor fixing and the entire cycle', function () {
    $review = $this->service->preview();
    $this->mock(EmployeePayrollService::class, function ($mock) {
        $mock->makePartial()->shouldReceive('finalize')->once()->andThrow(ValidationException::withMessages(['employee' => 'Unavailable']));
    });
    $service = app(ClinicPayrollCycleService::class);
    expect(fn () => $service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner))->toThrow(ValidationException::class);
    expect(ClinicPayrollCycle::count())->toBe(0)->and(SalarySettlement::count())->toBe(0)
        ->and(SalarySettlementItem::count())->toBe(0)->and(PayrollEntry::count())->toBe(0);
});

test('a variable salary approved early advances from its snapshotted payday', function () {
    $this->employee->update(['salary_payout_day' => 1]);
    $this->setting->update(['salary_model' => 'per_unit', 'per_unit_amount' => 100]);
    $first = $this->service->preview();
    $this->service->finalize($first['payroll_date'], $first['fingerprint'], $this->owner);
    // September 1 had no work: October 1 previews work performed since the first setting.
    $review = $this->service->preview();
    expect($review['payroll_date'])->toBe('2026-10-01');
    expect($review['employees'][0]['net_amount'])->toEqual(100)->and($review['employees'][0]['payday'])->toBe('2026-10-01');
    $cycle = $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    expect($cycle->employeeEntries()->sole()->period_end->toDateString())->toBe('2026-09-14');
    expect($this->service->preview()['employees'])->toBe([]);
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
    expect(app(EmployeePayrollService::class)->payableSummaries(collect([$this->employee->fresh()->load('payrollSettings')])))->toBe([]);
});

test('mixed currencies leave ordinary zero-payable work unsettled', function () {
    $visit = Visit::create(['patient_id' => $this->visit->patient_id, 'doctor_id' => $this->doctor->id,
        'visit_date' => today(), 'currency' => 'USD', 'total_price' => 100]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Unpaid work', 'quantity' => 1, 'unit_price' => 100]);
    $review = $this->service->preview();
    expect($review['doctor_totals']['USD'])->toEqual(0)->and($this->service->overview()['totals'])->toEqual($review['totals']);
    $cycle = $this->service->finalize($review['payroll_date'], $review['fingerprint'], $this->owner);
    expect($cycle->doctorSettlements()->count())->toBe(1)->and($cycle->snapshot['totals']['GEL'])->toEqual(1701.02);
});
