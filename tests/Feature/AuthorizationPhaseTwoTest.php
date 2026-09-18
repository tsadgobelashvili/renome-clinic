<?php

use App\Filament\Pages;
use App\Filament\Pages\Concerns\AuthorizesPageAccess;
use App\Filament\Resources\LabCases\LabCaseResource;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Filament\Resources\Purchases\Pages\CreatePurchase;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Http\Middleware\AuthorizePanelPage;
use App\Http\Middleware\RestrictLabTechnicianAccess;
use App\Models\FinanceOpeningBalance;
use App\Models\LabCase;
use App\Models\Purchase;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    // Explicit resource/page authorization must stand without the legacy URL rules.
    $this->withoutMiddleware(RestrictLabTechnicianAccess::class);
});

function phaseTwoResourceAllowed(string $resource, string $role, bool $active): bool
{
    if (! $active) {
        return false;
    }

    return match ($role) {
        User::ROLE_OWNER => true,
        User::ROLE_ADMINISTRATOR => in_array(class_basename($resource), [
            'DoctorResource', 'PatientResource', 'PartnerPatientResource',
            'VisitResource', 'TreatmentCaseResource', 'TreatmentEstimateResource',
        ], true),
        User::ROLE_LAB_TECHNICIAN => $resource === LabCaseResource::class,
        default => false,
    };
}

function phaseTwoPageAllowed(string $page, string $role, bool $active): bool
{
    return $active && ($role === User::ROLE_OWNER || ($role === User::ROLE_ADMINISTRATOR
        && in_array($page, [Pages\Dashboard::class, Pages\Cashbox::class, Pages\DoctorCompensation::class], true)));
}

test('all registered resources and pages enforce the role matrix without path middleware', function (string $role, bool $active) {
    $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $active]));
    $panel = Filament::getPanel('admin');
    expect($panel->getResources())->toHaveCount(16)->and($panel->getPages())->toHaveCount(15);

    foreach ($panel->getResources() as $resource) {
        $allowed = phaseTwoResourceAllowed($resource, $role, $active);
        expect(Gate::getPolicyFor($resource::getModel()))->not->toBeNull();
        expect($resource::canAccess())->toBe($allowed, $resource);
        $response = $this->get($resource::getUrl());
        $allowed ? $response->assertOk() : $response->assertForbidden();
        if (isset($resource::getPages()['create'])) {
            $response = $this->get($resource::getUrl('create'));
            $allowed && $resource::canCreate() ? $response->assertOk() : $response->assertForbidden();
        }
    }

    foreach ($panel->getPages() as $page) {
        $allowed = phaseTwoPageAllowed($page, $role, $active);
        expect($page::canAccess())->toBe($allowed, $page);
        $response = $this->get($page::getUrl());
        if ($allowed && $page === Pages\BogTransactions::class) {
            $response->assertRedirect(Pages\Bank::getUrl());
        } else {
            $allowed ? $response->assertOk() : $response->assertForbidden();
        }
    }
})->with([
    [User::ROLE_OWNER, true], [User::ROLE_ADMINISTRATOR, true], [User::ROLE_LAB_TECHNICIAN, true],
    ['unknown', true], [User::ROLE_OWNER, false], [User::ROLE_ADMINISTRATOR, false], [User::ROLE_LAB_TECHNICIAN, false],
]);

test('sensitive Livewire components deny hydration after owner permissions are revoked', function (string $role, bool $active) {
    $owner = User::factory()->create();
    $revoked = User::factory()->create(['role' => $role, 'is_active' => $active]);
    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        if (phaseTwoResourceAllowed($resource, $role, $active)) {
            continue;
        }
        $component = Livewire::actingAs($owner)->test($resource::getPages()['index']->getPage());
        Livewire::actingAs($revoked);
        $component->call('$refresh')->assertForbidden();
    }
    foreach (Filament::getPanel('admin')->getPages() as $page) {
        if ($page === Pages\BogTransactions::class || phaseTwoPageAllowed($page, $role, $active)) {
            continue;
        }
        $component = Livewire::actingAs($owner)->test($page);
        Livewire::actingAs($revoked);
        $component->call('$refresh')->assertForbidden();
    }
})->with([[User::ROLE_ADMINISTRATOR, true], [User::ROLE_LAB_TECHNICIAN, true], ['unknown', true], [User::ROLE_OWNER, false]]);

