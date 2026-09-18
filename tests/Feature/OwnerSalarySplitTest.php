<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Models\ClinicPayrollCycle;
use App\Models\Doctor;
use App\Models\OwnerSalaryShare;
use App\Models\Patient;
use App\Models\SalarySettlement;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use App\Services\DoctorSalaryHistory;
use App\Services\SalarySettlementService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function ownerSplitDoctors(): array
{
    return [
        Doctor::create(['first_name' => 'ლევან', 'last_name' => 'ბერიკაშვილი', 'owner_split_enabled' => true, 'compensation_percentage' => 30, 'is_active' => true]),
        Doctor::create(['first_name' => 'ნოდარ', 'last_name' => 'ელიშაკოვი', 'owner_split_enabled' => true, 'compensation_percentage' => 30, 'is_active' => true]),
    ];
}

function ownerSplitVisit(Doctor $doctor, array $lines, float $paid): Visit
{
    $patient = Patient::create(['first_name' => 'Owner', 'last_name' => uniqid()]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'currency' => 'GEL',
        'total_price' => collect($lines)->sum('price'),
    ]);
    foreach ($lines as $line) {
        $service = TreatmentCase::create([
            'name' => $line['name'].' '.uniqid(),
            'category' => $line['category'] ?? 'surgery',
            'triggers_owner_split' => $line['trigger'] ?? false,
            'is_active' => true,
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->getKey(),
            'quantity' => 1,
            'unit_price' => $line['price'],
        ]);
    }
    if ($paid > 0) {
        $visit->payments()->create([
            'amount' => $paid,
            'currency' => 'GEL',
            'payment_date' => today(),
            'payment_method' => 'cash',
        ]);
    }

    return $visit;
}

test('owner trigger applies fifty fifty to the whole eligible visit while normal work keeps percentage', function () {
    [$levan] = ownerSplitDoctors();
    $splitVisit = ownerSplitVisit($levan, [
        ['name' => 'Implantation', 'price' => 3000, 'trigger' => true],
        ['name' => 'Crown', 'price' => 2000],
    ], 5000);
    $splitVisit->treatmentCaseItems->first()->directExpenses()->create(['name' => 'Materials', 'amount' => 600, 'currency' => 'GEL']);
    ownerSplitVisit($levan, [['name' => 'Extraction', 'price' => 1000]], 1000);

    $report = app(DoctorCompensationCalculator::class)->calculate(
        $levan->getKey(), today()->toDateString(), today()->toDateString(), 30,
    );
    $split = collect($report['details'])->firstWhere('visit_id', $splitVisit->getKey());
    $normal = collect($report['details'])->firstWhere('owner_split', false);

    expect($split['owner_split'])->toBeTrue()
        ->and($split['base_total'])->toBe(4400.0)
        ->and($split['doctor_share'])->toBe(2200.0)
        ->and($normal['base_total'])->toBe(1000.0)
        ->and($normal['doctor_share'])->toBe(300.0);
});

test('owner trigger is persisted for owner-level catalog services but not implant crowns', function () {
    $implant = TreatmentCase::create(['name' => 'იმპლანტაცია Zimmer', 'category' => 'surgery', 'is_active' => true]);
    $sinus = TreatmentCase::create(['name' => 'Sinus lift', 'category' => 'surgery', 'is_active' => true]);
    $crown = TreatmentCase::create(['name' => 'ცირკონის გვირგვინი იმპლანტზე', 'category' => 'orthopedics', 'is_active' => true]);

    expect($implant->triggers_owner_split)->toBeTrue()
        ->and($sinus->triggers_owner_split)->toBeTrue()
        ->and($crown->triggers_owner_split)->toBeFalse();
});

