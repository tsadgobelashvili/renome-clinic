<?php

use App\Filament\Pages\FinanceReports;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('finance reports show existing totals and category breakdowns', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));

    FinanceTransaction::create([
        'type' => 'income',
        'transaction_date' => now(),
        'category' => 'other_income',
        'amount' => 120,
        'currency' => 'GEL',
        'payment_method' => 'cash',
        'cash_source' => 'current_cashier',
    ]);

    FinanceTransaction::create([
        'type' => 'expense',
        'transaction_date' => now(),
        'category' => 'materials',
        'description' => 'Test materials expense',
        'amount' => 30,
        'currency' => 'GEL',
        'payment_method' => 'cash',
        'cash_source' => 'current_cashier',
    ]);

    Livewire::test(FinanceReports::class)
        ->assertOk()
        ->assertSeeHtml('renome-finance-donut')
        ->assertViewHas('reportTotal', 120.0)
        ->assertViewHas('reportRows', fn (array $rows): bool => collect($rows)->contains(
            fn (array $row): bool => $row['key'] === 'other_income' && $row['amount'] === 120.0,
        ))
        ->call('selectReportTab', 'expense')
        ->assertViewHas('reportTotal', 30.0)
        ->assertViewHas('reportRows', fn (array $rows): bool => collect($rows)->contains(
            fn (array $row): bool => $row['key'] === 'materials' && $row['amount'] === 30.0,
        ))
        ->assertViewHas('breakdownDescriptions', fn (array $descriptions): bool => $descriptions['materials'] === ['Test materials expense'])
        ->call('selectReportTab', 'cash_out')
        ->assertViewHas('reportTotal', 30.0)
        ->call('selectSectionTab', 'dynamics')
        ->assertSet('sectionTab', 'dynamics')
        ->assertSee('შემოსავალი')
        ->call('selectSectionTab', 'doctors')
        ->assertSet('sectionTab', 'doctors')
        ->assertSee('ექიმების რეიტინგი')
        ->call('selectSectionTab', 'finance')
        ->assertSet('sectionTab', 'finance')
        ->assertSeeHtml('renome-finance-donut')
        ->set('dateFrom', today()->subYear()->toDateString())
        ->set('dateUntil', today()->subYear()->toDateString())
        ->assertDontSeeHtml('renome-finance-donut')
        ->assertSee('არჩეულ პერიოდში მონაცემები არ არის.');
});

test('doctor statistics aggregate unique patients categories and expandable details', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Test', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Test', 'last_name' => 'Patient']);
    $treatment = TreatmentCase::create(['name' => 'Implant', 'category' => 'surgery', 'default_price' => 200, 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->id,
        'doctor_id' => $doctor->id,
        'visit_date' => today(),
        'visit_type' => 'treatment',
        'total_price' => 200,
        'currency' => 'GEL',
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->id,
        'quantity' => 2,
        'unit_price' => 100,
        'currency' => 'GEL',
    ]);

    $secondDoctor = Doctor::create(['first_name' => 'Second', 'last_name' => 'Doctor', 'is_active' => true]);
    $secondPatient = Patient::create(['first_name' => 'Second', 'last_name' => 'Patient']);
    $secondVisit = Visit::create([
        'patient_id' => $secondPatient->id,
        'doctor_id' => $secondDoctor->id,
        'visit_date' => today(),
        'visit_type' => 'treatment',
        'total_price' => 150,
        'currency' => 'GEL',
    ]);
    $secondVisit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->id,
        'quantity' => 1,
        'unit_price' => 150,
        'currency' => 'GEL',
    ]);

    Livewire::test(FinanceReports::class)
        ->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', fn (array $stats): bool => $stats['totalPatients'] === 2
            && $stats['totalRevenue'] === 350.0
            && $stats['categories'][0]['procedures'] === 3
            && $stats['doctors'][0]['id'] === $doctor->id)
        ->call('toggleDoctor', $doctor->id)
        ->assertSee('Test Doctor')
        ->assertSee('Implant')
        ->call('toggleDoctor', $secondDoctor->id)
        ->assertSet('selectedDoctorId', $secondDoctor->id)
        ->assertSee('Second Doctor')
        ->call('selectSectionTab', 'dynamics')
        ->assertSet('sectionTab', 'dynamics')
        ->assertSet('selectedDoctorId', null)
        ->call('selectSectionTab', 'finance')
        ->assertSet('sectionTab', 'finance')
        ->assertSee('მიმდინარე ქეში')
        ->set('dateFrom', today()->subDay()->toDateString())
        ->set('dateUntil', today()->toDateString())
        ->call('selectSectionTab', 'doctors')
        ->call('toggleDoctor', $doctor->id)
        ->assertSet('selectedDoctorId', $doctor->id)
        ->set('currency', 'USD')
        ->assertSet('selectedDoctorId', null)
        ->assertSee('არჩეულ პერიოდში ექიმების მონაცემები არ არის.')
        ->set('currency', 'GEL')
        ->assertSee('Test Doctor')
        ->call('toggleDoctor', $secondDoctor->id)
        ->assertSet('selectedDoctorId', $secondDoctor->id)
        ->call('selectSectionTab', 'dynamics')
        ->call('selectSectionTab', 'finance')
        ->call('selectSectionTab', 'doctors')
        ->assertSet('sectionTab', 'doctors')
        ->assertSet('selectedDoctorId', null)
        ->assertSee('Test Doctor')
        ->assertSee('Second Doctor');
});
