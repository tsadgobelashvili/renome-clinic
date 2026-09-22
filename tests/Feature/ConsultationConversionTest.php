<?php

use App\Filament\Pages\FinanceReports;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\TreatmentEstimate;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Consultation', 'last_name' => 'Doctor', 'is_active' => true]);
    $this->consultation = TreatmentCase::create(['name' => 'Consultation', 'category' => 'consultation']);
    $this->ct = TreatmentCase::create(['name' => '3D CT', 'category' => 'tomography']);
    $this->panorama = TreatmentCase::create(['name' => 'Panorama', 'category' => 'tomography']);
    $this->therapy = TreatmentCase::create(['name' => 'Therapy', 'category' => 'therapy']);
    $this->visit = function (array $services, ?Patient $patient = null, array $attributes = []): Visit {
        $cancelledAt = $attributes['cancelled_at'] ?? null;
        unset($attributes['cancelled_at']);
        $patient ??= Patient::create(['first_name' => 'Conversion', 'last_name' => 'Patient']);
        $visit = Visit::create([...[
            'patient_id' => $patient->id, 'doctor_id' => $this->doctor->id, 'visit_date' => today()->subDays(8),
            'visit_type' => 'consultation', 'total_price' => 0, 'currency' => 'GEL',
        ], ...$attributes]);
        foreach ($services as $service) {
            $visit->treatmentCaseItems()->create(['treatment_case_id' => $service->id,
                'quantity' => 1, 'unit_price' => 0, 'currency' => $visit->currency]);
        }
        if ($cancelledAt) {
            $visit->update(['cancelled_at' => $cancelledAt]);
        }

        return $visit;
    };
    $this->page = fn () => Livewire::test(FinanceReports::class)
        ->set('dateFrom', today()->subDays(12)->toDateString())->set('dateUntil', today()->toDateString())
        ->call('selectSectionTab', 'doctors');
});

test('total consultation card lazily expands the exact cohort and reuses the not-started list', function () {
    $waiting = ($this->visit)([$this->consultation], attributes: ['visit_date' => today()]);
    $notStarted = ($this->visit)([$this->consultation]);
    $converted = ($this->visit)([$this->consultation]);
    ($this->visit)([$this->consultation], $converted->patient, ['visit_date' => today()]);
    ($this->visit)([$this->therapy], $converted->patient, ['visit_type' => 'treatment', 'visit_date' => today()]);
    ($this->visit)([$this->ct], attributes: ['visit_type' => 'treatment']);
    $ids = [$waiting->patient_id, $notStarted->patient_id, $converted->patient_id];
    sort($ids);
    $page = ($this->page)()->assertSet('showTotalConsultationPatients', false)
        ->assertSeeHtml('wire:click="toggleTotalConsultationPatients"')
        ->assertSee('დაიწყო მკურნალობა')
        ->assertDontSee('მოლოდინში')
        ->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['totalPatients'] === [])
        ->call('toggleTotalConsultationPatients')->assertSet('showTotalConsultationPatients', true)
        ->assertViewHas('doctorStatistics', function ($stats) use ($ids) {
            $rows = $stats['consultations']['totalPatients'];
            $actual = array_column($rows, 'id');
            sort($actual);

            return $actual === $ids && count($rows) === $stats['consultations']['total']
                && isset($rows[0]['patient'], $rows[0]['consultationDate'], $rows[0]['doctor'], $rows[0]['days']);
        })
        ->call('toggleDoctor', $this->doctor->id)
        ->assertViewHas('doctorStatistics', fn ($stats) => count($stats['consultations']['totalPatients']) === $stats['consultations']['total'])
        ->call('toggleTotalConsultationPatients')->assertSet('showTotalConsultationPatients', false)
        ->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['totalPatients'] === [])
        ->call('toggleNotStartedPatients')->assertSet('showNotStartedPatients', true)
        ->assertViewHas('doctorStatistics', fn ($stats) => count($stats['consultations']['notStartedPatients']) === 2)
        ->call('toggleTotalConsultationPatients')->assertSet('showNotStartedPatients', false)
        ->set('dateFrom', today()->toDateString())->assertSet('showTotalConsultationPatients', false)
        ->call('toggleTotalConsultationPatients')
        ->assertViewHas('doctorStatistics', fn ($stats) => count($stats['consultations']['totalPatients']) === 2
            && $stats['consultations']['total'] === 2)
        ->call('selectSectionTab', 'finance')->assertSet('showTotalConsultationPatients', false);
});