test('owner split override supports auto on and off and other doctors never qualify', function () {
    [$levan] = ownerSplitDoctors();
    $visit = ownerSplitVisit($levan, [['name' => 'Implantation', 'price' => 1000, 'trigger' => true]], 1000);

    expect($visit->fresh()->usesOwnerSplit())->toBeTrue();
    $visit->update(['owner_split_override' => 'off']);
    expect($visit->fresh()->usesOwnerSplit())->toBeFalse();
    $visit->update(['owner_split_override' => 'on']);
    expect($visit->fresh()->usesOwnerSplit())->toBeTrue();

    $other = Doctor::create(['first_name' => 'Other', 'last_name' => 'Doctor', 'compensation_percentage' => 30, 'is_active' => true]);
    $otherVisit = ownerSplitVisit($other, [['name' => 'Implantation', 'price' => 1000, 'trigger' => true]], 1000);
    $otherVisit->update(['owner_split_override' => 'on']);
    expect($otherVisit->fresh()->usesOwnerSplit())->toBeFalse();
});

test('owner doctor salary modal shows the owner split badge and persists its compact override', function () {
    $this->actingAs(User::factory()->create());
    [$levan] = ownerSplitDoctors();
    $visit = ownerSplitVisit($levan, [['name' => 'Augmentation', 'price' => 1000, 'trigger' => true]], 1000);

    Livewire::test(ViewDoctor::class, ['record' => $levan->getRouteKey()])
        ->mountAction(TestAction::make('calculateSalary')->schemaComponent('compensation'))
        ->assertMountedActionModalSee(['Owner Split 50/50', 'Auto', 'On', 'Off'])
        ->call('setOwnerSplitOverride', $visit->getKey(), 'off');

    $visit = $visit->fresh();
    expect($visit->owner_split_override)->toBe('off')
        ->and($visit->usesOwnerSplit())->toBeFalse();
});

test('nodar salary preview shows levans automatic counterpart share before persistence', function () {
    $this->actingAs(User::factory()->create());
    [$levan, $nodar] = ownerSplitDoctors();
    ownerSplitVisit($nodar, [['name' => 'Preview implant', 'price' => 5000, 'trigger' => true]], 5000);

    Livewire::test(ViewDoctor::class, ['record' => $nodar->getRouteKey()])
        ->mountAction(TestAction::make('calculateSalary')->schemaComponent('compensation'))
        ->assertMountedActionModalSee(['Counterpart preview', $nodar->full_name.' share:', $levan->full_name.' receives:', '2,500.00']);

    expect(SalarySettlement::query()->count())->toBe(0)
        ->and(OwnerSalaryShare::query()->count())->toBe(0);
});

test('levan salary preview shows nodars net counterpart share after direct expenses', function () {
    $this->actingAs(User::factory()->create());
    [$levan, $nodar] = ownerSplitDoctors();
    $visit = ownerSplitVisit($levan, [['name' => 'Expense preview implant', 'price' => 5000, 'trigger' => true]], 5000);
    $visit->treatmentCaseItems()->sole()->directExpenses()->create(['name' => 'Materials', 'amount' => 1000, 'currency' => 'GEL']);

    $report = app(DoctorCompensationCalculator::class)->calculate(
        $levan->getKey(), today()->toDateString(), today()->toDateString(), 30,
    );
    $preview = $report['owner_split_preview'][0];
    expect($preview['counterpart_doctor'])->toBe($nodar->full_name)
        ->and($preview['net_basis'])->toBe(4000.0)
        ->and($preview['source_share'])->toBe(2000.0)
        ->and($preview['counterpart_share'])->toBe(2000.0)
        ->and(SalarySettlement::query()->count())->toBe(0)
        ->and(OwnerSalaryShare::query()->count())->toBe(0);

    Livewire::test(ViewDoctor::class, ['record' => $levan->getRouteKey()])
        ->mountAction(TestAction::make('calculateSalary')->schemaComponent('compensation'))
        ->assertMountedActionModalSee([$nodar->full_name.' receives:', 'Net basis after expenses:', '4,000.00', '2,000.00']);
});

