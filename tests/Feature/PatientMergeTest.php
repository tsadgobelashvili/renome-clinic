<?php

use App\Filament\Resources\Patients\Pages\CreatePatient;
use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentEstimate;
use App\Models\User;
use App\Models\Visit;
use App\Services\PatientDuplicateMatcher;
use App\Services\PatientMergeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('patient creation warns about a possible duplicate without blocking creation', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $existing = Patient::create([
        'first_name' => 'Nino',
        'last_name' => 'Beridze',
        'phone' => '555 12 34 56',
        'birth_date' => '1990-02-03',
    ]);

    $this->actingAs($owner);

    Livewire::test(CreatePatient::class)
        ->fillForm([
            'first_name' => 'Nino',
            'last_name' => 'Beridze',
            'phone' => '555123456',
            'birth_date' => '1990-02-03',
            'patient_group_id' => PatientGroup::clinicId(),
        ])
        ->assertSee('A similar patient may already exist.')
        ->assertSee('Use existing patient')
        ->assertSee('Create anyway')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Patient::query()->where('first_name', 'Nino')->where('last_name', 'Beridze')->count())->toBe(2)
        ->and($existing->fresh())->not->toBeNull();
});

test('duplicate matching uses Georgian transliteration birth date and normalized phone', function () {
    $patient = Patient::create([
        'first_name' => 'ნინო',
        'last_name' => 'ბერიძე',
        'phone' => '+995 (555) 12-34-56',
        'birth_date' => '1990-02-03',
    ]);

    $matches = app(PatientDuplicateMatcher::class)->find([
        'first_name' => 'Nino',
        'last_name' => 'Beridze',
        'phone' => '995555123456',
        'birth_date' => '1990-02-03',
    ]);

    expect($matches->pluck('id')->all())->toContain($patient->getKey());
});

test('Owner can transactionally merge every patient-linked record into the primary patient', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $primary = Patient::create([
        'first_name' => 'Primary',
        'last_name' => 'Patient',
        'notes' => 'Primary note',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $duplicate = Patient::create([
        'first_name' => 'Duplicate',
        'last_name' => 'Patient',
        'phone' => '555111222',
        'birth_date' => '1988-04-05',
        'notes' => 'Duplicate note',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $doctor = Doctor::create(['first_name' => 'Doctor', 'last_name' => 'One']);
    $visit = Visit::create(['patient_id' => $duplicate->getKey(), 'doctor_id' => $doctor->getKey(), 'visit_date' => today()]);
    $labCase = LabCase::create([
        'patient_id' => $duplicate->getKey(),
        'doctor_id' => $doctor->getKey(),
        'case_date' => today(),
        'source' => 'israeli',
    ]);
    $estimate = TreatmentEstimate::create([
        'patient_id' => $duplicate->getKey(),
        'doctor_id' => $doctor->getKey(),
        'estimate_date' => today(),
    ]);

    DB::table('partner_patient_payments')->insert([
        'patient_id' => $duplicate->getKey(), 'amount' => 25, 'currency' => 'USD',
        'payment_method' => 'cash', 'paid_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('product_sales')->insert([
        'sold_at' => now(), 'patient_id' => $duplicate->getKey(), 'visit_id' => $visit->getKey(),
        'total' => 10, 'currency' => 'GEL', 'payment_method' => 'cash', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $cashboxDayId = DB::table('cashbox_days')->insertGetId([
        'date' => today(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('cashbox_transactions')->insert([
        'cashbox_day_id' => $cashboxDayId, 'type' => 'patient_payment', 'amount' => 25,
        'currency' => 'GEL', 'transaction_date' => now(), 'patient_id' => $duplicate->getKey(),
        'visit_id' => $visit->getKey(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ([$primary, $duplicate] as $patient) {
        DB::table('patient_doctor')->insert([
            'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(), 'role' => 'Doctor',
            'is_primary' => $patient->is($duplicate), 'assignment_source' => 'manual',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $result = app(PatientMergeService::class)->merge($primary, $duplicate, $owner);

    expect(Patient::find($duplicate->getKey()))->toBeNull()
        ->and($result->first_name)->toBe('Primary')
        ->and($result->phone)->toBe('555111222')
        ->and($result->birth_date->toDateString())->toBe('1988-04-05')
        ->and($result->notes)->toContain('Primary note', 'Duplicate note')
        ->and($visit->fresh()->patient_id)->toBe($primary->getKey())
        ->and($labCase->fresh()->patient_id)->toBe($primary->getKey())
        ->and($estimate->fresh()->patient_id)->toBe($primary->getKey())
        ->and(DB::table('partner_patient_payments')->where('patient_id', $primary->getKey())->count())->toBe(1)
        ->and(DB::table('product_sales')->where('patient_id', $primary->getKey())->count())->toBe(1)
        ->and(DB::table('cashbox_transactions')->where('patient_id', $primary->getKey())->count())->toBe(1)
        ->and(DB::table('patient_doctor')->where('patient_id', $primary->getKey())->count())->toBe(1)
        ->and((bool) DB::table('patient_doctor')->where('patient_id', $primary->getKey())->value('is_primary'))->toBeTrue()
        ->and(DB::table('patient_merges')->where('duplicate_patient_id', $duplicate->getKey())->count())->toBe(1);
});

test('merge action is limited to Owner and Administrator', function () {
    $patient = Patient::create(['first_name' => 'Primary', 'last_name' => 'Patient']);

    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->assertActionVisible('mergePatient')
        ->mountAction('mergePatient')
        ->assertMountedActionModalSee([
            'Primary patient (will remain)',
            'Duplicate patient (will be removed)',
            'Confirm merge',
        ]);

    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->assertActionHidden('mergePatient');

    expect(fn () => app(PatientMergeService::class)->merge(
        $patient,
        Patient::create(['first_name' => 'Duplicate', 'last_name' => 'Patient']),
        auth()->user(),
    ))->toThrow(AuthorizationException::class);
});
