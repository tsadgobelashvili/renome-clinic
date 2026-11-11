<?php

use App\Filament\Pages;
use App\Filament\Resources\DirectExpenses\DirectExpenseResource;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\LabTechnicians\LabTechnicianResource;
use App\Filament\Resources\PartnerFinance\PartnerFinanceResource;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.locale' => 'ka']);
    app()->setLocale('ka');
    Http::preventStrayRequests();
});

function phaseOneNavigationMap(): array
{
    return collect(filament()->getNavigation())->mapWithKeys(fn ($group) => [
        $group->getLabel() => collect($group->getItems())->map(fn ($item) => $item->getLabel())->all(),
    ])->all();
}

test('the owner has seven ordered workspaces with each authorized destination exactly once', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    $this->get(LabCaseResource::getUrl())->assertOk();

    expect(phaseOneNavigationMap())->toBe([
        '' => ['მთავარი'],
        'კლინიკა' => ['პაციენტები', 'ისრაელის პაციენტები', 'ვიზიტები'],
        'ლაბორატორია' => ['სამუშაოები', 'გარე შეკვეთები'],
        'HR / პერსონალი' => ['ექიმები', 'თანამშრომლები', 'ტექნიკოსები', 'პოზიციები', 'ხელფასები'],
        'ფინანსები' => ['საერთო', 'ისრაელი', 'სალარო', 'ბანკი', 'შესყიდვები'],
        'ანალიტიკა' => ['სტატისტიკა'],
        'ადმინისტრირება' => ['მომხმარებლები', 'მომსახურებების კატალოგი', 'ხარჯების კატეგორიები', 'პროდუქტები'],
    ]);
    $items = collect(filament()->getNavigation())->flatMap(fn ($group) => $group->getItems());
    expect($items->map(fn ($item) => $item->getUrl())->unique())->toHaveCount(21);
    foreach ($items as $item) {
        expect($item->getChildItems())->toBeEmpty();
    }
});

test('administrator navigation preserves operational access and hides owner-only destinations', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR, 'locale' => 'ka']));
    $this->get(DoctorResource::getUrl())->assertOk();
    expect(phaseOneNavigationMap())->toBe([
        '' => ['მთავარი'],
        'კლინიკა' => ['პაციენტები', 'ისრაელის პაციენტები', 'ვიზიტები'],
        'HR / პერსონალი' => ['ექიმები', 'ხელფასები'],
        'ფინანსები' => ['სალარო'],
        'ადმინისტრირება' => ['მომსახურებების კატალოგი'],
    ]);
    foreach ([PartnerFinanceResource::getUrl(), Pages\Finance::getUrl(), Pages\Bank::getUrl(),
        Pages\TechnicianSalaries::getUrl(), EmployeeResource::getUrl(), LabTechnicianResource::getUrl()] as $url) {
        $this->get($url)->assertForbidden();
    }
    $this->get(Pages\DoctorCompensation::getUrl())->assertOk();
});

test('lab technicians retain only the shared laboratory work entry and existing landing redirect', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN, 'locale' => 'ka']));
    $this->get('/')->assertRedirect(LabCaseResource::getUrl());
    $this->get(LabCaseResource::getUrl())->assertOk();
    expect(phaseOneNavigationMap())->toBe(['ლაბორატორია' => ['სამუშაოები']]);
    foreach ([Pages\ExternalLabOrders::getUrl(), Pages\DoctorCompensation::getUrl(),
        Pages\TechnicianSalaries::getUrl(), PartnerFinanceResource::getUrl(), DoctorResource::getUrl(),
        EmployeeResource::getUrl(), LabTechnicianResource::getUrl()] as $url) {
        $this->get($url)->assertForbidden();
    }
});

test('regrouped destinations use native route-based active states', function (string $destination) {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    $this->get($destination::getUrl())->assertOk();
    $items = collect(filament()->getNavigation())->flatMap(fn ($group) => $group->getItems());
    $active = $items->filter(fn ($item) => $item->isActive());
    expect($active)->toHaveCount(1)
        ->and($active->first()->getUrl())->toBe($destination === Pages\TechnicianSalaries::class
            ? Pages\DoctorCompensation::getUrl() : $destination::getUrl());
})->with([
    DoctorResource::class, EmployeeResource::class, LabTechnicianResource::class,
    Pages\ExternalLabOrders::class, Pages\DoctorCompensation::class,
    Pages\TechnicianSalaries::class, PartnerFinanceResource::class, Pages\FinanceReports::class,
]);