test('lab shared queue policy allows other creators but denies destructive abilities', function () {
    $owner = User::factory()->create();
    $lab = User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]);
    Livewire::actingAs($owner)->test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'clinic', 'case_date' => today()->toDateString(),
        'mainWorks' => [['patient_search' => 'Historical Lab Patient', 'material' => 'pmma', 'quantity' => 1]],
    ])->callMountedAction()->assertHasNoActionErrors();
    $case = LabCase::sole();

    Livewire::actingAs($lab)->test(ListLabCases::class)->assertCanSeeTableRecords([$case]);
    expect(Gate::allows('view', $case))->toBeTrue()
        ->and(Gate::allows('update', $case))->toBeTrue()
        ->and(Gate::allows('delete', $case))->toBeFalse()
        ->and(Gate::allows('deleteAny', LabCase::class))->toBeFalse()
        ->and(Gate::allows('forceDeleteAny', LabCase::class))->toBeFalse();
    $this->get(LabCaseResource::getUrl('edit', ['record' => $case]))->assertOk();
    $this->get('/profile')->assertOk();
    $this->post('/logout')->assertRedirect();
    $this->assertGuest();
});

test('sensitive actions reject a revoked role on the same logged in account', function () {
    $owner = User::factory()->create();
    $bank = Livewire::actingAs($owner)->test(Pages\Bank::class);
    $opening = Livewire::test(Pages\FinanceOpeningBalances::class)->mountAction('create')->assertActionMounted('create');
    $userForm = Livewire::test(CreateUser::class);
    $purchaseForm = Livewire::test(CreatePurchase::class);

    $owner->update(['role' => User::ROLE_ADMINISTRATOR]);
    Livewire::actingAs($owner->fresh());
    $bank->call('saveInlineClassification')->assertForbidden();
    $opening->callMountedAction()->assertForbidden();
    $userForm->call('create')->assertForbidden();
    $purchaseForm->call('create')->assertForbidden();

    expect(FinanceOpeningBalance::count())->toBe(0)
        ->and(Purchase::count())->toBe(0)
        ->and(User::count())->toBe(1);
});

class UnconfiguredAuthorizationPage extends Page {}

class UnconfiguredConventionPage extends Page
{
    use AuthorizesPageAccess;
}

class UnconfiguredFinanceSubclass extends Pages\Finance {}

class UnconfiguredAuthorizationModel extends Model {}

class UnconfiguredAuthorizationResource extends \Filament\Resources\Resource
{
    protected static ?string $model = UnconfiguredAuthorizationModel::class;
}

test('new unconfigured page classes and subclasses are denied regardless of URL', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    expect(Gate::allows('access-panel-page', UnconfiguredAuthorizationPage::class))->toBeFalse()
        ->and(UnconfiguredFinanceSubclass::canAccess())->toBeFalse();
    Livewire::test(UnconfiguredConventionPage::class)->assertForbidden();

    foreach (['new-module', 'lab-cases-secret', 'profile-report'] as $path) {
        Route::middleware(['web', AuthorizePanelPage::class])->get('/'.$path, UnconfiguredAuthorizationPage::class);
        $this->get('/'.$path)->assertForbidden();
    }
})->with([User::ROLE_OWNER, User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('strict authorization refuses a new resource with no policy', function () {
    $this->actingAs(User::factory()->create());
    expect(Filament::getPanel('admin')->isAuthorizationStrict())->toBeTrue();
    expect(fn () => UnconfiguredAuthorizationResource::canAccess())->toThrow(LogicException::class);
});
