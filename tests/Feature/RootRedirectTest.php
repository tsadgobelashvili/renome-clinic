<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('root sends guests to panel login and direct admin access still requires login', function () {
    $this->get('/')->assertRedirect('/login');
    $this->get('/login')->assertOk();
    $this->get('/visits')->assertRedirect('/login');
});

test('root reuses the panel role specific home URL', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => true]));
    $destination = $role === User::ROLE_LAB_TECHNICIAN
        ? LabCaseResource::getUrl()
        : Dashboard::getUrl();

    if ($role === User::ROLE_LAB_TECHNICIAN) {
        $this->get('/')->assertRedirect($destination);
    } else {
        $this->get('/')->assertOk();
    }
    expect(filament()->getPanel('admin')->getHomeUrl())->toBe($destination);
    $this->get($destination)->assertOk();
})->with([User::ROLE_OWNER, User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('root redirect does not grant inactive users access', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN, 'is_active' => false]));
    $this->get('/')->assertForbidden();
    $this->get(LabCaseResource::getUrl())->assertForbidden();
});

test('legacy admin bookmarks redirect to clean URLs preserving deep links and query strings', function () {
    $this->get('/admin')->assertRedirect(url('/'));
    $this->get('/admin/login')->assertRedirect('/login');
    $this->get('/admin/visits/123/edit?return=dashboard')->assertRedirect('/visits/123/edit?return=dashboard');
    $this->get('/admin/lab-cases')->assertRedirect('/lab-cases');
});

test('panel logout at root clears the authenticated session', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    $this->post(route('filament.admin.auth.logout'))->assertRedirect('/login');
    $this->assertGuest();
});
