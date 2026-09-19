<?php

use App\Filament\Resources\LabTechnicians\LabTechnicianResource;
use App\Filament\Resources\LabTechnicians\Pages\EditLabTechnician;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\EmployeeSalaryRate;
use App\Models\LabCase;
use App\Models\LabTechnicianRate;
use App\Models\Patient;
use App\Models\User;
use App\Services\EmployeeSalaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/../Support/EmployeeSalaryFunding.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 1)->startOfDay());
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->tech = Employee::create(['first_name' => 'Any', 'last_name' => 'Technician',
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id,
        'is_active' => true, 'salary_active' => true, 'salary_type' => 'performance', 'salary_main_technician' => true]);
    $this->patient = Patient::create(['first_name' => 'Rate', 'last_name' => 'History']);
});

function rateHistoryWork($test, string $date, string $material = 'zircon', int $quantity = 2)
{
    return LabCase::create(['patient_id' => $test->patient->id, 'source' => 'clinic', 'case_date' => $date])->mainWorks()
        ->create(['material' => $material, 'quantity' => $quantity, 'technician_id' => $test->tech->id]);
}

test('employee technicians use independent dated rates and keep old work at its historical rate', function () {
    expect(LabTechnicianResource::getEloquentQuery()->whereKey($this->tech)->exists())->toBeTrue();
    $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-09-01']);
    $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 30, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-11-01']);
    $old = rateHistoryWork($this, '2026-10-31');
    $new = rateHistoryWork($this, '2026-11-01');
    rateHistoryWork($this, '2026-11-02', 'pmma');
    $rows = app(EmployeeSalaryService::class)->pending($this->tech);
    expect($rows)->toHaveCount(2)->and($rows->firstWhere('lab_main_work_id', $old->id)['amount_gel'])->toBe(50.0)
        ->and($rows->firstWhere('lab_main_work_id', $new->id)['amount_gel'])->toBe(60.0);
    $other = $this->tech->replicate();
    $other->first_name = 'Different';
    $other->save();
    $other->salaryRates()->create(['work_type' => 'zircon', 'amount' => 8, 'basis' => 'per_unit', 'is_active' => true]);
    expect(app(EmployeeSalaryService::class)->pending($other)->sum('amount_gel'))->toBe(32.0);
    $this->tech->update(['first_name' => 'Renamed', 'last_name' => 'Entirely']);
    expect(app(EmployeeSalaryService::class)->pending($this->tech)->sum('amount_gel'))->toBe(110.0);
});

test('inactive dated version stops compensation without falling back or changing earlier work', function () {
    $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_work', 'is_active' => true, 'effective_from' => '2026-09-01']);
    $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_work', 'is_active' => false, 'effective_from' => '2026-11-01']);
    rateHistoryWork($this, '2026-08-31');
    rateHistoryWork($this, '2026-10-31');
    rateHistoryWork($this, '2026-11-01');
    expect(app(EmployeeSalaryService::class)->pending($this->tech)->sum('amount_gel'))->toBe(25.0);
});

test('historical rates are immutable and same-date versions are rejected', function () {
    $rate = $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-09-01']);
    expect(fn () => $rate->update(['amount' => 30]))->toThrow(ValidationException::class);
    rateHistoryWork($this, '2026-09-30');
    expect(fn () => $rate->fresh()->delete())->toThrow(ValidationException::class);
    expect(fn () => $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 30, 'effective_from' => '2026-09-01']))->toThrow(ValidationException::class);
    $future = $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 30, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-11-01']);
    $future->update(['amount' => 35]);
    expect($future->fresh()->amount)->toBe('35.00')->and($rate->fresh()->amount)->toBe('25.00');
});

