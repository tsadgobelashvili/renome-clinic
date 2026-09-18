<?php

use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Doctors\Pages\CreateDoctor;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Models\Doctor;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use App\Services\SalarySettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('doctor profile toggles existing owner split participation independently of names', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'Independent', 'last_name' => 'Profile', 'is_active' => true,
        'specialties' => ['surgery'], 'compensation_category_percentages' => ['surgery' => 30]]);
    $page = Livewire::test(EditDoctor::class, ['record' => $doctor->id])
        ->assertSee('ძირითადი ინფორმაცია')->assertSee('ანაზღაურება')
        ->assertSee('შენახვა')->assertSee('გაუქმება')
        ->assertFormFieldVisible('owner_split_enabled')
        ->assertDontSee('მონაწილე 1')->assertDontSee('მონაწილე 2')
        ->set('data.owner_split_enabled', true)->call('save')->assertHasNoFormErrors()
        ->assertRedirect(DoctorResource::getUrl('index'));
    expect($doctor->fresh()->owner_split_key)->toBe('levan')
        ->and($doctor->fresh()->isOwnerSplitDoctor())->toBeTrue();

    Livewire::test(EditDoctor::class, ['record' => $doctor->id])->assertFormSet(['owner_split_enabled' => true]);
    $other = Doctor::create(['first_name' => 'Other', 'last_name' => 'Profile', 'owner_split_enabled' => true]);
    expect($other->owner_split_key)->toBe('nodar');
    expect(fn () => Doctor::create(['first_name' => 'Third', 'last_name' => 'Profile', 'owner_split_enabled' => true]))
        ->toThrow(ValidationException::class);

    $page->set('data.owner_split_enabled', false)->call('save')->assertHasNoFormErrors();
    expect($doctor->fresh()->isOwnerSplitDoctor())->toBeFalse();
    Livewire::test(EditDoctor::class, ['record' => $doctor->id])->assertFormSet(['owner_split_enabled' => false])
        ->set('data.specialties', ['therapy'])->assertFormFieldHidden('owner_split_enabled')
        ->set('data.specialties', ['orthopedics'])->assertFormFieldHidden('owner_split_enabled')
        ->set('data.specialties', ['surgery'])->assertFormFieldVisible('owner_split_enabled');
});

test('specialty-only configuration calculates and finalizes distinct percentages', function () {
    $doctor = Doctor::create(['first_name' => 'Configured', 'last_name' => 'Doctor', 'specialties' => ['therapy', 'orthopedics'],
        'compensation_category_percentages' => ['therapy' => 37, 'orthopedics' => 48]]);
    $patient = Patient::create(['first_name' => 'Paid', 'last_name' => 'Patient']);
    $visit = Visit::create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 2000]);
    foreach (['therapy', 'orthopedics'] as $category) {
        $work = TreatmentCase::create(['name' => $category, 'category' => $category, 'is_active' => true]);
        $visit->treatmentCaseItems()->create(['treatment_case_id' => $work->id, 'quantity' => 1, 'unit_price' => 1000]);
    }
    $visit->payments()->create(['amount' => 2000, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);
    $calculator = app(DoctorCompensationCalculator::class);
    $report = $calculator->calculate($doctor->id, today()->toDateString(), today()->toDateString());
    expect($report['totals']['GEL']['doctor_share'])->toBe(850.0);
    $settlement = app(SalarySettlementService::class)->settle($doctor->id, today()->toDateString(), today()->toDateString(), (float) $doctor->compensation_percentage, null)[0];
    expect($settlement->items->pluck('salary_percentage_snapshot')->map(fn ($p) => (float) $p)->sort()->values()->all())->toBe([37.0, 48.0]);
});

