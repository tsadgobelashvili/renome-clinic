<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\DoctorCompensation;
use App\Filament\Pages\Finance;
use App\Filament\Pages\TechnicianSalaries;
use App\Filament\Support\SalaryNavigation;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\Patient;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()->setLocale('ka');
    Http::preventStrayRequests();
});

function activeHrSalaryTab(string $html): array
{
    $document = new DOMDocument;
    $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $nodes = (new DOMXPath($document))->query('//*[@data-salary-navigation]//*[@data-salary-tab and @aria-current]');

    return array_map(fn ($node) => $node->getAttribute('data-salary-tab'), iterator_to_array($nodes));
}

test('dashboard is a single ungrouped direct navigation destination', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    $this->get(Dashboard::getUrl())->assertOk()->assertDontSee('data-group-label="მთავარი"', false);
    $groups = collect(filament()->getNavigation());
    $items = $groups->flatMap(fn ($group) => $group->getItems());

    expect(Dashboard::getNavigationGroup())->toBeNull()
        ->and($groups->first()->getLabel())->toBeNull()
        ->and($groups->first()->getItems())->toHaveCount(1)
        ->and($items->filter(fn ($item) => $item->getUrl() === Dashboard::getUrl()))->toHaveCount(1);
});

test('salary tabs respect existing page permissions', function (string $role, array $modes) {
    $this->actingAs(User::factory()->create(['role' => $role, 'locale' => 'ka']));
    expect(array_column(SalaryNavigation::tabs(), 'mode'))->toBe($modes);

    if ($modes === []) {
        $this->get(DoctorCompensation::getUrl())->assertForbidden();
    } else {
        $response = $this->get(DoctorCompensation::getUrl())->assertOk();
        foreach ($modes as $mode) {
            $response->assertSee('data-salary-tab="'.$mode.'"', false);
        }
    }

    if (! in_array('technicians', $modes, true)) {
        $this->get(TechnicianSalaries::getUrl())->assertForbidden();
        if ($modes !== []) {
            $response->assertDontSee('data-salary-tab="technicians"', false);
        }
    }
})->with([
    [User::ROLE_OWNER, ['doctors', 'employees', 'technicians']],
    [User::ROLE_ADMINISTRATOR, ['doctors', 'employees']],
    [User::ROLE_LAB_TECHNICIAN, []],
]);

test('salary modes open through the existing route and retain their selected tab', function (string $mode) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    $parameters = $mode === 'employees' ? ['staff' => $mode] : [];
    $response = $this->get(DoctorCompensation::getUrl($parameters))->assertOk();
    expect(activeHrSalaryTab($response->getContent()))->toBe([$mode]);

    $component = Livewire::withQueryParams($parameters)->test(DoctorCompensation::class)
        ->assertSet('staffTypeFilter', $mode);
    expect(activeHrSalaryTab($component->html()))->toBe([$mode]);
    if ($mode === 'employees') {
        $component->assertSee('data-employee-payment-day-filter', false);
    } else {
        $component->assertDontSee('data-employee-payment-day-filter', false);
    }
})->with(['doctors', 'employees']);

test('technician tab reuses its original page and links to the correct employee mode', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    $response = $this->get(TechnicianSalaries::getUrl())->assertOk()
        ->assertSee(DoctorCompensation::getUrl(['staff' => 'employees']), false);

    expect(activeHrSalaryTab($response->getContent()))->toBe(['technicians'])
        ->and(TechnicianSalaries::shouldRegisterNavigation())->toBeFalse()
        ->and(parse_url(TechnicianSalaries::getUrl(), PHP_URL_PATH))->toBe('/technician-salaries');

    Livewire::test(TechnicianSalaries::class)->set('ready', true)->assertSet('ready', true);
});

test('changing salary mode preserves payroll selection filters and all financial records', function () {
    $this->travelTo(now()->setDate(2026, 9, 14)->setTime(12, 0));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    $doctor = Doctor::create(['first_name' => 'Salary', 'last_name' => 'Doctor', 'is_active' => true, 'compensation_percentage' => 40]);
    $patient = Patient::create(['first_name' => 'Salary', 'last_name' => 'Patient']);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'currency' => 'GEL', 'total_price' => 1000]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Salary work', 'quantity' => 1, 'unit_price' => 1000]);
    $visit->payments()->create(['amount' => 1000, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);
    $employee = Employee::create(['first_name' => 'Salary', 'last_name' => 'Employee', 'is_active' => true,
        'position_id' => EmployeePosition::where('name', 'Administrator')->sole()->id, 'salary_payout_day' => 16]);
    $employee->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'fixed_net',
        'currency' => 'GEL', 'net_amount' => 1000, 'default_payment_method' => 'bank_transfer', 'effective_from' => '2026-09-01', 'is_active' => true]);

    $snapshot = fn () => collect(['payments', 'finance_transactions', 'cashbox_transactions', 'salary_settlements',
        'employee_salary_settlements', 'payroll_entries', 'clinic_payroll_cycles'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    $before = $snapshot();
    $page = Livewire::test(DoctorCompensation::class)->mountAction('clinicPayroll');
    $selection = $page->get('selectedClinicPayrollRows');
    $review = $page->get('clinicPayrollReview');
    expect($selection)->not->toBeEmpty();
    $page->call('unmountAction')->set('employeePaymentDayFilter', '16')
        ->set('staffTypeFilter', 'employees')->assertSee('data-employee-payment-day-filter', false)
        ->assertSet('selectedClinicPayrollRows', $selection)->assertSet('clinicPayrollReview', $review)
        ->set('staffTypeFilter', 'doctors')->set('staffTypeFilter', 'employees')
        ->assertSet('employeePaymentDayFilter', '16')->assertSet('selectedClinicPayrollRows', $selection);

    expect(activeHrSalaryTab($page->html()))->toBe(['employees'])
        ->and($snapshot())->toBe($before);
});

test('HR job positions remain separate from application roles and technician classification', function () {
    $positions = EmployeePosition::whereIn('name', ['Administrator', 'Assistant', 'Designer', 'Manager', 'Technician'])
        ->orderBy('id')->get();
    $before = $positions->toArray();
    expect($positions)->toHaveCount(5);
    $user = User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]);
    $employee = Employee::create(['first_name' => 'Linked', 'last_name' => 'Technician', 'is_active' => true,
        'position_id' => $positions->firstWhere('name', 'Technician')->id, 'user_id' => $user->id]);
    $this->actingAs($user);

    expect(User::ROLES)->toBe(['owner', 'administrator', 'lab_technician'])
        ->and($user->fresh()->role)->toBe(User::ROLE_ADMINISTRATOR)
        ->and(Employee::activeTechnicians()->whereKey($employee)->exists())->toBeTrue();
    $this->get(DoctorCompensation::getUrl(['staff' => 'employees']))->assertOk();
    $this->get(TechnicianSalaries::getUrl())->assertForbidden();
    $this->get(Finance::getUrl())->assertForbidden();
    expect(EmployeePosition::whereKey($positions->modelKeys())->orderBy('id')->get()->toArray())->toBe($before);
});