test('technician profile can save multiple versions of one work type and reject duplicate dates', function () {
    $page = Livewire::test(EditLabTechnician::class, ['record' => $this->tech->id]);
    $rows = [
        ['work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-10-01'],
        ['work_type' => 'zircon', 'amount' => 30, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-11-01'],
    ];
    $page->fillForm(['salaryRates' => $rows])->call('save')->assertHasNoFormErrors();
    expect($this->tech->salaryRates()->count())->toBe(2);
    Livewire::test(EditLabTechnician::class, ['record' => $this->tech->id])->call('save')->assertHasNoFormErrors();
    $fresh = $this->tech->replicate();
    $fresh->save();
    $rows[1]['effective_from'] = $rows[0]['effective_from'];
    Livewire::test(EditLabTechnician::class, ['record' => $fresh->id])->fillForm(['salaryRates' => $rows])->call('save')->assertHasFormErrors();
    expect($fresh->salaryRates()->count())->toBe(0);
});

test('adding a future rate never changes finalized salary snapshots', function () {
    seedTechnicianClinicCash();
    $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-09-01']);
    rateHistoryWork($this, '2026-09-30');
    $service = app(EmployeeSalaryService::class);
    $settled = settleTechnicianWithClinicCash($this->tech, $service->pending($this->tech)->keys()->all());
    $before = $settled->fresh()->toArray();
    $items = $settled->items()->get()->toArray();
    $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 30, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-11-01']);
    expect($settled->fresh()->toArray())->toBe($before)->and($settled->items()->get()->toArray())->toBe($items)
        ->and($service->pending($this->tech))->toBeEmpty();
    expect(fn () => $this->tech->salaryRates()->oldest('effective_from')->first()->delete())->toThrow(ValidationException::class);
    expect($settled->fresh()->toArray())->toBe($before)->and($settled->items()->get()->toArray())->toBe($items);
});

test('an unsaved rate disappears immediately without a confirmation modal', function () {
    $page = Livewire::test(EditLabTechnician::class, ['record' => $this->tech->id])
        ->fillForm(['salaryRates' => ['draft' => ['work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-11-01']]])
        ->mountFormComponentAction('salaryRates', 'delete', arguments: ['item' => 'draft']);
    expect($page->get('data.salaryRates'))->toBeEmpty()->and($page->instance()->getMountedAction())->toBeNull()
        ->and($this->tech->salaryRates()->count())->toBe(0);
});

test('unused persisted rate deletion requires confirmation and preserves other rates', function () {
    $rate = $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-09-01']);
    $other = $this->tech->salaryRates()->create(['work_type' => 'pmma', 'amount' => 5, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-09-01']);
    $page = Livewire::test(EditLabTechnician::class, ['record' => $this->tech->id])
        ->mountFormComponentAction('salaryRates', 'delete', arguments: ['item' => 'record-'.$rate->id]);
    expect($page->instance()->getMountedAction())->not->toBeNull()
        ->and($page->get('data.salaryRates'))->toHaveKey('record-'.$rate->id)->and($rate->fresh())->not->toBeNull();
    $page->callMountedAction();
    expect($page->get('data.salaryRates'))->not->toHaveKey('record-'.$rate->id);
    $page->call('save')->assertHasNoFormErrors();
    expect($rate->fresh())->toBeNull()->and($other->fresh()->amount)->toBe('5.00');
});

test('rate needed for historical work cannot be removed through the row action or forged form state', function () {
    $rate = $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-09-01']);
    rateHistoryWork($this, '2026-09-20');
    $page = Livewire::test(EditLabTechnician::class, ['record' => $this->tech->id])
        ->callFormComponentAction('salaryRates', 'delete', arguments: ['item' => 'record-'.$rate->id])
        ->assertNotified(__('employees.salary.rate_delete_blocked'));
    expect($page->get('data.salaryRates'))->toHaveKey('record-'.$rate->id)->and($rate->fresh())->not->toBeNull();
    Livewire::test(EditLabTechnician::class, ['record' => $this->tech->id])->fillForm(['salaryRates' => []])->call('save')->assertHasErrors();
    expect($rate->fresh())->not->toBeNull();
});

test('deleting an unused version leaves historical rate resolution unchanged and deactivation works', function () {
    $old = $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-09-01']);
    $accidental = $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 35, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-12-01']);
    rateHistoryWork($this, '2026-09-20');
    $accidental->delete();
    $stop = $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 25, 'basis' => 'per_unit', 'is_active' => true, 'effective_from' => '2026-11-01']);
    Livewire::test(EditLabTechnician::class, ['record' => $this->tech->id])->fillForm(['salaryRates.record-'.$stop->id.'.is_active' => false])->call('save')->assertHasNoFormErrors();
    rateHistoryWork($this, '2026-11-02');
    expect($stop->fresh()->is_active)->toBeFalse()->and($old->fresh()->amount)->toBe('25.00')
        ->and(app(EmployeeSalaryService::class)->pending($this->tech)->sum('amount_gel'))->toBe(50.0);
    expect(fn () => $stop->fresh()->delete())->toThrow(ValidationException::class);
});

test('legacy work snapshots also resolve stored rates by work date', function () {
    $user = User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]);
    foreach (['2026-09-01' => 25, '2026-11-01' => 30] as $date => $amount) {
        LabTechnicianRate::create(['technician_id' => $user->id, 'work_type' => 'zirconia', 'component_type' => 'production', 'rate_per_unit' => $amount, 'is_active' => true, 'effective_from' => $date]);
    }
    $case = LabCase::create(['patient_id' => $this->patient->id, 'source' => 'clinic', 'case_date' => '2026-10-01']);
    foreach (['2026-10-31' => '50.00', '2026-11-01' => '60.00'] as $date => $amount) {
        $work = $case->workItems()->create(['technician_id' => $user->id, 'work_type' => 'zirconia', 'component_type' => 'production', 'quantity' => 2, 'work_date' => $date, 'status' => 'completed']);
        expect($work->salary_amount)->toBe($amount);
    }
});

test('backfill retains the exact pre-existing named technicians rates and refuses lossy rollback', function () {
    $migration = require database_path('migrations/2026_09_19_100000_version_technician_compensation_rates.php');
    $migration->down();
    $profiles = ['Alex' => ['zircon' => 25, 'pmma' => 5, 'individual_abutment' => 10, 'milling' => 5],
        'Ilia' => ['zircon_modeling' => 10, 'pmma_modeling' => 5, 'abutment_modeling' => 10, 'titanium_bar_modeling' => 30, 'milling' => 5],
        'Mari' => ['zircon_modeling' => 5, 'pmma_modeling' => 5, 'milling' => 5],
        'Mari I' => ['zircon_modeling' => 3, 'milling' => 5]];
    foreach ($profiles as $name => $rates) {
        $employee = $this->tech->replicate();
        $employee->first_name = $name;
        $employee->save();
        foreach ($rates as $type => $amount) {
            DB::table('employee_salary_rates')->insert(['employee_id' => $employee->id, 'work_type' => $type, 'amount' => $amount, 'basis' => 'per_unit', 'is_active' => true]);
        }
    }
    $before = DB::table('employee_salary_rates')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    $migration->up();
    $after = DB::table('employee_salary_rates')->orderBy('id')->get()->map(function ($row) {
        $row = (array) $row;
        unset($row['effective_from']);

        return $row;
    })->all();
    expect($after)->toBe($before)->and(EmployeeSalaryRate::where('effective_from', '1900-01-01')->count())->toBe(14);
    $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 25, 'effective_from' => '2026-11-01']);
    $this->tech->salaryRates()->create(['work_type' => 'zircon', 'amount' => 30, 'effective_from' => '2026-12-01']);
    expect(fn () => $migration->down())->toThrow(RuntimeException::class);
});