test('nodar finalization fixes both owners and cannot be undone or duplicated', function () {
    $user = User::factory()->create();
    $cycle = ClinicPayrollCycle::create(['payroll_date' => today(), 'status' => 'draft']);
    [$levan, $nodar] = ownerSplitDoctors();
    $visit = ownerSplitVisit($nodar, [['name' => 'Sinus lift', 'price' => 5000, 'trigger' => true]], 5000);
    $service = app(SalarySettlementService::class);

    $nodarSettlement = $service->settle(
        $nodar->getKey(), today()->toDateString(), today()->toDateString(), 30, $user->getKey(), patientGroup: 'clinic', clinicPayrollCycleId: $cycle->id,
    )[0];
    $share = OwnerSalaryShare::query()->sole();
    $levanSettlement = SalarySettlement::query()->where('doctor_id', $levan->getKey())->sole();
    expect($share->status)->toBe('settled')
        ->and($share->source_doctor_id)->toBe($nodar->getKey())
        ->and($share->recipient_doctor_id)->toBe($levan->getKey())
        ->and((float) $share->amount)->toBe(2500.0)
        ->and($share->recipient_salary_settlement_id)->toBe($levanSettlement->getKey())
        ->and((float) $levanSettlement->owner_split_received_total)->toBe(2500.0)
        ->and((float) $levanSettlement->salary_total)->toBe(2500.0);

    $preview = app(DoctorCompensationCalculator::class)->calculate(
        $levan->getKey(), today()->toDateString(), today()->toDateString(), 30,
    );
    expect($preview['details'])->toBe([])->and($preview['totals'])->toBe([]);

    expect(fn () => $service->undo($nodarSettlement->getKey(), $nodar->getKey()))->toThrow(ValidationException::class);
    expect(fn () => $service->settle($nodar->getKey(), today()->toDateString(), today()->toDateString(), 30, $user->getKey()))->toThrow(ValidationException::class);
    expect(OwnerSalaryShare::query()->count())->toBe(1)
        ->and(SalarySettlement::query()->count())->toBe(2)
        ->and(OwnerSalaryShare::query()->sole()->source_salary_settlement_id)->toBe($nodarSettlement->getKey());
});
test('levan finalization automatically creates nodars finalized split', function () {
    $user = User::factory()->create();
    $cycle = ClinicPayrollCycle::create(['payroll_date' => today(), 'status' => 'draft']);
    $this->actingAs($user);
    [$levan, $nodar] = ownerSplitDoctors();
    $visit = ownerSplitVisit($levan, [['name' => 'Implantation', 'price' => 5000, 'trigger' => true]], 5000);
    app(SalarySettlementService::class)->settle(
        $levan->getKey(), today()->toDateString(), today()->toDateString(), 30, $user->getKey(), patientGroup: 'clinic', clinicPayrollCycleId: $cycle->id,
    );

    $settlement = SalarySettlement::query()->where('doctor_id', $nodar->getKey())->sole();
    $share = OwnerSalaryShare::query()->where('recipient_doctor_id', $nodar->getKey())->sole();
    expect((float) $settlement->normal_salary_total)->toBe(0.0)
        ->and((float) $settlement->owner_split_received_total)->toBe(2500.0)
        ->and((float) $settlement->salary_total)->toBe(2500.0)
        ->and($share->status)->toBe('settled')
        ->and($share->recipient_salary_settlement_id)->toBe($settlement->getKey())
        ->and($share->source_doctor_id)->toBe($levan->getKey())
        ->and($share->visit_id)->toBe($visit->getKey());
});

