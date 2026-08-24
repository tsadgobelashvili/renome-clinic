<?php

use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('patient search supports names in both orders phone and personal id', function () {
    $patient = Patient::create([
        'first_name' => 'გიორგი',
        'last_name' => 'ბერიძე',
        'phone' => '555123456',
        'personal_id' => '01010112345',
    ]);

    foreach (['გიორგი', 'ბერიძე', 'გიორგი ბერიძე', 'ბერიძე გიორგი', '555123456', '01010112345'] as $search) {
        expect(Patient::query()->searchForClinic($search)->sole()->is($patient))->toBeTrue();
    }
});

test('patient personal id is unique while phone may be shared', function () {
    $existing = Patient::create([
        'first_name' => 'First',
        'last_name' => 'Patient',
        'phone' => '555000000',
        'personal_id' => '01010112345',
    ]);

    expect(fn () => $existing->update(['last_name' => 'Updated']))
        ->not->toThrow(ValidationException::class)
        ->and(fn () => Patient::create([
            'first_name' => 'Second',
            'last_name' => 'Patient',
            'phone' => '555000000',
        ]))->not->toThrow(ValidationException::class)
        ->and(fn () => Patient::create([
            'first_name' => 'Duplicate',
            'last_name' => 'Patient',
            'personal_id' => '01010112345',
        ]))->toThrow(ValidationException::class, 'ამ პირადი ნომრით პაციენტი უკვე არსებობს.');
});

test('patient payments are available through existing visits', function () {
    $patient = Patient::create(['first_name' => 'Payment', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Test', 'last_name' => 'Doctor', 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => now()->toDateString(),
        'total_price' => 100,
    ]);
    $payment = $visit->payments()->create([
        'amount' => 50,
        'payment_date' => now()->toDateString(),
        'payment_method' => 'cash',
    ]);

    expect($patient->payments()->sole()->is($payment))->toBeTrue();
});

test('patient list and profile render for patients with and without activity', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create([
        'first_name' => 'Profile',
        'last_name' => 'Patient',
        'phone' => '555123123',
    ]);

    Livewire::test(ListPatients::class)->assertOk();
    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])->assertOk();
});

test('doctor and patient view pages use full names as titles without breadcrumbs', function () {
    $this->actingAs(User::factory()->create());

    $doctor = Doctor::create([
        'first_name' => 'შალვა',
        'last_name' => 'ბერულაშვილი',
        'is_active' => true,
    ]);
    $patient = Patient::create([
        'first_name' => 'თემურ',
        'last_name' => 'დადეშქელიანი',
    ]);

    $doctorPage = Livewire::test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])->assertOk();
    $patientPage = Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])->assertOk();

    expect($doctorPage->instance()->getTitle())->toBe('შალვა ბერულაშვილი')
        ->and($doctorPage->instance()->getBreadcrumbs())->toBe([])
        ->and($patientPage->instance()->getTitle())->toBe('თემურ დადეშქელიანი')
        ->and($patientPage->instance()->getBreadcrumbs())->toBe([]);
});