test('only actual consultation procedures form the distinct patient conversion cohort', function () {
    $only = ($this->visit)([$this->consultation]);
    $mixed = ($this->visit)([$this->consultation, $this->ct]);
    ($this->visit)([$this->consultation], $mixed->patient, ['visit_date' => today()->subDays(2)]);
    ($this->visit)([$this->ct], attributes: ['visit_type' => 'treatment']);
    ($this->visit)([$this->panorama], attributes: ['visit_type' => 'treatment']);
    ($this->visit)([$this->therapy], attributes: ['visit_type' => 'treatment']);
    ($this->visit)([], attributes: ['visit_type' => 'treatment']);
    ($this->visit)([$this->therapy], $mixed->patient, ['visit_type' => 'treatment', 'visit_date' => today()]);
    ($this->page)()->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 2
        && $stats['consultations']['started'] === 1 && $stats['consultations']['notStarted'] === 1
        && $stats['consultations']['conversion'] === 50.0
        && $stats['tomography']['ct']['patients'] === 2 && $stats['tomography']['panorama']['patients'] === 1)
        ->call('toggleNotStartedPatients')
        ->assertViewHas('doctorStatistics', fn ($stats) => array_column($stats['consultations']['notStartedPatients'], 'id') === [$only->patient_id])
        ->call('toggleDoctor', $this->doctor->id)
        ->assertSet('selectedDoctorId', $this->doctor->id)
        ->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 2);
});

test('date currency source and cancelled filters also constrain the consultation anchor', function () {
    ($this->visit)([$this->consultation]);
    ($this->visit)([$this->consultation], attributes: ['visit_date' => today()->subMonth()]);
    ($this->visit)([$this->consultation], attributes: ['cancelled_at' => now()]);
    ($this->visit)([$this->consultation], attributes: ['currency' => 'USD']);
    $partner = Patient::create(['first_name' => 'Partner', 'last_name' => 'Consultation', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    ($this->visit)([$this->consultation], $partner);
    // Earlier non-consultation on the same day must not become the detail anchor.
    $otherDoctor = Doctor::create(['first_name' => 'Imaging', 'last_name' => 'Doctor', 'is_active' => true]);
    $imaging = ($this->visit)([$this->ct], attributes: ['doctor_id' => $otherDoctor->id, 'visit_type' => 'treatment']);
    ($this->visit)([$this->consultation], $imaging->patient);
    ($this->page)()->set('source', 'clinic')->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 2)
        ->call('toggleNotStartedPatients')
        ->assertViewHas('doctorStatistics', fn ($stats) => collect($stats['consultations']['notStartedPatients'])->every(fn ($row) => $row['doctor'] === $this->doctor->full_name))
        ->set('source', 'partner')->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 1)
        ->set('source', 'clinic')->set('currency', 'USD')->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 1);
});

test('periodontal consultation uses explicit catalog classification and counts once with imaging', function () {
    $periodontal = TreatmentCase::create(['name' => 'Periodontal consultation', 'category' => 'consultation']);
    $visit = ($this->visit)([$periodontal, $this->ct]);
    ($this->visit)([$periodontal], $visit->patient, ['visit_date' => today()]);
    // No text-based guessing: a similar name with a different category is not a consultation.
    $unmapped = TreatmentCase::create(['name' => 'Consultation-like periodontal work', 'category' => 'periodontology']);
    ($this->visit)([$unmapped], attributes: ['visit_type' => 'treatment']);
    ($this->page)()->call('toggleTotalConsultationPatients')
        ->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 1
            && array_column($stats['consultations']['totalPatients'], 'id') === [$visit->patient_id]);
});

