<?php

use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Doctors\Pages\CreateDoctor;
use App\Filament\Resources\Doctors\Pages\EditDoctor;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// Quiet creation below represents legacy invalid-role fixtures; application creation now rejects them.

function protectedDoctorProfile(): Doctor
{
    return Doctor::create([
        'first_name' => 'Original', 'last_name' => 'Doctor',
        'specialties' => ['surgery', 'orthopedics'],
        'compensation_category_percentages' => ['surgery' => 30, 'orthopedics' => 40],
        'compensation_percentage' => 30,
        'israeli_lab_zircon_rate' => 100, 'israeli_lab_pmma_rate' => 25,
        'clinic_salary_payment_method' => 'bank_transfer', 'owner_split_enabled' => true,
    ]);
}

function forgedDoctorCompensation(): array
{
    return [
        'compensation_category_percentages' => ['surgery' => 99, 'orthopedics' => 98],
        'compensation_percentage' => 99,
        'israeli_lab_zircon_rate' => 999, 'israeli_lab_pmma_rate' => 888,
        'clinic_salary_payment_method' => 'cash',
        'owner_split_enabled' => false, 'owner_split_key' => null,
    ];
}

test('administrator operational save ignores forged compensation state', function () {
    $doctor = protectedDoctorProfile();
    $before = $doctor->getAttributes();
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $page = Livewire::test(EditDoctor::class, ['record' => $doctor->id])
        ->assertFormFieldDisabled('israeli_lab_zircon_rate')
        ->assertFormFieldDisabled('israeli_lab_pmma_rate')
        ->assertFormFieldDisabled('compensation_category_percentages.surgery')
        ->assertFormFieldDisabled('clinic_salary_payment_method')
        ->assertFormFieldDisabled('owner_split_enabled');
    foreach (forgedDoctorCompensation() as $field => $value) {
        $page->set('data.'.$field, $value);
    }
    $page->set('data.first_name', 'Updated')->set('data.phone', '555123456')
        ->set('data.specialties', ['surgery', 'orthopedics', 'therapy'])
        ->call('save')->assertHasNoFormErrors()->assertRedirect(DoctorResource::getUrl('index'));
    $doctor->refresh();
    expect($doctor->first_name)->toBe('Updated')->and($doctor->phone)->toBe('555123456')
        ->and($doctor->specialties)->toContain('therapy');
    foreach (array_keys(forgedDoctorCompensation()) as $field) {
        if ($field !== 'owner_split_enabled') {
            expect($doctor->getRawOriginal($field))->toBe($before[$field]);
        }
    }
});

test('save hooks strip raw compensation payload independently of disabled form fields', function (string $page, string $hook) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $method = new ReflectionMethod($page, $hook);
    expect($method->invoke(new $page, ['first_name' => 'Allowed', ...forgedDoctorCompensation()]))
        ->toBe(['first_name' => 'Allowed']);
})->with([
    [EditDoctor::class, 'mutateFormDataBeforeSave'],
    [CreateDoctor::class, 'mutateFormDataBeforeCreate'],
]);

test('owner can save all compensation controls', function () {
    $doctor = protectedDoctorProfile();
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    expect(DoctorResource::authorizedProfileData(forgedDoctorCompensation()))->toBe(forgedDoctorCompensation());
    Livewire::test(EditDoctor::class, ['record' => $doctor->id])
        ->fillForm(forgedDoctorCompensation())->call('save')->assertHasNoFormErrors();
    $doctor->refresh();
    expect($doctor->compensation_category_percentages)->toBe(['surgery' => 99, 'orthopedics' => 98])
        ->and($doctor->israeli_lab_zircon_rate)->toBe('999.00')
        ->and($doctor->israeli_lab_pmma_rate)->toBe('888.00')
        ->and($doctor->clinic_salary_payment_method)->toBe('cash')
        ->and($doctor->isOwnerSplitDoctor())->toBeFalse();
});

test('administrator can create operational doctor without configuring compensation', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    Livewire::test(CreateDoctor::class)->fillForm([
        'first_name' => 'New', 'last_name' => 'Doctor', 'specialties' => ['orthopedics'],
        ...forgedDoctorCompensation(),
    ])->call('create')->assertHasNoFormErrors();
    $doctor = Doctor::sole();
    expect($doctor->compensation_category_percentages)->toBeNull()
        ->and($doctor->israeli_lab_zircon_rate)->toBeNull()
        ->and($doctor->israeli_lab_pmma_rate)->toBeNull()
        ->and($doctor->isOwnerSplitDoctor())->toBeFalse()
        ->and($doctor->clinic_salary_payment_method)->toBe('bank_transfer');
});

test('unauthorized roles cannot access doctor editing or compensation', function (string $role, bool $active) {
    $doctor = protectedDoctorProfile();
    $this->actingAs(User::factory()->createQuietly(['role' => $role, 'is_active' => $active]));
    expect(Gate::allows('manageCompensation', Doctor::class))->toBeFalse();
    Livewire::test(EditDoctor::class, ['record' => $doctor->id])->assertForbidden();
})->with([
    [User::ROLE_LAB_TECHNICIAN, true], ['unknown', true], [User::ROLE_OWNER, false],
]);
