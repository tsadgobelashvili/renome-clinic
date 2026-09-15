<?php

use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Models\LabCase;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('active lab technician login redirects to Laboratory even with a forbidden intended URL', function ($intended) {
    $user = User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN, 'is_active' => true, 'password' => 'test-password']);
    expect($user->canAccessPanel(filament()->getPanel('admin')))->toBeTrue();
    if ($intended) {
        $this->withSession(['url.intended' => url($intended)]);
    }
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'test-password'])
        ->call('authenticate')->assertHasNoFormErrors()->assertRedirect(LabCaseResource::getUrl());
    $this->assertAuthenticatedAs($user);
    $this->get(LabCaseResource::getUrl())->assertOk();
    expect(filament()->getPanel('admin')->getHomeUrl())->toBe(LabCaseResource::getUrl());
    $items = collect(filament()->getNavigation())->flatMap(fn ($group) => $group->getItems());
    expect($items)->toHaveCount(1)->and($items->first()->getUrl())->toBe(LabCaseResource::getUrl());
})->with([null, '/', '/finance']);

test('Lab Technician direct URLs deny every other registered resource and page', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    foreach (filament()->getPanel('admin')->getResources() as $resource) {
        if ($resource === LabCaseResource::class || ! isset($resource::getPages()['index'])) {
            continue;
        }
        $this->get($resource::getUrl('index'))->assertForbidden();
    }
    foreach (filament()->getPanel('admin')->getPages() as $page) {
        if ($page === \App\Filament\Pages\Dashboard::class) {
            $this->get($page::getUrl())->assertRedirect(LabCaseResource::getUrl());
            continue;
        }
        $this->get($page::getUrl())->assertForbidden();
    }
});

test('shared Lab Technician account can create view and edit its lab work', function () {
    $user = User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]);
    $this->actingAs($user);
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'clinic', 'case_date' => today()->toDateString(),
        'mainWorks' => [['patient_search' => 'Shared Lab Patient', 'material' => 'pmma', 'quantity' => 1]],
    ])->callMountedAction()->assertHasNoActionErrors();
    $case = LabCase::sole();
    expect($case->created_by)->toBe($user->id);
    $this->get(LabCaseResource::getUrl('edit', ['record' => $case]))->assertOk();
    Livewire::test(ListLabCases::class)->assertCanSeeTableRecords([$case]);
    Livewire::test(EditLabCase::class, ['record' => $case->id])->fillForm(['notes' => 'Shared lab update'])
        ->call('save')->assertHasNoFormErrors();
    expect($case->fresh()->notes)->toBe('Shared lab update')->and(LabCaseResource::canDelete($case))->toBeFalse();
    // Signing in from another computer uses this same account and case visibility.
    auth()->logout();
    $this->actingAs($user->fresh());
    $this->get(LabCaseResource::getUrl('edit', ['record' => $case]))->assertOk();
});

test('inactive Lab Technician cannot log in or keep panel access', function () {
    $user = User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN, 'is_active' => false, 'password' => 'test-password']);
    expect($user->canAccessPanel(filament()->getPanel('admin')))->toBeFalse();
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'test-password'])
        ->call('authenticate')->assertHasFormErrors(['email']);
    $this->assertGuest();
    $this->actingAs($user)->get(LabCaseResource::getUrl())->assertForbidden();
});

test('laboratory Livewire requests work while active and are blocked after deactivation', function () {
    $user = User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]);
    $this->actingAs($user);
    $html = $this->get(LabCaseResource::getUrl())->assertOk()->getContent();
    preg_match('/wire:snapshot="([^"]+)"/', $html, $matches);
    expect($matches)->not->toBeEmpty();
    $snapshot = html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $payload = ['components' => [['snapshot' => $snapshot, 'updates' => [], 'calls' => []]]];
    $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertOk();
    // HTTP tests share one application; clear Livewire's per-request middleware memo.
    Livewire::flushState();
    $user->update(['is_active' => false]);
    $this->actingAs($user->fresh());
    $this->postJson(Livewire::getUpdateUri(), $payload, ['X-Livewire' => 'true'])->assertForbidden();
});

test('Owner and Admin login retain their existing landing page and access', function ($role) {
    $user = User::factory()->create(['role' => $role, 'password' => 'test-password']);
    Livewire::test(Login::class)->fillForm(['email' => $user->email, 'password' => 'test-password'])
        ->call('authenticate')->assertHasNoFormErrors()->assertRedirect(url('/'));
    $this->get('/')->assertOk();
    $this->get(LabCaseResource::getUrl())->assertStatus($role === User::ROLE_OWNER ? 200 : 403);
})->with([User::ROLE_OWNER, User::ROLE_ADMINISTRATOR]);
