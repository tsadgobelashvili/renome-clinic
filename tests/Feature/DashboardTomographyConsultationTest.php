<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinanceReports;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('dashboard tomography consultation defaults off and has a localized label', function (string $locale, string $label) {
    $this->actingAs(User::factory()->create());
    app()->setLocale($locale);

    Livewire::test(Dashboard::class)->mountAction('manageTomography')
        ->assertSet('mountedActions.0.data.is_consultation', false)
        ->assertMountedActionModalSee($label);
})->with(['English' => ['en', 'Consultation'], 'Georgian' => ['ka', 'კონსულტაცია']]);

test('dashboard tomography classification preserves one visit procedures totals and payments', function (bool $consultation, bool $paid) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create(['first_name' => 'Tomography', 'last_name' => 'Patient']);
    $ct = TreatmentCase::create(['name' => '3D CT', 'category' => 'tomography', 'default_price' => 100, 'is_active' => true]);
    $panorama = TreatmentCase::create(['name' => 'Panorama', 'category' => 'tomography', 'default_price' => 40, 'is_active' => true]);
    TreatmentCase::create(['name' => 'Consultation', 'category' => 'consultation', 'default_price' => 50, 'is_active' => true]);

    Livewire::test(Dashboard::class)->callAction('manageTomography', [
        'patient_id' => $patient->id,
        'is_consultation' => $consultation,
        'consultation_source' => 'our_patient',
        'currency' => 'GEL',
        'tomographyItems' => [
            ['treatment_case_id' => $ct->id, 'quantity' => 1, 'unit_price' => 100],
            ['treatment_case_id' => $panorama->id, 'quantity' => 1, 'unit_price' => 40],
        ],
        'amount' => 140,
        'paymentSplits' => $paid ? [
            ['payment_method' => 'cash', 'amount' => 40, 'currency' => 'GEL'],
            ['payment_method' => 'card', 'amount' => 100, 'currency' => 'GEL'],
        ] : [],
    ])->assertHasNoActionErrors();

    $visit = Visit::query()->with('treatmentCaseItems', 'payments.splits')->sole();
    expect($visit->patient_id)->toBe($patient->id)
        ->and($visit->visit_type)->toBe($consultation ? 'consultation' : 'diagnostic')
        ->and((float) $visit->consultation_fee)->toBe(0.0)
        ->and($visit->treatmentCaseItems->pluck('treatment_case_id')->sort()->values()->all())->toBe([$ct->id, $panorama->id])
        ->and((float) $visit->total_price)->toBe(140.0)
        ->and($visit->payments)->toHaveCount($paid ? 1 : 0);
    if ($paid) {
        expect((float) $visit->payments->sole()->amount)->toBe(140.0)
            ->and($visit->payments->sole()->splits)->toHaveCount(2)
            ->and((float) $visit->payments->sole()->splits->sum('amount'))->toBe(140.0);
    }

    $reports = Livewire::test(FinanceReports::class)
        ->set('dateFrom', today()->toDateString())->set('dateUntil', today()->toDateString())
        ->call('selectSectionTab', 'doctors')
        ->call('toggleTotalConsultationPatients')
        ->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['total'] === (int) $consultation
            && $stats['consultations']['started'] === 0
            && array_column($stats['consultations']['totalPatients'], 'id') === ($consultation ? [$patient->id] : [])
            && $stats['tomography']['ct']['patients'] === 1
            && $stats['tomography']['panorama']['patients'] === 1);

    $therapy = TreatmentCase::create(['name' => 'Therapy', 'category' => 'therapy']);
    $treatment = Visit::create(['patient_id' => $patient->id, 'visit_date' => today(), 'visit_type' => 'treatment', 'currency' => 'GEL', 'total_price' => 0]);
    $treatment->treatmentCaseItems()->create(['treatment_case_id' => $therapy->id, 'quantity' => 1, 'unit_price' => 0, 'currency' => 'GEL']);
    $reports->call('$refresh')->assertViewHas('doctorStatistics', fn ($stats) => $stats['consultations']['started'] === (int) $consultation
        && $stats['consultations']['conversion'] === ($consultation ? 100.0 : 0.0));
})->with([false, true])->with([false, true]);
