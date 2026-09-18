<?php

use App\Filament\Pages\Cashbox;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\PartnerFinance\PartnerFinanceResource;
use App\Models\ClinicPayrollCycle;
use App\Models\User;
use App\Services\DoctorSalaryHistory;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
});

test('panel and operational pages explicitly recognize active roles only', function (string $role, bool $active, bool $panelAllowed, bool $clinicAllowed) {
    $user = User::factory()->create(['role' => $role, 'is_active' => $active]);
    $this->actingAs($user);

    expect($user->canAccessPanel(filament()->getPanel('admin')))->toBe($panelAllowed)
        ->and(Dashboard::canAccess())->toBe($clinicAllowed)
        ->and(Cashbox::canAccess())->toBe($clinicAllowed);

    if ($clinicAllowed) {
        $this->get('/')->assertOk();
        $this->get('/cashbox')->assertOk();
    } elseif ($active && $role === User::ROLE_LAB_TECHNICIAN) {
        $this->get('/')->assertRedirect(LabCaseResource::getUrl());
        $this->get(LabCaseResource::getUrl())->assertOk();
        $this->get('/cashbox')->assertForbidden();
    } else {
        $this->get('/')->assertForbidden();
        $this->get('/cashbox')->assertForbidden();
    }
})->with([
    'owner' => [User::ROLE_OWNER, true, true, true],
    'administrator' => [User::ROLE_ADMINISTRATOR, true, true, true],
    'lab' => [User::ROLE_LAB_TECHNICIAN, true, true, false],
    'unknown' => ['unknown', true, false, false],
    'mistyped' => ['Owner', true, false, false],
    'empty' => ['', true, false, false],
    'inactive owner' => [User::ROLE_OWNER, false, false, false],
    'inactive administrator' => [User::ROLE_ADMINISTRATOR, false, false, false],
    'inactive lab' => [User::ROLE_LAB_TECHNICIAN, false, false, false],
]);

test('unknown role cannot sign in to the panel', function () {
    $user = User::factory()->create(['role' => 'unknown', 'password' => 'test-password']);
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'test-password'])
        ->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest();
});

test('financial direct URLs remain owner only', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    foreach ([
        '/bank', '/bog-transactions', '/finance', '/finance-reports', '/profit-loss',
        '/purchases', '/purchases/items', '/purchases/create', '/product-materials',
        PartnerFinanceResource::getUrl(), '/expense-categories', '/bank-categories',
        '/bank-rules', '/finance-opening-balances', '/lab-salaries', '/users',
        '/full-discount-statistics',
    ] as $url) {
        $response = $this->get($url);
        if ($role === User::ROLE_OWNER) {
            // The legacy BOG review page intentionally redirects to Bank.
            $url === '/bog-transactions' ? $response->assertRedirect() : $response->assertOk();
        } else {
            $response->assertForbidden();
        }
    }
})->with([User::ROLE_OWNER, User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('operational actions reject revoked roles on hydration', function (string $role, bool $active) {
    $owner = User::factory()->create();
    $denied = User::factory()->create(['role' => $role, 'is_active' => $active]);
    foreach ([Dashboard::class, Cashbox::class] as $page) {
        $component = Livewire::actingAs($owner)->test($page);
        if ($page === Dashboard::class) {
            $component->mountAction('cashboxOverview');
        }
        $action = $page === Dashboard::class ? 'dashboardExpense' : 'expense';
        $component->mountAction($action)->assertActionMounted($action);
        Livewire::actingAs($denied);
        $component->callMountedAction()->assertForbidden();
    }
})->with([
    ['unknown', true], [User::ROLE_LAB_TECHNICIAN, true], [User::ROLE_OWNER, false],
]);

function authorizationPayrollHistory(): ClinicPayrollCycle
{
    return ClinicPayrollCycle::create([
        'payroll_date' => today()->subMonth(), 'status' => 'finalized',
        'snapshot' => [
            'payroll_date' => today()->subMonth()->toDateString(),
            'doctor_settlement_ids' => [], 'doctors' => [], 'employees' => [],
            'doctor_totals' => ['GEL' => 87654.32], 'employee_totals' => [], 'totals' => ['GEL' => 87654.32],
        ],
    ]);
}

test('owner can review finalized payroll while administrator keeps only current review', function () {
    $cycle = authorizationPayrollHistory();
    Livewire::actingAs(User::factory()->create())->test(DoctorCompensation::class)
        ->mountAction('clinicPayroll', ['cycle' => $cycle->id])
        ->assertActionMounted('clinicPayroll')->assertMountedActionModalSee('87,654.32');

    $admin = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    Livewire::actingAs($admin)->test(DoctorCompensation::class)
        ->assertDontSee(__('clinic-payroll.last_finalized'))
        ->mountAction('clinicPayroll')->assertActionMounted('clinicPayroll')
        ->assertMountedActionModalDontSee('87,654.32');
    Livewire::actingAs($admin)->test(DoctorCompensation::class)
        ->mountAction('clinicPayroll', ['cycle' => $cycle->id])->assertForbidden();
});

test('an already open finalized review rejects an owner downgraded to administrator', function () {
    $cycle = authorizationPayrollHistory();
    $component = Livewire::actingAs(User::factory()->create())->test(DoctorCompensation::class)
        ->mountAction('clinicPayroll', ['cycle' => $cycle->id]);
    Livewire::actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $component->call('$refresh')->assertForbidden();
});

test('forged doctor history visibility cannot query salary history for administrator', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    $page = new DoctorCompensation;
    $page->activeSalaryDoctorId = 123;
    $page->salaryHistoryDoctorId = 123;
    $this->mock(DoctorSalaryHistory::class)->shouldNotReceive('forDoctor');

    expect($page->isDoctorSalaryHistoryVisible(123))->toBeFalse()
        ->and($page->doctorSalaryHistory(123))->toBeEmpty();
    Livewire::test(DoctorCompensation::class)->call('toggleDoctorSalaryHistory', 123)->assertForbidden();
});
