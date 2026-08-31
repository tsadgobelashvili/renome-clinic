<?php

use App\Filament\Pages\DoctorCompensation;
use App\Models\CashboxTransaction;
use App\Models\DirectExpense;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Models\VisitTreatmentCase;
use App\Services\DoctorCompensationCalculator;
use App\Services\SalarySettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function undoSalaryVisit(Doctor $doctor, Patient $patient, float $amount, float $paid = 0): Visit
{
    $service = TreatmentCase::create([
        'name' => 'Undo salary work '.uniqid(),
        'category' => 'therapy',
        'is_active' => true,
    ]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'currency' => 'GEL',
        'total_price' => $amount,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $service->getKey(),
        'quantity' => 1,
        'unit_price' => $amount,
    ]);
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

test('missing zero and above one hundred percentage cannot create a settlement', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $doctor = Doctor::create(['first_name' => 'Invalid', 'last_name' => 'Percentage', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Percentage', 'last_name' => 'Patient']);
    undoSalaryVisit($doctor, $patient, 200, 200);

    Livewire::test(DoctorCompensation::class)
        ->set('doctorId', $doctor->getKey())
        ->set('from', today()->toDateString())
        ->set('until', today()->toDateString())
        ->set('percentage', null)
        ->call('confirmSettlement')
        ->assertHasErrors(['percentage']);

    foreach ([0, 101] as $percentage) {
        expect(fn () => app(SalarySettlementService::class)->settle(
            $doctor->getKey(), today()->toDateString(), today()->toDateString(), $percentage, $user->getKey(),
        ))->toThrow(ValidationException::class);
    }

    expect(SalarySettlement::query()->count())->toBe(0);
});

test('valid percentage creates a settlement normally', function () {
    $doctor = Doctor::create(['first_name' => 'Valid', 'last_name' => 'Percentage', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Valid', 'last_name' => 'Patient']);
    undoSalaryVisit($doctor, $patient, 200, 200);

    app(SalarySettlementService::class)->settle(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 25, null,
    );

    expect(SalarySettlement::query()->sole()->salary_total)->toEqual('50.00');
});

test('undo removes only the selected settlement and restores the previous marker and eligible work', function () {
    $doctor = Doctor::create(['first_name' => 'Previous', 'last_name' => 'Marker', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Previous', 'last_name' => 'Patient']);
    $firstVisit = undoSalaryVisit($doctor, $patient, 100, 100);
    app(SalarySettlementService::class)->settle($doctor->getKey(), today()->toDateString(), today()->toDateString(), 20, null);
    $firstSettlement = SalarySettlement::query()->sole();

    $secondVisit = undoSalaryVisit($doctor, $patient, 200, 200);
    app(SalarySettlementService::class)->settle($doctor->getKey(), today()->toDateString(), today()->toDateString(), 20, null);
    $secondSettlement = SalarySettlement::query()->latest('id')->firstOrFail();

    expect(app(SalarySettlementService::class)->undo($secondSettlement->getKey(), $doctor->getKey()))->toBeTrue();

    $report = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 20,
    );
    $summary = app(DoctorCompensationCalculator::class)->summary($doctor);

    expect(SalarySettlement::query()->pluck('id')->all())->toBe([$firstSettlement->getKey()])
        ->and($report['details'])->toHaveCount(1)
        ->and($report['details'][0]['visit_id'])->toBe($secondVisit->getKey())
        ->and($summary['last_visit_id'])->toBe($firstVisit->getKey());
});

test('undo is group isolated repeat safe and preserves all clinical and financial source data', function () {
    $doctor = Doctor::create(['first_name' => 'Group', 'last_name' => 'Undo', 'is_active' => true]);
    $clinic = Patient::create(['first_name' => 'Undo', 'last_name' => 'Clinic']);
    $israel = Patient::create(['first_name' => 'Undo', 'last_name' => 'Israel', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $clinicVisit = undoSalaryVisit($doctor, $clinic, 300, 300);
    $israelVisit = undoSalaryVisit($doctor, $israel, 400);
    $clinicVisit->treatmentCaseItems->first()->directExpenses()->create(['name' => 'Lab', 'amount' => 25, 'currency' => 'GEL']);

    app(SalarySettlementService::class)->settle($doctor->getKey(), today()->toDateString(), today()->toDateString(), 30, null);
    $clinicSettlement = SalarySettlement::query()->where('patient_group_slug', PatientGroup::CLINIC_SLUG)->sole();
    $israelSettlement = SalarySettlement::query()->where('patient_group_slug', PatientGroup::ISRAEL_PARTNER_SLUG)->sole();
    $sourceCounts = [
        Visit::query()->count(), VisitTreatmentCase::query()->count(), Payment::query()->count(),
        DirectExpense::query()->count(), CashboxTransaction::query()->count(),
    ];

    $service = app(SalarySettlementService::class);
    expect($service->undo($israelSettlement->getKey(), $doctor->getKey()))->toBeTrue()
        ->and($service->undo($israelSettlement->getKey(), $doctor->getKey()))->toBeFalse()
        ->and(SalarySettlement::query()->whereKey($clinicSettlement->getKey())->exists())->toBeTrue();

    $clinicReport = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 30, null, PatientGroup::CLINIC_SLUG,
    );
    $israelReport = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 30, null, PatientGroup::ISRAEL_PARTNER_SLUG,
    );

    expect($clinicReport['details'])->toBeEmpty()
        ->and(collect($israelReport['details'])->pluck('visit_id')->all())->toBe([$israelVisit->getKey()])
        ->and([
            Visit::query()->count(), VisitTreatmentCase::query()->count(), Payment::query()->count(),
            DirectExpense::query()->count(), CashboxTransaction::query()->count(),
        ])->toBe($sourceCounts);

    app(SalarySettlementService::class)->settle(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 30, null, null, PatientGroup::ISRAEL_PARTNER_SLUG,
    );
    $newIsraelSettlement = SalarySettlement::query()->where('patient_group_slug', PatientGroup::ISRAEL_PARTNER_SLUG)->sole();
    expect($service->undo($clinicSettlement->getKey(), $doctor->getKey()))->toBeTrue()
        ->and(SalarySettlement::query()->whereKey($newIsraelSettlement->getKey())->exists())->toBeTrue();

    $clinicReport = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 30, null, PatientGroup::CLINIC_SLUG,
    );
    expect(collect($clinicReport['details'])->pluck('visit_id')->all())->toBe([$clinicVisit->getKey()]);
});

test('undo supports an existing zero value settlement and releases its exact item', function () {
    $doctor = Doctor::create(['first_name' => 'Legacy', 'last_name' => 'Zero', 'is_active' => true]);
    $partner = Patient::create(['first_name' => 'Legacy', 'last_name' => 'Israel', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $visit = undoSalaryVisit($doctor, $partner, 250);
    $item = $visit->treatmentCaseItems()->sole();
    $settlement = SalarySettlement::query()->create([
        'doctor_id' => $doctor->getKey(), 'patient_group_slug' => PatientGroup::ISRAEL_PARTNER_SLUG,
        'period_start' => today(), 'period_end' => today(), 'settled_at' => now(), 'currency' => 'GEL',
        'performed_total' => 250, 'paid_amount' => 0, 'outstanding_amount' => 250,
        'direct_expense_total' => 0, 'base_total' => 0, 'percentage' => 0,
        'salary_total' => 0, 'status' => 'confirmed',
    ]);
    $settlement->items()->create([
        'visit_id' => $visit->getKey(), 'visit_treatment_case_id' => $item->getKey(),
        'patient_group_slug' => PatientGroup::ISRAEL_PARTNER_SLUG,
        'revenue' => 250, 'direct_expense' => 0, 'salary_base' => 0, 'doctor_share' => 0,
        'total_value_snapshot' => 250, 'paid_amount_snapshot' => 0, 'outstanding_amount_snapshot' => 250,
        'expense_snapshot' => 0, 'base_snapshot' => 0, 'doctor_share_snapshot' => 0,
    ]);

    expect(app(SalarySettlementService::class)->undo($settlement->getKey(), $doctor->getKey()))->toBeTrue();
    $report = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 25, null, PatientGroup::ISRAEL_PARTNER_SLUG,
    );

    expect(SalarySettlement::query()->count())->toBe(0)
        ->and(SalarySettlementItem::query()->count())->toBe(0)
        ->and($report['details'][0]['visit_id'])->toBe($visit->getKey());
});

test('undo refreshes the livewire salary state and recalculates from fresh linkage data', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'Livewire', 'last_name' => 'Undo', 'compensation_percentage' => 25, 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Livewire', 'last_name' => 'Patient']);
    $visit = undoSalaryVisit($doctor, $patient, 200, 200);
    app(SalarySettlementService::class)->settle(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 25, null,
    );
    $settlement = SalarySettlement::query()->sole();

    Livewire::test(DoctorCompensation::class)
        ->set('doctorId', $doctor->getKey())
        ->set('from', today()->toDateString())
        ->set('until', today()->toDateString())
        ->set('percentage', 25)
        ->call('undoSettlement', $settlement->getKey())
        ->call('calculate')
        ->assertSet('report.details.0.visit_id', $visit->getKey());

    expect(SalarySettlementItem::query()->where('salary_settlement_id', $settlement->getKey())->exists())->toBeFalse();
});
