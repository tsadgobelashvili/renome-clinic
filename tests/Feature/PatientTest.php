<?php

use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\EditPatient;
use App\Filament\Resources\Patients\Pages\ListPatients;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\PatientDoctorAssignment;
use App\Support\Currency;
use Filament\Actions\Testing\TestAction;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('patient group migration backfills existing patients into clinic', function () {
    $migration = require database_path('migrations/2026_08_27_090000_create_patient_groups_and_assign_patients.php');
    $migration->down();

    $patientId = DB::table('patients')->insertGetId([
        'patient_number' => 900001,
        'first_name' => 'Legacy',
        'last_name' => 'Patient',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $migration->up();

    $clinicId = DB::table('patient_groups')->where('slug', PatientGroup::CLINIC_SLUG)->value('id');

    expect(DB::table('patients')->where('id', $patientId)->value('patient_group_id'))
        ->toBe($clinicId)
        ->and(collect(Schema::getIndexes('patients'))->contains(
            fn (array $index): bool => $index['columns'] === ['patient_group_id'],
        ))->toBeTrue();
});

test('patients default to clinic and may be classified in another active group', function () {
    $clinic = PatientGroup::query()->where('slug', PatientGroup::CLINIC_SLUG)->sole();
    $partner = PatientGroup::query()->where('slug', PatientGroup::ISRAEL_PARTNER_SLUG)->sole();

    $clinicPatient = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Patient']);
    $partnerPatient = Patient::create([
        'first_name' => 'Partner',
        'last_name' => 'Patient',
        'patient_group_id' => $partner->getKey(),
    ]);

    expect($clinicPatient->patient_group_id)->toBe($clinic->getKey())
        ->and($clinicPatient->patientGroup->is($clinic))->toBeTrue()
        ->and($partnerPatient->patientGroup->is($partner))->toBeTrue();
});

test('patient list filters records by patient group', function () {
    $this->actingAs(User::factory()->create());
    $clinic = PatientGroup::query()->where('slug', PatientGroup::CLINIC_SLUG)->sole();
    $partner = PatientGroup::query()->where('slug', PatientGroup::ISRAEL_PARTNER_SLUG)->sole();
    $clinicPatient = Patient::create(['first_name' => 'Clinic', 'last_name' => 'Listed']);
    $partnerPatient = Patient::create([
        'first_name' => 'Partner',
        'last_name' => 'Listed',
        'patient_group_id' => $partner->getKey(),
    ]);

    Livewire::test(ListPatients::class)
        ->assertCanSeeTableRecords([$clinicPatient, $partnerPatient])
        ->filterTable('patient_group_id', $partner->getKey())
        ->searchTable('Partner')
        ->assertCanSeeTableRecords([$partnerPatient])
        ->assertCanNotSeeTableRecords([$clinicPatient])
        ->searchTable(null)
        ->filterTable('patient_group_id', $clinic->getKey())
        ->assertCanSeeTableRecords([$clinicPatient])
        ->assertCanNotSeeTableRecords([$partnerPatient]);
});

test('patient phone is required for clinic and optional for israel partner', function () {
    $this->actingAs(User::factory()->create());
    $clinic = PatientGroup::query()->where('slug', PatientGroup::CLINIC_SLUG)->sole();
    $partner = PatientGroup::query()->where('slug', PatientGroup::ISRAEL_PARTNER_SLUG)->sole();

    Livewire::test(CreatePatient::class)
        ->fillForm([
            'first_name' => 'Missing',
            'last_name' => 'Clinic Phone',
            'patient_group_id' => $clinic->getKey(),
            'phone' => null,
        ])
        ->call('create')
        ->assertHasFormErrors(['phone' => 'required']);

    Livewire::test(CreatePatient::class)
        ->fillForm([
            'first_name' => 'Partner',
            'last_name' => 'Without Phone',
            'patient_group_id' => $partner->getKey(),
            'phone' => null,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Patient::query()->where('last_name', 'Without Phone')->sole()->phone)->toBeNull();
});

test('patient can change group and birth date while inactive group assignments remain stored', function () {
    $this->actingAs(User::factory()->create());
    $partner = PatientGroup::query()->where('slug', PatientGroup::ISRAEL_PARTNER_SLUG)->sole();
    $patient = Patient::create([
        'first_name' => 'Editable',
        'last_name' => 'Patient',
        'phone' => '555123123',
    ]);

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->fillForm([
            'patient_group_id' => $partner->getKey(),
            'birth_date' => '1990-04-15',
            'phone' => null,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $partner->update(['is_active' => false]);
    $patient->refresh();

    Livewire::test(EditPatient::class, ['record' => $patient->getRouteKey()])
        ->fillForm(['last_name' => 'Still Linked'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($patient->fresh()->patientGroup->is($partner))->toBeTrue()
        ->and($patient->birth_date->toDateString())->toBe('1990-04-15');
});

test('patient list and profile display birth date and assigned doctors', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create([
        'first_name' => 'Identified',
        'last_name' => 'Patient',
        'phone' => '555456456',
        'birth_date' => '1985-06-20',
    ]);
    $doctor = Doctor::create([
        'first_name' => 'Assigned',
        'last_name' => 'Doctor',
        'is_active' => true,
    ]);
    $patient->doctors()->attach($doctor, [
        'role' => 'ექიმი',
        'is_primary' => true,
        'assignment_source' => 'manual',
    ]);

    Livewire::test(ListPatients::class)
        ->assertSee('20.06.1985')
        ->assertSee($doctor->full_name)
        ->assertDontSee('პირადი ნომერი');

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->assertSee('20.06.1985');
});

test('patients receive permanent sequential numbers and can be searched by them', function () {
    $first = Patient::create(['first_name' => 'First', 'last_name' => 'Numbered']);
    $second = Patient::create(['first_name' => 'Second', 'last_name' => 'Numbered']);

    expect($first->patient_number)->toBe(1)
        ->and($second->patient_number)->toBe(2)
        ->and($first->formatted_patient_number)->toBe('№ 000001')
        ->and(Patient::query()->searchForClinic('000001')->sole()->is($first))->toBeTrue()
        ->and(Patient::query()->searchForClinic('№ 000002')->sole()->is($second))->toBeTrue()
        ->and((int) DB::table('patient_number_counters')->value('next_number'))->toBe(3);
});

test('patient number is unique and immutable after assignment', function () {
    $first = Patient::create(['first_name' => 'Immutable', 'last_name' => 'One']);
    $second = Patient::create(['first_name' => 'Immutable', 'last_name' => 'Two']);

    expect(function () use ($first): void {
        $first->patient_number = 99;
        $first->save();
    })
        ->toThrow(ValidationException::class, 'პაციენტის ნომრის შეცვლა შეუძლებელია.')
        ->and(fn () => DB::table('patients')->where('id', $second->getKey())->update([
            'patient_number' => 1,
        ]))->toThrow(QueryException::class)
        ->and($first->fresh()->patient_number)->toBe(1)
        ->and($second->fresh()->patient_number)->toBe(2);
});

test('patient number migration backfills existing patients by created date then id', function () {
    $clinicId = PatientGroup::clinicId();
    $migration = require database_path('migrations/2026_08_25_140000_add_patient_number_to_patients_table.php');
    $migration->down();

    DB::table('patients')->insert([
        ['first_name' => 'Newest', 'last_name' => 'Patient', 'patient_group_id' => $clinicId, 'created_at' => '2026-01-02 09:00:00', 'updated_at' => now()],
        ['first_name' => 'Oldest', 'last_name' => 'Patient', 'patient_group_id' => $clinicId, 'created_at' => '2026-01-01 09:00:00', 'updated_at' => now()],
        ['first_name' => 'Same time', 'last_name' => 'Patient', 'patient_group_id' => $clinicId, 'created_at' => '2026-01-02 09:00:00', 'updated_at' => now()],
    ]);

    $migration->up();

    expect(DB::table('patients')->orderBy('patient_number')->pluck('first_name')->all())
        ->toBe(['Oldest', 'Newest', 'Same time'])
        ->and(DB::table('patients')->pluck('patient_number')->unique())->toHaveCount(3)
        ->and(Patient::create(['first_name' => 'After', 'last_name' => 'Backfill'])->patient_number)->toBe(4);
});

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

    Livewire::test(ListPatients::class)
        ->assertOk()
        ->assertTableColumnDoesNotExist('patient_number')
        ->assertTableColumnDoesNotExist('personal_id')
        ->assertTableActionDoesNotExist('view')
        ->assertTableActionDoesNotExist('edit');
    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->assertOk()
        ->assertSee($patient->formatted_patient_number);
});

test('patient list filters debt latest visit and doctor with query level financial totals', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create(['first_name' => 'Filter', 'last_name' => 'Doctor', 'is_active' => true]);
    $otherDoctor = Doctor::create(['first_name' => 'Other', 'last_name' => 'Doctor', 'is_active' => true]);
    $debtPatient = Patient::create(['first_name' => 'Debt', 'last_name' => 'Patient']);
    $clearPatient = Patient::create(['first_name' => 'Clear', 'last_name' => 'Patient']);
    $oldPatient = Patient::create(['first_name' => 'Old', 'last_name' => 'Patient']);

    $debtVisit = Visit::create([
        'patient_id' => $debtPatient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'total_price' => 1000,
        'discount_type' => 'amount',
        'discount_value' => 100,
        'currency' => 'GEL',
    ]);
    $debtVisit->payments()->create([
        'amount' => 400,
        'currency' => 'GEL',
        'payment_date' => today(),
        'payment_method' => 'cash',
    ]);
    $clearVisit = Visit::create([
        'patient_id' => $clearPatient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today()->subDays(8),
        'total_price' => 500,
        'currency' => 'GEL',
    ]);
    $clearVisit->payments()->create([
        'amount' => 500,
        'currency' => 'GEL',
        'payment_date' => today()->subDays(8),
        'payment_method' => 'card',
    ]);
    Visit::create([
        'patient_id' => $oldPatient->getKey(),
        'doctor_id' => $otherDoctor->getKey(),
        'visit_date' => today()->subMonths(2),
        'total_price' => 300,
        'currency' => 'USD',
    ]);

    expect(Patient::query()->whereHasClinicDebt()->pluck('id')->all())
        ->toContain($debtPatient->getKey(), $oldPatient->getKey())
        ->not->toContain($clearPatient->getKey())
        ->and(Patient::query()->whereHasClinicDebt(false)->pluck('id')->all())
        ->toContain($clearPatient->getKey())
        ->and(Patient::query()->whereLatestVisitBetween(
            today()->subDays(6)->toDateString(),
            today()->toDateString(),
        )->pluck('id')->all())->toBe([$debtPatient->getKey()])
        ->and(Patient::query()->whereKey([$debtPatient->getKey(), $oldPatient->getKey()])
            ->orderByClinicDebt('desc')->pluck('id')->all())
        ->toBe([$debtPatient->getKey(), $oldPatient->getKey()])
        ->and(Patient::query()->whereKey([
            $debtPatient->getKey(),
            $clearPatient->getKey(),
            $oldPatient->getKey(),
        ])->orderByLatestVisit('desc')->pluck('id')->all())
        ->toBe([$debtPatient->getKey(), $clearPatient->getKey(), $oldPatient->getKey()]);

    $listPage = Livewire::test(ListPatients::class)
        ->assertTableActionExists('quickDebtFilter')
        ->assertTableActionExists('createVisit')
        ->assertSee(Currency::format(500, 'GEL'))
        ->assertSee('Paid')
        ->filterTable('financial_status', 'debt')
        ->assertCanSeeTableRecords([$debtPatient, $oldPatient])
        ->assertCanNotSeeTableRecords([$clearPatient])
        ->resetTableFilters()
        ->filterTable('last_visit', '7_days')
        ->assertCanSeeTableRecords([$debtPatient])
        ->assertCanNotSeeTableRecords([$clearPatient, $oldPatient])
        ->resetTableFilters()
        ->filterTable('doctor_id', $otherDoctor->getKey())
        ->assertCanSeeTableRecords([$oldPatient])
        ->assertCanNotSeeTableRecords([$debtPatient, $clearPatient]);

    expect($listPage->instance()->getTable()->getRecordUrl($debtPatient))
        ->toBe(PatientResource::getUrl('view', ['record' => $debtPatient]));

    Livewire::test(ListPatients::class)
        ->call('toggleDebtFilter')
        ->assertSet('tableFilters.financial_status.value', 'debt')
        ->assertCanSeeTableRecords([$debtPatient, $oldPatient])
        ->assertCanNotSeeTableRecords([$clearPatient]);
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

test('a patient can be assigned multiple existing doctors', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Multi', 'last_name' => 'Doctor']);
    $surgeon = Doctor::create([
        'first_name' => 'დავით',
        'last_name' => 'ჭუმბურიძე',
        'specialty' => 'ქირურგი',
        'is_active' => true,
    ]);
    $therapist = Doctor::create([
        'first_name' => 'ქეთი',
        'last_name' => 'კუხიანიძე',
        'specialty' => 'თერაპევტი',
        'is_active' => true,
    ]);

    $patient->doctors()->attach($surgeon, [
        'is_primary' => true,
        'role' => $surgeon->specialty ?: 'ექიმი',
        'assignment_source' => 'manual',
    ]);

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->assertSee('ქირურგი — დავით ჭუმბურიძე')
        ->callAction(TestAction::make('attachDoctor')->schemaComponent(), [
            'doctor_id' => $therapist->getKey(),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified('ექიმი პაციენტს დაემატა.');

    expect($patient->doctors()->count())->toBe(2)
        ->and($patient->doctors()->whereKey($therapist)->exists())->toBeTrue()
        ->and($surgeon->patients()->whereKey($patient)->exists())->toBeTrue();
});

test('visit manipulations automatically assign the doctor role without duplicates', function () {
    $patient = Patient::create(['first_name' => 'Automatic', 'last_name' => 'Patient']);
    $doctor = Doctor::create([
        'first_name' => 'Therapy',
        'last_name' => 'Doctor',
        'specialty' => 'სტომატოლოგი',
        'is_active' => true,
    ]);
    $therapy = TreatmentCase::create([
        'name' => 'Therapy service',
        'category' => 'therapy',
        'is_active' => true,
    ]);

    foreach (range(1, 2) as $index) {
        $visit = Visit::create([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_date' => today()->addDays($index),
            'currency' => 'GEL',
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $therapy->getKey(),
            'quantity' => 1,
            'unit_price' => 100,
        ]);
    }

    $relation = DB::table('patient_doctor')->where([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
    ])->sole();

    expect(DB::table('patient_doctor')->where('patient_id', $patient->getKey())->count())->toBe(1)
        ->and($relation->role)->toBe('თერაპევტი')
        ->and($relation->assignment_source)->toBe('auto');
});

test('different visit specialties and doctors add separate patient doctor relations', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Multiple', 'last_name' => 'Specialties']);
    $therapist = Doctor::create(['first_name' => 'Therapy', 'last_name' => 'Doctor', 'is_active' => true]);
    $orthopedist = Doctor::create(['first_name' => 'Ortho', 'last_name' => 'Doctor', 'is_active' => true]);
    $therapy = TreatmentCase::create(['name' => 'Therapy', 'category' => 'therapy', 'is_active' => true]);
    $orthopedics = TreatmentCase::create(['name' => 'Ortho', 'category' => 'orthopedics', 'is_active' => true]);

    foreach ([[$therapist, $therapy], [$orthopedist, $orthopedics]] as [$doctor, $service]) {
        $visit = Visit::create([
            'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
            'visit_date' => today(), 'currency' => 'GEL',
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->getKey(), 'quantity' => 1, 'unit_price' => 100,
        ]);
    }

    expect($patient->doctors()->count())->toBe(2)
        ->and($patient->doctors()->whereKey($therapist)->sole()->pivot->role)->toBe('თერაპევტი')
        ->and($patient->doctors()->whereKey($orthopedist)->sole()->pivot->role)->toBe('ორთოპედი');

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->assertSee('თერაპევტი — '.$therapist->full_name)
        ->assertSee('ორთოპედი — '.$orthopedist->full_name);
});

test('the same doctor keeps multiple roles and patient profile groups them into one row', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Multi', 'last_name' => 'Role']);
    $doctor = Doctor::create(['first_name' => 'Levan', 'last_name' => 'Doctor', 'is_active' => true]);
    $orthopedics = TreatmentCase::create(['name' => 'Crown', 'category' => 'orthopedics', 'is_active' => true]);
    $implant = TreatmentCase::create(['name' => 'Dental implant', 'category' => 'surgery', 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
        'visit_date' => today(), 'currency' => 'GEL',
    ]);

    foreach ([$orthopedics, $implant] as $service) {
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->getKey(), 'quantity' => 1, 'unit_price' => 100,
        ]);
    }

    $roles = DB::table('patient_doctor')
        ->where('patient_id', $patient->getKey())
        ->where('doctor_id', $doctor->getKey())
        ->orderBy('id')
        ->pluck('role')
        ->all();

    expect($roles)->toBe(['ორთოპედი', 'იმპლანტოლოგი']);

    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->assertSee('ორთოპედი, იმპლანტოლოგი — '.$doctor->full_name);
});

test('patient doctor backfill restores every role from visit history', function () {
    $patient = Patient::create(['first_name' => 'Historical', 'last_name' => 'Roles']);
    $doctor = Doctor::create(['first_name' => 'History', 'last_name' => 'Doctor', 'is_active' => true]);

    foreach ([
        TreatmentCase::create(['name' => 'Ortho', 'category' => 'orthopedics', 'is_active' => true]),
        TreatmentCase::create(['name' => 'Implant procedure', 'category' => 'surgery', 'is_active' => true]),
    ] as $index => $service) {
        $visit = Visit::create([
            'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
            'visit_date' => today()->addDays($index), 'currency' => 'GEL',
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->getKey(), 'quantity' => 1, 'unit_price' => 100,
        ]);
    }

    DB::table('patient_doctor')->where('patient_id', $patient->getKey())->delete();
    app(PatientDoctorAssignment::class)->backfillExistingVisits();
    app(PatientDoctorAssignment::class)->backfillExistingVisits();

    expect(DB::table('patient_doctor')->where('patient_id', $patient->getKey())->pluck('role')->sort()->values()->all())
        ->toBe(collect(['ორთოპედი', 'იმპლანტოლოგი'])->sort()->values()->all());
});

test('patient doctor backfill is idempotent and visit without doctor remains valid', function () {
    $patient = Patient::create(['first_name' => 'Backfill', 'last_name' => 'Patient']);
    $doctor = Doctor::create([
        'first_name' => 'Implant', 'last_name' => 'Doctor',
        'specialty' => 'იმპლანტოლოგი', 'is_active' => true,
    ]);
    $surgery = TreatmentCase::create(['name' => 'Implant', 'category' => 'surgery', 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
        'visit_date' => today(), 'currency' => 'GEL',
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $surgery->getKey(), 'quantity' => 1, 'unit_price' => 500,
    ]);
    DB::table('patient_doctor')->where('patient_id', $patient->getKey())->delete();

    $assignment = app(PatientDoctorAssignment::class);
    $assignment->backfillExistingVisits();
    $assignment->backfillExistingVisits();
    $doctorless = Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => null,
        'visit_date' => today(), 'currency' => 'GEL',
    ]);

    expect($doctorless->exists)->toBeTrue()
        ->and($patient->doctors()->count())->toBe(1)
        ->and($patient->doctors()->sole()->pivot->role)->toBe('იმპლანტოლოგი');
});

test('changing visit doctor preserves the old history and assigns the new doctor', function () {
    $patient = Patient::create(['first_name' => 'Changed', 'last_name' => 'Doctor']);
    $oldDoctor = Doctor::create(['first_name' => 'Old', 'last_name' => 'Doctor', 'specialty' => 'თერაპევტი', 'is_active' => true]);
    $newDoctor = Doctor::create(['first_name' => 'New', 'last_name' => 'Doctor', 'specialty' => 'ორთოპედი', 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $oldDoctor->getKey(),
        'visit_date' => today(), 'currency' => 'GEL',
    ]);
    $therapy = TreatmentCase::create(['name' => 'Therapy', 'category' => 'therapy', 'is_active' => true]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $therapy->getKey(), 'quantity' => 1, 'unit_price' => 100,
    ]);

    $visit->update(['doctor_id' => $newDoctor->getKey()]);

    expect($patient->doctors()->pluck('doctors.id')->all())
        ->toContain($oldDoctor->getKey(), $newDoctor->getKey());
});
