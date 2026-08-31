<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Models\CashboxTransaction;
use App\Models\Doctor;
use App\Models\PartnerPatientPayment;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\SalarySettlement;
use App\Models\SalarySettlementItem;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use App\Services\PartnerVisitPaymentRecorder;
use App\Services\SalarySettlementService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function partnerSalaryVisit(Doctor $doctor, Patient $patient, float $amount): Visit
{
    $service = TreatmentCase::create([
        'name' => 'Partner completed work '.uniqid(),
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

    return $visit;
}

test('partner completed work is salary eligible without a clinic payment', function () {
    $doctor = Doctor::create(['first_name' => 'Partner', 'last_name' => 'Doctor', 'compensation_percentage' => 30, 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Israel', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $visit = partnerSalaryVisit($doctor, $patient, 240);

    $report = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 30, null, PatientGroup::ISRAEL_PARTNER_SLUG,
    );

    expect(Payment::query()->count())->toBe(0)
        ->and($report['details'])->toHaveCount(1)
        ->and($report['details'][0]['visit_id'])->toBe($visit->getKey())
        ->and($report['details'][0]['paid_total'])->toBe(0.0)
        ->and($report['details'][0]['base_total'])->toBe(240.0)
        ->and($report['details'][0]['doctor_share'])->toBe(72.0);
});

test('new doctor without a percentage sees unpaid partner work in salary preview', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create([
        'first_name' => 'Natalia',
        'last_name' => 'Iluridze',
        'compensation_percentage' => null,
        'is_active' => true,
    ]);
    $patient = Patient::create([
        'first_name' => 'New',
        'last_name' => 'Partner',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $visit = partnerSalaryVisit($doctor, $patient, 360);

    $report = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(),
        today()->toDateString(),
        today()->toDateString(),
        null,
        null,
        PatientGroup::ISRAEL_PARTNER_SLUG,
    );

    expect($report['details'])->toHaveCount(1)
        ->and($report['details'][0]['visit_id'])->toBe($visit->getKey())
        ->and($report['details'][0]['paid_total'])->toBe(0.0)
        ->and($report['details'][0]['base_total'])->toBe(360.0)
        ->and($report['details'][0]['doctor_share'])->toBe(0.0);

    $action = TestAction::make('calculateSalary')->schemaComponent('compensation');
    Livewire::test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])
        ->mountAction($action)
        ->set('mountedActions.0.data.patient_group', PatientGroup::ISRAEL_PARTNER_SLUG)
        ->assertMountedActionModalSee([
            'New Partner',
            'Visit #'.$visit->getKey(),
            '360.00 ₾',
        ])
        ->callMountedAction()
        ->assertHasActionErrors(['percentage']);

    expect(SalarySettlement::query()->count())->toBe(0);
});

test('standalone salary preview does not require a percentage to list eligible work', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create([
        'first_name' => 'Preview',
        'last_name' => 'Doctor',
        'compensation_percentage' => null,
        'is_active' => true,
    ]);
    $patient = Patient::create([
        'first_name' => 'Preview',
        'last_name' => 'Partner',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $visit = partnerSalaryVisit($doctor, $patient, 250);

    Livewire::test(DoctorCompensation::class)
        ->set('doctorId', $doctor->getKey())
        ->set('from', today()->toDateString())
        ->set('until', today()->toDateString())
        ->set('patientGroup', PatientGroup::ISRAEL_PARTNER_SLUG)
        ->set('percentage', null)
        ->call('calculate')
        ->assertHasNoErrors()
        ->assertSet('report.details.0.visit_id', $visit->getKey())
        ->call('confirmSettlement')
        ->assertHasErrors(['percentage']);
});

test('partner visit saves unpaid through the shared create visit flow', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'Unpaid', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Unpaid', 'last_name' => 'Partner', 'patient_group_id' => PatientGroup::israelPartnerId()]);

    Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(), 'visit_type' => 'treatment',
        'treatmentCaseItems' => [[
            'service_choice' => '__manual__', 'treatment_case_id' => null,
            'custom_service_name' => 'Partner filling', 'quantity' => 2, 'unit_price' => 120,
        ]],
    ])->call('create')->assertHasNoErrors();

    $visit = Visit::query()->with('treatmentCaseItems')->sole();
    expect((float) $visit->total_price)->toBe(240.0)
        ->and($visit->payments()->count())->toBe(0)
        ->and($visit->treatmentCaseItems)->toHaveCount(1);
});