test('consultation cohort is independent of treatment plan and payment status', function () {
    $withoutPlan = ($this->visit)([$this->consultation], attributes: ['visit_type' => 'consultation', 'doctor_id' => null]);
    $withPlan = ($this->visit)([$this->consultation]);
    TreatmentEstimate::create(['patient_id' => $withPlan->patient_id, 'doctor_id' => $this->doctor->id,
        'visit_id' => $withPlan->id, 'estimate_date' => today()]);
    expect($withoutPlan->treatmentEstimates()->count())->toBe(0)
        ->and($withPlan->treatmentEstimates()->count())->toBe(1)
        ->and($withPlan->payments()->count())->toBe(0);
    ($this->page)()->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 2
        && $stats['consultations']['started'] === 0);
});

test('later real procedures convert the same patient in treatment mode and without a plan', function () {
    $only = ($this->visit)([$this->consultation], attributes: ['visit_date' => today()]);
    $imaging = ($this->visit)([$this->consultation]);
    ($this->visit)([$this->ct, $this->panorama], $imaging->patient, ['visit_date' => today()]);
    $converted = ($this->visit)([$this->consultation]);
    // A later Treatment visit establishes conversion without a plan.
    ($this->visit)([$this->therapy], $converted->patient, ['visit_date' => today()->subDay(), 'visit_type' => 'treatment']);
    ($this->visit)([$this->therapy], $converted->patient, ['visit_date' => today(), 'visit_type' => 'treatment']);
    ($this->visit)([$this->consultation], $converted->patient, ['visit_date' => today()->subDays(2)]);
    $page = ($this->page)()->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 3
        && $stats['consultations']['started'] === 1 && $stats['consultations']['notStarted'] === 2
        && $stats['consultations']['conversion'] === 33.3)
        ->call('toggleNotStartedPatients')->assertViewHas('doctorStatistics', function ($stats) use ($only, $imaging) {
            $ids = array_column($stats['consultations']['notStartedPatients'], 'id');
            sort($ids);
            $expected = [$only->patient_id, $imaging->patient_id];
            sort($expected);

            return $ids === $expected;
        })
        ->call('toggleTotalConsultationPatients')->assertViewHas('doctorStatistics', fn ($stats) => count($stats['consultations']['totalPatients']) === 3);
});

test('only subsequent noncancelled treatment converts and it can follow the cohort period', function () {
    $before = ($this->visit)([$this->therapy], attributes: ['visit_date' => today()->subDays(10)]);
    ($this->visit)([$this->consultation], $before->patient);
    $cancelled = ($this->visit)([$this->consultation]);
    ($this->visit)([$this->therapy], $cancelled->patient, ['visit_date' => today(), 'cancelled_at' => now()]);
    $converted = ($this->visit)([$this->consultation]);
    ($this->visit)([$this->therapy], $converted->patient, ['visit_date' => today(), 'visit_type' => 'treatment']);
    ($this->page)()->set('dateUntil', today()->subDays(7)->toDateString())
        ->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === 3 && $stats['consultations']['started'] === 1);
});

test('statistics has no separate dynamics tab but retains the finance trend', function () {
    Livewire::test(FinanceReports::class)->assertDontSeeHtml('reports-tab-dynamics')
        ->assertSeeHtml('fi-wi-chart-canvas-ctn')->call('selectSectionTab', 'dynamics')->assertSet('sectionTab', 'finance');
});

test('visit type defines the cohort including historical consultation fee and diagnostic visits', function () {
    $fee = ($this->visit)([]);
    $radiology = ($this->visit)([$this->ct]);
    $wrongMode = ($this->visit)([$this->consultation], attributes: ['visit_type' => 'treatment']);
    ($this->visit)([$this->ct, $this->panorama], $radiology->patient, ['visit_type' => 'treatment', 'visit_date' => today()]);
    ($this->visit)([], $fee->patient, ['visit_date' => today()->subDays(2)]);
    ($this->visit)([$this->therapy], $fee->patient, ['visit_type' => 'treatment', 'visit_date' => today()]);
    ($this->page)()->call('toggleTotalConsultationPatients')->assertViewHas('doctorStatistics', function ($stats) use ($wrongMode) {
        $c = $stats['consultations'];

        return $c['total'] === 2 && $c['started'] === 1 && $c['notStarted'] === 1 && $c['conversion'] === 50.0
            && ! in_array($wrongMode->patient_id, array_column($c['totalPatients'], 'id'));
    });
});