test('doctor form reacts to selected specialties and persists independently configurable rates', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $page = Livewire::test(CreateDoctor::class)->fillForm(['first_name' => 'Profile', 'last_name' => 'Doctor', 'specialties' => ['therapy']])
        ->assertFormFieldVisible('compensation_category_percentages.therapy')
        ->assertFormFieldHidden('compensation_category_percentages.orthopedics')
        ->assertFormFieldHidden('israeli_lab_zircon_rate')->assertFormFieldHidden('israeli_lab_pmma_rate');
    $page->set('data.specialties', ['therapy', 'orthopedics'])
        ->assertFormFieldVisible('compensation_category_percentages.orthopedics')
        ->assertFormFieldVisible('israeli_lab_zircon_rate')->assertFormFieldVisible('israeli_lab_pmma_rate')
        ->assertFormFieldHidden('compensation_category_percentages.surgery')
        ->fillForm(['compensation_category_percentages' => ['therapy' => 37, 'orthopedics' => 48],
            'israeli_lab_zircon_rate' => 123, 'israeli_lab_pmma_rate' => 31])->call('create')->assertHasNoFormErrors();
    $doctor = Doctor::where('first_name', 'Profile')->sole();
    expect($doctor->specialties)->toBe(['therapy', 'orthopedics'])
        ->and((float) $doctor->compensation_category_percentages['therapy'])->toBe(37.0)
        ->and((float) $doctor->compensation_category_percentages['orthopedics'])->toBe(48.0)
        ->and($doctor->israeli_lab_pmma_rate)->toBe('31.00');
    Livewire::test(EditDoctor::class, ['record' => $doctor->id])->set('data.specialties', ['therapy'])
        ->assertFormFieldHidden('compensation_category_percentages.orthopedics')
        ->assertFormFieldHidden('israeli_lab_zircon_rate')->assertFormFieldHidden('israeli_lab_pmma_rate')
        ->call('save')->assertHasNoFormErrors();
    expect($doctor->fresh()->specialties)->toBe(['therapy'])
        ->and((float) $doctor->fresh()->compensation_category_percentages['orthopedics'])->toBe(48.0);
});

test('Israeli configured unit rates preserve zircon precedence within one case', function (array $materials, float $expected) {
    $doctor = Doctor::create(['first_name' => 'Unrelated', 'last_name' => 'Name', 'specialties' => ['orthopedics'],
        'compensation_category_percentages' => ['orthopedics' => 43], 'israeli_lab_zircon_rate' => 123, 'israeli_lab_pmma_rate' => 31]);
    $patient = Patient::create(['first_name' => 'Unit', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $case = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'source' => 'israeli', 'case_date' => today()]);
    foreach ($materials as $material) {
        $case->mainWorks()->create(['material' => $material, 'quantity' => 2]);
    }
    $calculator = app(DoctorCompensationCalculator::class);
    $report = $calculator->calculate($doctor->id, today()->toDateString(), today()->toDateString(), patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG);
    expect($report['totals']['GEL']['doctor_share'])->toBe($expected)->and($report['details'])->toHaveCount(1);
    $doctor->update(['first_name' => 'Levan', 'last_name' => 'Berikashvili']);
    expect($calculator->calculate($doctor->id, today()->toDateString(), today()->toDateString(), patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG)['totals']['GEL']['doctor_share'])->toBe($expected);
})->with([[['zircon'], 246.0], [['pmma'], 62.0], [['zircon', 'pmma'], 246.0]]);

test('migration preserves existing percentages and snapshots former PMMA rate without name defaults', function () {
    Schema::table('doctors', fn ($table) => $table->dropColumn(['specialties', 'israeli_lab_pmma_rate']));
    $id = DB::table('doctors')->insertGetId(['first_name' => 'Levan', 'last_name' => 'Berikashvili', 'specialty' => 'Therapy / Orthopedics',
        'compensation_percentage' => 37, 'compensation_category_percentages' => json_encode(['therapy' => 42]), 'israeli_lab_zircon_rate' => 117]);
    (require database_path('migrations/2026_09_18_100000_configure_doctor_specialty_and_pmma_rates.php'))->up();
    $doctor = Doctor::findOrFail($id);
    expect($doctor->specialties)->toBe(['therapy', 'orthopedics'])->and($doctor->compensation_percentage)->toBe('37.00')
        ->and($doctor->compensation_category_percentages)->toBe(['therapy' => 42, 'orthopedics' => 37])
        ->and($doctor->israeli_lab_zircon_rate)->toBe('117.00')->and($doctor->israeli_lab_pmma_rate)->toBe('25.00');
});

test('fixed rate Israeli lab work can finalize with zero specialty percentage', function () {
    $doctor = Doctor::create(['first_name' => 'Fixed', 'last_name' => 'Doctor', 'specialties' => ['orthopedics'],
        'compensation_category_percentages' => ['orthopedics' => 0], 'israeli_lab_pmma_rate' => 31]);
    $patient = Patient::create(['first_name' => 'Fixed', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $case = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'source' => 'israeli', 'case_date' => today()]);
    $work = $case->mainWorks()->create(['material' => 'pmma', 'quantity' => 2]);
    $settlement = app(SalarySettlementService::class)->settle($doctor->id, today()->toDateString(), today()->toDateString(), 0, null,
        patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG, selectedLabWorkIds: [$work->id], israeliLabOnly: true, deferIsraeliPayment: true)[0];
    expect((float) $settlement->salary_total)->toBe(62.0)->and((float) $settlement->items->sole()->unit_rate_snapshot)->toBe(31.0);
});
