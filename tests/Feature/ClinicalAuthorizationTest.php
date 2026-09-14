<?php

use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\TreatmentCases\TreatmentCaseResource;
use App\Filament\Resources\TreatmentEstimates\TreatmentEstimateResource;
use App\Filament\Resources\Visits\VisitResource;
use App\Http\Middleware\RestrictLabTechnicianAccess;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\TreatmentEstimate;
use App\Models\User;
use App\Models\Visit;
use App\Services\PatientHistoryExportService;
use App\Services\TreatmentEstimateExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function clinicalAuthorizationRecords(): array
{
    $patient = Patient::create(['first_name' => 'Protected', 'last_name' => 'Patient']);
    $partner = Patient::create(['first_name' => 'Partner', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $doctor = Doctor::create(['first_name' => 'Operational', 'last_name' => 'Doctor', 'is_active' => true]);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'visit_type' => 'treatment', 'total_price' => 0, 'currency' => 'GEL']);
    $case = TreatmentCase::create(['name' => 'Treatment', 'category' => 'therapy', 'is_active' => true]);
    $estimate = TreatmentEstimate::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'estimate_date' => today()]);

    return [
        PatientResource::class => $patient,
        PartnerPatientResource::class => $partner,
        DoctorResource::class => $doctor,
        VisitResource::class => $visit,
        TreatmentCaseResource::class => $case,
        TreatmentEstimateResource::class => $estimate,
    ];
}

function clinicalExportUrls(array $records): array
{
    $patient = $records[PatientResource::class];
    $estimate = $records[TreatmentEstimateResource::class];

    return [
        route('patients.history.pdf', $patient),
        route('patients.history.word', $patient),
        route('treatment-estimates.pdf', [$patient, $estimate]),
        route('treatment-estimates.word', [$patient, $estimate]),
    ];
}

test('active operational roles download history and estimate documents through real routes', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    foreach (clinicalExportUrls(clinicalAuthorizationRecords()) as $url) {
        $response = $this->get($url)->assertOk();
        if (str_ends_with($url, '/pdf')) {
            expect($response->getContent())->toStartWith('%PDF-');
        } else {
            $response->assertDownload();
            expect(is_file($response->baseResponse->getFile()->getPathname()))->toBeTrue();
            unlink($response->baseResponse->getFile()->getPathname());
        }
    }
})->with([User::ROLE_OWNER, User::ROLE_ADMINISTRATOR]);

test('unauthorized roles and inactive users cannot invoke either document generator', function (string $role, bool $active) {
    $urls = clinicalExportUrls(clinicalAuthorizationRecords());
    $this->mock(PatientHistoryExportService::class, function ($mock) {
        $mock->shouldNotReceive('pdf', 'word');
    });
    $this->mock(TreatmentEstimateExportService::class, function ($mock) {
        $mock->shouldNotReceive('pdf', 'word');
    });
    $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $active]));
    foreach ($urls as $url) {
        $this->get($url)->assertForbidden();
    }
})->with([
    'technician' => [User::ROLE_LAB_TECHNICIAN, true],
    'inactive owner' => [User::ROLE_OWNER, false],
    'inactive administrator' => [User::ROLE_ADMINISTRATOR, false],
    'unknown role' => ['unknown', true],
]);

test('guests cannot download patient documents', function () {
    foreach (clinicalExportUrls(clinicalAuthorizationRecords()) as $url) {
        $this->getJson($url)->assertUnauthorized();
        $this->get($url)->assertRedirect('/admin/login');
    }
});

test('cross patient estimate URLs return 404 without generating documents', function (string $role) {
    $records = clinicalAuthorizationRecords();
    $wrongPatient = $records[PartnerPatientResource::class];
    $estimate = $records[TreatmentEstimateResource::class];
    $this->actingAs(User::factory()->create(['role' => $role]));
    $this->mock(TreatmentEstimateExportService::class, function ($mock) {
        $mock->shouldNotReceive('pdf', 'word');
    });
    foreach (['pdf', 'word'] as $format) {
        $this->get(route('treatment-estimates.'.$format, [$wrongPatient, $estimate]))->assertNotFound();
    }
})->with([User::ROLE_OWNER, User::ROLE_ADMINISTRATOR]);

test('clinical resource policies protect every page and CRUD ability without URL restriction middleware', function (string $role, bool $allowed) {
    $records = clinicalAuthorizationRecords();
    $this->withoutMiddleware(RestrictLabTechnicianAccess::class);
    $this->actingAs(User::factory()->create(['role' => $role]));

    foreach ($records as $resource => $record) {
        expect(Gate::getPolicyFor($record))->not->toBeNull();
        expect($resource::canViewAny())->toBe($allowed)
            ->and($resource::canView($record))->toBe($allowed)
            ->and($resource::canCreate())->toBe($allowed)
            ->and($resource::canEdit($record))->toBe($allowed)
            ->and($resource::canDelete($record))->toBe($allowed)
            ->and($resource::canDeleteAny())->toBe($allowed);

        foreach ($resource::getPages() as $name => $registration) {
            $url = $resource::getUrl($name, ['record' => $record]);
            $this->get($url)->assertStatus($allowed ? 200 : 403);
        }
    }
})->with([
    'owner' => [User::ROLE_OWNER, true],
    'administrator' => [User::ROLE_ADMINISTRATOR, true],
    'technician' => [User::ROLE_LAB_TECHNICIAN, false],
]);

test('inactive operational accounts are denied every clinical policy ability', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => false]));
    foreach (clinicalAuthorizationRecords() as $resource => $record) {
        expect($resource::canViewAny())->toBeFalse()
            ->and($resource::canView($record))->toBeFalse()
            ->and($resource::canCreate())->toBeFalse()
            ->and($resource::canEdit($record))->toBeFalse()
            ->and($resource::canDelete($record))->toBeFalse()
            ->and($resource::canDeleteAny())->toBeFalse();
    }
});

test('stale clinical Livewire pages cannot create edit or delete after role revocation', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $technician = User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]);
    foreach (clinicalAuthorizationRecords() as $resource => $record) {
        foreach (['create', 'save', 'delete'] as $action) {
            Livewire::actingAs($owner);
            $page = $resource::getPages()[$action === 'create' ? 'create' : 'edit']->getPage();
            $component = Livewire::test($page, $action === 'create' ? [] : ['record' => $record->getRouteKey()]);
            Livewire::actingAs($technician);
            if ($action === 'delete') {
                $component->call('mountAction', 'delete')->assertForbidden();
            } else {
                $component->call($action)->assertForbidden();
            }
            expect($record->fresh())->not->toBeNull();
        }
    }
});
