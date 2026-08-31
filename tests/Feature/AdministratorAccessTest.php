<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DoctorCompensation;
use App\Filament\Pages\Finance;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\Purchases\PurchaseResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\UserResource;
use App\Models\Doctor;
use App\Models\SalarySettlement;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function roleUser(string $role, bool $active = true): User
{
    return User::factory()->create(['role' => $role, 'is_active' => $active]);
}

test('active role users can log in while inactive users cannot access the panel', function () {
    $panel = Filament::getPanel('admin');

    expect(roleUser(User::ROLE_OWNER)->canAccessPanel($panel))->toBeTrue()
        ->and(roleUser(User::ROLE_ADMINISTRATOR)->canAccessPanel($panel))->toBeTrue()
        ->and(roleUser(User::ROLE_LAB_TECHNICIAN)->canAccessPanel($panel))->toBeTrue()
        ->and(roleUser(User::ROLE_ADMINISTRATOR, false)->canAccessPanel($panel))->toBeFalse();
});

test('owner can create a separate administrator login', function () {
    $owner = roleUser(User::ROLE_OWNER);

    Livewire::actingAs($owner)->test(CreateUser::class)
        ->fillForm([
            'name' => 'Clinic Administrator',
            'email' => 'administrator@example.com',
            'password' => 'secure-password',
            'role' => User::ROLE_ADMINISTRATOR,
            'locale' => 'ka',
            'is_active' => true,
        ])->call('create')->assertHasNoFormErrors();

    $administrator = User::query()->where('email', 'administrator@example.com')->firstOrFail();
    expect($administrator->isAdministrator())->toBeTrue()
        ->and($administrator->is_active)->toBeTrue()
        ->and($administrator->password)->not->toBe('secure-password');
});

test('administrator keeps operational access but owner-only modules stay hidden', function () {
    $administrator = roleUser(User::ROLE_ADMINISTRATOR);
    $this->actingAs($administrator);

    expect(Dashboard::canAccess())->toBeTrue()
        ->and(DoctorCompensation::canAccess())->toBeTrue()
        ->and(Finance::canAccess())->toBeFalse()
        ->and(LabCaseResource::canViewAny())->toBeFalse()
        ->and(PurchaseResource::canViewAny())->toBeFalse()
        ->and(UserResource::canViewAny())->toBeFalse();

    $this->get('/admin')->assertOk();
    $this->get('/admin/visits')->assertOk();
    $this->get('/admin/patients')->assertOk();
    $this->get('/admin/doctors')->assertOk();
    $this->get('/admin/cashbox')->assertOk();
    $this->get('/admin/doctor-compensation')->assertOk();
    $this->get('/admin/finance')->assertForbidden();
    $this->get('/admin/lab-cases')->assertForbidden();
    $this->get('/admin/purchases')->assertForbidden();
    $this->get('/admin/users')->assertForbidden();
});

test('administrator can calculate salary but cannot see historical salary amounts', function () {
    $doctor = Doctor::create(['first_name' => 'Private', 'last_name' => 'Salary', 'compensation_percentage' => 30, 'is_active' => true]);
    SalarySettlement::create([
        'doctor_id' => $doctor->id,
        'period_start' => today()->subMonth(),
        'period_end' => today()->subMonth(),
        'settled_at' => now(),
        'currency' => 'GEL',
        'performed_total' => 999999.99,
        'paid_amount' => 999999.99,
        'outstanding_amount' => 0,
        'direct_expense_total' => 0,
        'base_total' => 999999.99,
        'percentage' => 30,
        'normal_salary_total' => 299999.99,
        'owner_split_received_total' => 0,
        'salary_total' => 299999.99,
        'status' => 'confirmed',
        'patient_group_slug' => 'clinic',
    ]);

    $administrator = roleUser(User::ROLE_ADMINISTRATOR);
    Livewire::actingAs($administrator)->test(DoctorCompensation::class)
        ->set('doctorId', $doctor->id)
        ->assertSuccessful()
        ->assertDontSee('299,999.99');
    Livewire::actingAs($administrator)->test(ViewDoctor::class, ['record' => $doctor->id])
        ->assertSee('ხელფასის დათვლა')
        ->assertDontSee('ხელფასების ისტორია')
        ->assertDontSee('299,999.99');

    $owner = roleUser(User::ROLE_OWNER);
    Livewire::actingAs($owner)->test(DoctorCompensation::class)
        ->set('doctorId', $doctor->id)
        ->assertSee('299,999.99');
});