test('automatic counterpart split uses the finalized net basis after direct expenses', function () {
    $user = User::factory()->create();
    $cycle = ClinicPayrollCycle::create(['payroll_date' => today(), 'status' => 'draft']);
    [$levan, $nodar] = ownerSplitDoctors();
    $visit = ownerSplitVisit($nodar, [['name' => 'Implantation', 'price' => 5000, 'trigger' => true]], 5000);
    $visit->treatmentCaseItems()->sole()->directExpenses()->create([
        'name' => 'Implant materials', 'amount' => 1000, 'currency' => 'GEL',
    ]);

    $source = app(SalarySettlementService::class)->settle(
        $nodar->getKey(), today()->toDateString(), today()->toDateString(), 30, $user->getKey(), patientGroup: 'clinic', clinicPayrollCycleId: $cycle->id,
    )[0];
    $share = OwnerSalaryShare::query()->sole();
    $counterpart = SalarySettlement::query()->where('doctor_id', $levan->getKey())->sole();

    expect((float) $source->base_total)->toBe(4000.0)
        ->and((float) $source->salary_total)->toBe(2000.0)
        ->and((float) $share->amount)->toBe(2000.0)
        ->and((float) $counterpart->base_total)->toBe(4000.0)
        ->and((float) $counterpart->owner_split_received_total)->toBe(2000.0);

    $visit->treatmentCaseItems()->sole()->directExpenses()->update(['amount' => 500]);
    expect((float) $share->fresh()->amount)->toBe(2000.0)
        ->and((float) $counterpart->fresh()->salary_total)->toBe(2000.0);
});

test('normal non owner split settlement remains a single ordinary settlement', function () {
    $user = User::factory()->create();
    [$levan] = ownerSplitDoctors();
    ownerSplitVisit($levan, [['name' => 'Ordinary treatment', 'price' => 1000]], 1000);

    $settlements = app(SalarySettlementService::class)->settle(
        $levan->getKey(), today()->toDateString(), today()->toDateString(), 30, $user->getKey(),
    );

    expect($settlements)->toHaveCount(1)
        ->and(SalarySettlement::query()->count())->toBe(1)
        ->and(OwnerSalaryShare::query()->count())->toBe(0)
        ->and((float) $settlements[0]->normal_salary_total)->toBe(300.0)
        ->and((float) $settlements[0]->owner_split_received_total)->toBe(0.0);
});

test('salary history groups a later owner split settlement with the existing period card', function () {
    $user = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->actingAs($user);
    [$levan, $nodar] = ownerSplitDoctors();
    ownerSplitVisit($nodar, [['name' => 'Extraction', 'price' => 1000]], 1000);
    $service = app(SalarySettlementService::class);
    $service->settle($nodar->getKey(), today()->toDateString(), today()->toDateString(), 30, $user->getKey());

    ownerSplitVisit($levan, [['name' => 'Implantation', 'price' => 5000, 'trigger' => true]], 5000);
    $service->settle($levan->getKey(), today()->toDateString(), today()->toDateString(), 30, $user->getKey());
    $service->settle($nodar->getKey(), today()->toDateString(), today()->toDateString(), 30, $user->getKey());

    expect(SalarySettlement::query()->where('doctor_id', $nodar->getKey())->count())->toBe(2)
        ->and(OwnerSalaryShare::query()->where('recipient_doctor_id', $nodar->getKey())->sole()->status)->toBe('settled');

    $history = app(DoctorSalaryHistory::class)->forDoctor($nodar->getKey());
    expect($history)->toHaveCount(1);
    $display = $history->first();
    expect($display->historyRecords)->toHaveCount(2)
        ->and((float) $display->normal_salary_total)->toBe(300.0)
        ->and((float) $display->owner_split_received_total)->toBe(2500.0)
        ->and((float) $display->salary_total)->toBe(2800.0);

    Livewire::test(DoctorCompensation::class)
        ->call('openDoctorSalary', $nodar->getKey(), 'clinic')
        ->call('toggleDoctorSalaryHistory', $nodar->getKey())
        ->assertMountedActionModalSee('OWNER SPLIT')
        ->assertMountedActionModalSee('Owner Split — ლევანისგან')
        ->assertMountedActionModalSee('Owner Split +2,500.00 ₾')
        ->assertMountedActionModalSee('From Levan')
        ->assertMountedActionModalSee('Visit #')
        ->assertMountedActionModalSee('Settlement #')
        ->assertMountedActionModalSee('Implantation')
        ->assertMountedActionModalSee(['სულ', '2,800.00 ₾']);

    $next = app(DoctorCompensationCalculator::class)->calculate(
        $nodar->getKey(), today()->toDateString(), today()->toDateString(), 30,
    );
    expect($next['details'])->toBe([])
        ->and($next['owner_split_income'])->toBe([]);
});