test('partner payment from shared create visit flow is recorded once outside cashbox', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'Paid', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Paid', 'last_name' => 'Partner', 'patient_group_id' => PatientGroup::israelPartnerId()]);

    Livewire::test(CreateVisit::class)->fillForm([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(), 'visit_type' => 'treatment',
        'treatmentCaseItems' => [[
            'service_choice' => '__manual__', 'treatment_case_id' => null,
            'custom_service_name' => 'Partner paid work', 'quantity' => 1, 'unit_price' => 240,
        ]],
    ])->call('submitPayment', [
        'amount' => 240, 'currency' => 'GEL',
        'splits' => [['payment_method' => 'cash', 'amount' => 240, 'currency' => 'GEL']],
    ])->call('create')->assertHasNoErrors();

    expect(Visit::query()->count())->toBe(1)
        ->and(PartnerPatientPayment::query()->count())->toBe(1)
        ->and(Payment::query()->count())->toBe(0)
        ->and(CashboxTransaction::query()->count())->toBe(0);
});

test('clinic salary remains paid based while all mode applies each group rule', function () {
    $doctor = Doctor::create(['first_name' => 'Mixed', 'last_name' => 'Doctor', 'compensation_percentage' => 50, 'is_active' => true]);
    $clinic = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Patient']);
    $partner = Patient::create(['first_name' => 'Partner', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $clinicVisit = partnerSalaryVisit($doctor, $clinic, 200);
    $partnerVisit = partnerSalaryVisit($doctor, $partner, 300);
    $clinicVisit->payments()->create(['amount' => 100, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);

    $report = app(DoctorCompensationCalculator::class)->calculate($doctor->getKey(), today()->toDateString(), today()->toDateString(), 50);

    expect(collect($report['details'])->pluck('visit_id')->all())->toContain($clinicVisit->getKey(), $partnerVisit->getKey())
        ->and(collect($report['details'])->firstWhere('visit_id', $clinicVisit->getKey())['base_total'])->toBe(100.0)
        ->and(collect($report['details'])->firstWhere('visit_id', $partnerVisit->getKey())['base_total'])->toBe(300.0)
        ->and($report['totals_by_group'][PatientGroup::CLINIC_SLUG]['GEL']['doctor_share'])->toBe(50.0)
        ->and($report['totals_by_group'][PatientGroup::ISRAEL_PARTNER_SLUG]['GEL']['doctor_share'])->toBe(150.0);
});

test('partner visit payment is isolated from clinic payments and cashbox', function () {
    $patient = Patient::create(['first_name' => 'Paying', 'last_name' => 'Partner', 'patient_group_id' => PatientGroup::israelPartnerId()]);

    app(PartnerVisitPaymentRecorder::class)->record($patient, [
        ['payment_method' => 'cash', 'amount' => 100, 'currency' => 'GEL'],
        ['payment_method' => 'bank_transfer', 'amount' => 50, 'currency' => 'USD'],
    ]);

    expect(PartnerPatientPayment::query()->count())->toBe(2)
        ->and(Payment::query()->count())->toBe(0)
        ->and(CashboxTransaction::query()->count())->toBe(0);
});

test('salary settlement snapshots partner source and excludes settled work', function () {
    $doctor = Doctor::create(['first_name' => 'Settled', 'last_name' => 'Partner', 'compensation_percentage' => 25, 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Snapshot', 'last_name' => 'Partner', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    partnerSalaryVisit($doctor, $patient, 400);

    app(SalarySettlementService::class)->settle(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 25, null, null, PatientGroup::ISRAEL_PARTNER_SLUG,
    );
    $settlement = SalarySettlement::query()->with('items')->sole();
    $next = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 25, null, PatientGroup::ISRAEL_PARTNER_SLUG,
    );

    expect($settlement->patient_group_slug)->toBe(PatientGroup::ISRAEL_PARTNER_SLUG)
        ->and($settlement->items->sole()->patient_group_slug)->toBe(PatientGroup::ISRAEL_PARTNER_SLUG)
        ->and((float) $settlement->salary_total)->toBe(100.0)
        ->and($next['details'])->toBeEmpty();
});

test('clinic only confirmation creates one clinic settlement with unchanged paid based percentage rule', function () {
    $doctor = Doctor::create(['first_name' => 'Clinic', 'last_name' => 'Settlement', 'compensation_percentage' => 40, 'is_active' => true]);
    $clinic = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Only']);
    $visit = partnerSalaryVisit($doctor, $clinic, 500);
    $visit->payments()->create(['amount' => 300, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);

    app(SalarySettlementService::class)->settle(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 40, null, null, PatientGroup::CLINIC_SLUG,
    );

    $settlement = SalarySettlement::query()->with('items')->sole();
    expect($settlement->patient_group_slug)->toBe(PatientGroup::CLINIC_SLUG)
        ->and((float) $settlement->base_total)->toBe(300.0)
        ->and((float) $settlement->percentage)->toBe(40.0)
        ->and((float) $settlement->salary_total)->toBe(120.0)
        ->and($settlement->items->pluck('patient_group_slug')->unique()->all())->toBe([PatientGroup::CLINIC_SLUG]);
});

test('both confirmation creates exactly one separate settlement per patient group', function () {
    $doctor = Doctor::create(['first_name' => 'Both', 'last_name' => 'Settlement', 'compensation_percentage' => 50, 'is_active' => true]);
    $clinic = Patient::create(['first_name' => 'Both', 'last_name' => 'Clinic']);
    $partner = Patient::create(['first_name' => 'Both', 'last_name' => 'Israel', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $clinicVisit = partnerSalaryVisit($doctor, $clinic, 400);
    $partnerVisit = partnerSalaryVisit($doctor, $partner, 600);
    $clinicVisit->payments()->create(['amount' => 200, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);

    app(SalarySettlementService::class)->settle(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 50, null,
    );

    $settlements = SalarySettlement::query()->with('items')->orderBy('patient_group_slug')->get();
    $clinicSettlement = $settlements->firstWhere('patient_group_slug', PatientGroup::CLINIC_SLUG);
    $partnerSettlement = $settlements->firstWhere('patient_group_slug', PatientGroup::ISRAEL_PARTNER_SLUG);

    expect($settlements)->toHaveCount(2)
        ->and($clinicSettlement->items->pluck('visit_id')->unique()->all())->toBe([$clinicVisit->getKey()])
        ->and($partnerSettlement->items->pluck('visit_id')->unique()->all())->toBe([$partnerVisit->getKey()])
        ->and((float) $clinicSettlement->base_total)->toBe(200.0)
        ->and((float) $clinicSettlement->salary_total)->toBe(100.0)
        ->and((float) $partnerSettlement->base_total)->toBe(600.0)
        ->and((float) $partnerSettlement->salary_total)->toBe(300.0);
});

test('both confirmation rolls back every group when a later settlement item fails', function () {
    $doctor = Doctor::create(['first_name' => 'Rollback', 'last_name' => 'Settlement', 'compensation_percentage' => 30, 'is_active' => true]);
    $partner = Patient::create(['first_name' => 'Rollback', 'last_name' => 'Israel', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $clinic = Patient::create(['first_name' => 'Rollback', 'last_name' => 'Clinic']);
    partnerSalaryVisit($doctor, $partner, 300);
    $clinicVisit = partnerSalaryVisit($doctor, $clinic, 200);
    $clinicVisit->payments()->create(['amount' => 200, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);

    $event = 'eloquent.creating: '.SalarySettlementItem::class;
    Event::listen($event, function (SalarySettlementItem $item): void {
        if ($item->patient_group_slug === PatientGroup::ISRAEL_PARTNER_SLUG) {
            throw new RuntimeException('Simulated Israel settlement item failure.');
        }
    });

    try {
        expect(fn () => app(SalarySettlementService::class)->settle(
            $doctor->getKey(), today()->toDateString(), today()->toDateString(), 30, null,
        ))->toThrow(RuntimeException::class);
    } finally {
        Event::forget($event);
    }

    expect(SalarySettlement::query()->count())->toBe(0)
        ->and(SalarySettlementItem::query()->count())->toBe(0);
});

test('repeated salary confirmation cannot duplicate already settled work', function () {
    $doctor = Doctor::create(['first_name' => 'Repeat', 'last_name' => 'Settlement', 'compensation_percentage' => 25, 'is_active' => true]);
    $partner = Patient::create(['first_name' => 'Repeat', 'last_name' => 'Israel', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    partnerSalaryVisit($doctor, $partner, 400);
    $service = app(SalarySettlementService::class);

    $service->settle($doctor->getKey(), today()->toDateString(), today()->toDateString(), 25, null, null, PatientGroup::ISRAEL_PARTNER_SLUG);

    expect(fn () => $service->settle(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 25, null, null, PatientGroup::ISRAEL_PARTNER_SLUG,
    ))->toThrow(ValidationException::class)
        ->and(SalarySettlement::query()->count())->toBe(1)
        ->and(SalarySettlementItem::query()->count())->toBe(1);
});
