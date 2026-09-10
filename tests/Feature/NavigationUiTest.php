<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\LabTechnicians\LabTechnicianResource;
use App\Filament\Resources\PartnerFinance\PartnerFinanceResource;
use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
use App\Models\User;
use Filament\Enums\UserMenuPosition;
use Filament\Support\Icons\Heroicon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

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
        ->assertSee('data-group-label="ლაბორატორია"', false)
        ->assertSee('data-group-label="ადმინისტრირება"', false)
        ->assertSee('x-collapse.duration.200ms', false)
        ->assertSee('fi-user-menu-trigger-text', false)->assertSee('admin');
    $sidebar = filament()->getNavigation();
    $clinic = collect($sidebar)->first(fn ($group) => $group->getLabel() === 'კლინიკა');
    expect($clinic->getIcon())->toBe(Heroicon::OutlinedBuildingOffice2)
        ->and(collect($clinic->getItems())->map(fn ($item) => $item->getIcon())->all())
        ->toContain('renome-doctor', Heroicon::OutlinedCalendarDays);
    $lab = collect($sidebar)->first(fn ($group) => $group->getLabel() === 'ლაბორატორია');
    expect($lab->isCollapsible())->toBeTrue()
        ->and(collect($lab->getItems())->map(fn ($item) => $item->getLabel())->values()->all())->toBe(['სამუშაო', 'ტექნიკები'])
        ->and(LabTechnicianResource::getNavigationLabel())->toBe('ტექნიკები')
        ->and(collect($lab->getItems())->filter(fn ($item) => $item->isActive())->count())->toBe(1);
});

test('technician sidebar retains access restrictions after regrouping', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN, 'locale' => 'ka']));
    $this->get(LabCaseResource::getUrl())->assertOk()
        ->assertSee('data-group-label="ლაბორატორია"', false)
        ->assertDontSee('data-group-label="ადმინისტრირება"', false);
    $this->get(PartnerFinanceResource::getUrl())->assertForbidden();
});