test('doctor profile and edit bookmarks retain their route and HR navigation context', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    $doctor = Doctor::create(['first_name' => 'Navigation', 'last_name' => 'Doctor']);
    foreach (['view', 'edit'] as $page) {
        $url = DoctorResource::getUrl($page, ['record' => $doctor]);
        expect(parse_url($url, PHP_URL_PATH))->toBe('/doctors/navigation-doctor-'.$doctor->id.($page === 'edit' ? '/edit' : ''));
        $this->get($url)->assertOk();
        $active = collect(filament()->getNavigation())->flatMap(fn ($group) => $group->getItems())
            ->filter(fn ($item) => $item->isActive());
        expect($active)->toHaveCount(1)->and($active->first()->getGroup())->toBe('HR / პერსონალი');
    }
});

test('hidden direct expenses keeps its original URL and role-based direct access', function (string $role, bool $active, int $status) {
    $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $active, 'locale' => 'ka']));

    expect(DirectExpenseResource::shouldRegisterNavigation())->toBeFalse()
        ->and(parse_url(DirectExpenseResource::getUrl(), PHP_URL_PATH))->toBe('/direct-expenses');
    $this->get(DirectExpenseResource::getUrl())->assertStatus($status);
    $urls = collect(filament()->getNavigation())->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getUrl());
    expect($urls)->not->toContain(DirectExpenseResource::getUrl());
})->with([
    [User::ROLE_OWNER, true, 200],
    [User::ROLE_ADMINISTRATOR, true, 403],
    [User::ROLE_LAB_TECHNICIAN, true, 403],
    [User::ROLE_OWNER, false, 403],
]);

test('existing resource index bookmarks are unchanged', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $paths = [
        'DirectExpenses\\DirectExpenseResource' => '/direct-expenses',
        'Doctors\\DoctorResource' => '/doctors',
        'EmployeeAdvances\\EmployeeAdvanceResource' => '/employee-advances',
        'EmployeePositions\\EmployeePositionResource' => '/employee-positions',
        'Employees\\EmployeeResource' => '/employees',
        'LabCases\\LabCaseResource' => '/lab-cases',
        'LabTechnicianRates\\LabTechnicianRateResource' => '/lab-technician-rates',
        'LabTechnicians\\LabTechnicianResource' => '/lab-technicians',
        'PartnerFinance\\PartnerFinanceResource' => '/partner-finance/partner-finances',
        'PartnerPatients\\PartnerPatientResource' => '/partner-patients',
        'Patients\\PatientResource' => '/patients',
        'ProductMaterials\\ProductMaterialResource' => '/product-materials',
        'Purchases\\PurchaseResource' => '/purchases',
        'TreatmentCases\\TreatmentCaseResource' => '/treatment-cases',
        'TreatmentEstimates\\TreatmentEstimateResource' => '/treatment-estimates',
        'Users\\UserResource' => '/users',
        'Visits\\VisitResource' => '/visits',
    ];
    foreach ($paths as $resource => $path) {
        $class = 'App\\Filament\\Resources\\'.$resource;
        expect(parse_url($class::getUrl(), PHP_URL_PATH))->toBe($path);
    }
});

test('hidden legacy reports and settings keep their URLs and owner access', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    foreach ([
        Pages\BankRules::class => '/bank-rules',
        Pages\BankCategories::class => '/bank-categories',
        Pages\FinanceOpeningBalances::class => '/finance-opening-balances',
        Pages\FullDiscountStatistics::class => '/full-discount-statistics',
        Pages\ProfitLoss::class => '/profit-loss',
        Pages\LabSalaries::class => '/lab-salaries',
    ] as $page => $path) {
        expect($page::shouldRegisterNavigation())->toBeFalse()
            ->and(parse_url($page::getUrl(), PHP_URL_PATH))->toBe($path);
        $this->get($page::getUrl())->assertOk();
    }
    $this->get(Pages\BogTransactions::getUrl())->assertRedirect(Pages\Bank::getUrl());
    $this->get('/admin/doctors')->assertRedirect(url('/doctors'));
});

test('Israeli Finance retains an independent destination and employee payroll retains its existing selector', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
    $this->get(PartnerFinanceResource::getUrl())->assertOk();
    $finance = collect(filament()->getNavigation())->first(fn ($group) => $group->getLabel() === 'ფინანსები');
    $urls = collect($finance->getItems())->mapWithKeys(fn ($item) => [$item->getLabel() => $item->getUrl()]);
    expect($urls['საერთო'])->toBe(Pages\Finance::getUrl())
        ->and($urls['ისრაელი'])->toBe(PartnerFinanceResource::getUrl())
        ->and($urls['ისრაელი'])->not->toBe($urls['საერთო']);
    Livewire::test(Pages\DoctorCompensation::class)
        ->set('staffTypeFilter', 'employees')->assertOk()->assertSet('staffTypeFilter', 'employees');
});
