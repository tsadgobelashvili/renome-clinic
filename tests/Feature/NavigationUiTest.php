<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\LabTechnicians\LabTechnicianResource;
use App\Filament\Resources\PartnerFinance\PartnerFinanceResource;
use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
use App\Filament\Support\PersonnelNavigation;
use App\Models\User;
use Filament\Enums\UserMenuPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('laboratory uses unbranded full width layout with native desktop sidebar controls', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    $panel = filament()->getPanel('admin');

    expect($panel->getBrandName())->toBe('')
        ->and($panel->isSidebarFullyCollapsibleOnDesktop())->toBeTrue()
        ->and($panel->getMaxContentWidth())->toBe(\Filament\Support\Enums\Width::Full);

    $this->get(LabCaseResource::getUrl())->assertOk()
        ->assertSee('fi-body-has-sidebar-fully-collapsible-on-desktop', false)
        ->assertSee('fi-topbar-open-sidebar-btn', false)
        ->assertSee('fi-topbar-close-collapse-sidebar-btn', false)
        ->assertSee('fi-width-full', false)
        ->assertSee('x-on:click="$store.sidebar.open()"', false)
        ->assertSee('x-on:click="$store.sidebar.close()"', false)
        ->assertDontSee('Laravel');
});

test('Personnel reuses the three existing management pages with compact role filtered tabs', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    foreach (PersonnelNavigation::RESOURCES as $resource) {
        $this->get($resource::getUrl())->assertOk()->assertSee('data-personnel-navigation', false)
            ->assertSee(DoctorResource::getUrl(), false)
            ->assertSee(EmployeeResource::getUrl(), false)
            ->assertSee(LabTechnicianResource::getUrl(), false);
    }
    expect(PersonnelNavigation::tabs())->toHaveCount(3)
        ->and(DoctorResource::shouldRegisterNavigation())->toBeFalse()
        ->and(LabTechnicianResource::shouldRegisterNavigation())->toBeFalse()
        ->and(EmployeeResource::shouldRegisterNavigation())->toBeFalse();
    $items = collect(filament()->getNavigation())->flatMap(fn ($group) => $group->getItems());
    $personnel = $items->first(fn ($item) => $item->getLabel() === __('personnel.title'));
    expect($personnel->getGroup())->toBe('ადმინისტრირება')->and($personnel->isActive())->toBeTrue();
});

test('administrator Personnel contains only Doctors and does not grant employee or lab access', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    expect(array_column(PersonnelNavigation::tabs(), 'resource'))->toBe([DoctorResource::class]);
    $this->get(DoctorResource::getUrl())->assertOk()->assertSee('data-personnel-navigation', false);
    $this->get(EmployeeResource::getUrl())->assertForbidden();
    $this->get(LabTechnicianResource::getUrl())->assertForbidden();
    $this->get(LabCaseResource::getUrl())->assertForbidden();
});

test('erp page chrome hides global breadcrumbs and repeated page headings', function () {
    $theme = file_get_contents(resource_path('css/filament/admin/theme.css'));

    expect(filament()->getPanel('admin')->hasBreadcrumbs())->toBeFalse()
        ->and($theme)->toContain('.fi-page > .fi-page-header-main-ctn')
        ->and($theme)->toContain('.fi-header .fi-header-heading')
        ->and($theme)->toContain('padding-block: 0;');
});

test('sidebar uses the native profile footer menu', function () {
    $panel = filament()->getPanel('admin');

    expect($panel->hasProfile())->toBeTrue()
        ->and($panel->getUserMenuPosition())->toBe(UserMenuPosition::Sidebar);
});

test('renamed sidebar labels keep their existing destinations', function () {
    expect(Dashboard::getNavigationLabel())->toBe('მთავარი')
        ->and(DoctorResource::getNavigationLabel())->toBe('ექიმები')
        ->and(PartnerFinanceResource::getNavigationLabel())->toBe('ფინანსები')
        ->and(PartnerPatientResource::getNavigationLabel())->toBe('პაციენტები')
        ->and(parse_url(Dashboard::getUrl(), PHP_URL_PATH))->toBe('/admin')
        ->and(parse_url(DoctorResource::getUrl('index'), PHP_URL_PATH))->toBe('/admin/doctors')
        ->and(parse_url(PartnerFinanceResource::getUrl('index'), PHP_URL_PATH))->toBe('/admin/partner-finance/partner-finances')
        ->and(parse_url(PartnerPatientResource::getUrl('index'), PHP_URL_PATH))->toBe('/admin/partner-patients');
});

test('sidebar renders accordion groups with child icons and route based active lab state', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'name' => 'admin', 'locale' => 'ka']));
    $response = $this->get(LabCaseResource::getUrl());
    $response->assertOk()->assertSee('data-group-label="კლინიკა"', false)
        ->assertSee('data-group-label="ისრაელი"', false)
        ->assertDontSee('data-group-label="ლაბორატორია"', false)
        ->assertSee('data-group-label="ადმინისტრირება"', false)
        ->assertSee('x-collapse.duration.200ms', false)
        ->assertSee('fi-user-menu-trigger-text', false)->assertSee('admin');
    $sidebar = filament()->getNavigation();
    $clinic = collect($sidebar)->first(fn ($group) => $group->getLabel() === 'კლინიკა');
    expect($clinic->getIcon())->toBe(Heroicon::OutlinedBuildingOffice2)
        ->and(collect($clinic->getItems())->map(fn ($item) => $item->getIcon())->all())
        ->toContain(Heroicon::OutlinedCalendarDays)->not->toContain('renome-doctor');
    $items = collect($sidebar)->flatMap(fn ($group) => $group->getItems());
    $lab = $items->first(fn ($item) => $item->getLabel() === __('lab.navigation.group'));
    expect($lab->getUrl())->toBe(LabCaseResource::getUrl())
        ->and($lab->getChildItems())->toBeEmpty()->and($lab->isActive())->toBeTrue()
        ->and($items->map(fn ($item) => $item->getLabel()))->not->toContain(__('lab.navigation.cases'), DoctorResource::getNavigationLabel(), LabTechnicianResource::getNavigationLabel());
});

test('technician sidebar retains access restrictions after regrouping', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN, 'locale' => 'ka']));
    $this->get(LabCaseResource::getUrl())->assertOk()
        ->assertSee(LabCaseResource::getUrl(), false)
        ->assertDontSee('data-group-label="ლაბორატორია"', false)
        ->assertDontSee('data-group-label="ადმინისტრირება"', false);
    $this->get(PartnerFinanceResource::getUrl())->assertForbidden();
    $this->get(DoctorResource::getUrl())->assertForbidden();
    expect(PersonnelNavigation::tabs())->toBeEmpty();
});
