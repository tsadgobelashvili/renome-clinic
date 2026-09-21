<?php

use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Employees\EmployeeResource;
use App\Filament\Resources\LabTechnicians\LabTechnicianResource;
use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function readablePersonRecords(): array
{
    return [
        DoctorResource::class => Doctor::create([
            'first_name' => 'ლევან', 'last_name' => 'ბერიკაშვილი',
            'first_name_en' => 'Levan', 'last_name_en' => 'Berikashvili',
        ]),
        PatientResource::class => Patient::create([
            'first_name' => 'ნინო', 'last_name' => 'გიორგაძე',
            'first_name_latin' => 'Nino', 'last_name_latin' => 'Giorgadze',
        ]),
        EmployeeResource::class => Employee::create([
            'first_name' => 'ალექს', 'last_name' => 'მელიუხინი', 'is_active' => true,
            'position_id' => EmployeePosition::create(['name' => 'Office', 'is_technician' => false])->id,
        ]),
    ];
}

test('canonical person links use Latin names and preserve numeric model identity', function () {
    $this->actingAs(User::factory()->create());
    $records = readablePersonRecords();
    foreach ([DoctorResource::class => 'levan-berikashvili', PatientResource::class => 'nino-giorgadze', EmployeeResource::class => 'aleks-meliukhini'] as $resource => $slug) {
        $record = $records[$resource];
        $key = $slug.'-'.$record->id;
        expect($resource::getUrl('view', ['record' => $record]))->toEndWith('/'.$key)
            ->and($resource::getUrl('edit', ['record' => $record->id]))->toEndWith('/'.$key.'/edit')
            ->and($resource::resolveRecordRouteBinding($key)->id)->toBe($record->id)
            ->and($record->getRouteKey())->toBe($record->id);
        $this->get($resource::getUrl('view', ['record' => $record]))->assertOk();
        $this->get($resource::getUrl('edit', ['record' => $record]))->assertOk();
    }
});

test('numeric and incorrect slug URLs redirect only to the authorized canonical page', function () {
    $this->actingAs(User::factory()->create());
    foreach (readablePersonRecords() as $resource => $record) {
        foreach (['view', 'edit'] as $page) {
            foreach ([(string) $record->id, 'wrong-name-'.$record->id] as $key) {
                $old = route($resource::getRouteBaseName().'.'.$page, ['record' => $key, 'tab' => 'history']);
                $this->get($old)->assertRedirect($resource::getUrl($page, ['record' => $record, 'tab' => 'history']));
            }
        }
        $old = $resource::getUrl('view', ['record' => $record]);
        $record->update(match ($resource) {
            DoctorResource::class => ['first_name_en' => 'Renamed'],
            PatientResource::class => ['first_name_latin' => 'Renamed'],
            default => ['first_name' => 'Renamed'],
        });
        $this->get($old)->assertRedirect($resource::getUrl('view', ['record' => $record]));
    }
});

test('trailing ID determines the record and resource scopes and query modifiers remain in force', function () {
    $this->actingAs(User::factory()->create());
    foreach (readablePersonRecords() as $resource => $record) {
        $other = $record->replicate(['patient_number']);
        $other->first_name = 'Other';
        $other->save();
        $key = 'arbitrary-name-'.$other->id;
        expect($resource::resolveRecordRouteBinding($key)->id)->toBe($other->id)
            ->and($resource::resolveRecordRouteBinding($key, fn ($query) => $query->whereKey($record->id)))->toBeNull();
        $this->get(route($resource::getRouteBaseName().'.view', ['record' => $key]))
            ->assertRedirect($resource::getUrl('view', ['record' => $other]));
    }
    $technician = Employee::create(['first_name' => 'Lab', 'last_name' => 'Only',
        'position_id' => EmployeePosition::create(['name' => 'URL test technician', 'is_technician' => true])->id]);
    expect(EmployeeResource::resolveRecordRouteBinding('lab-only-'.$technician->id))->toBeNull();
    $this->get('/employees/lab-only-'.$technician->id)->assertNotFound();
    expect(LabTechnicianResource::getUrl('view', ['record' => $technician]))->toEndWith('/'.$technician->id);
    $this->get(LabTechnicianResource::getUrl('view', ['record' => $technician]))->assertOk();
});

test('unauthorized users receive denial rather than canonical name redirects', function (string $role, bool $active) {
    // Legacy unknown role fixture, bypassing new-user role validation only in setup.
    $this->actingAs(User::factory()->createQuietly(['role' => $role, 'is_active' => $active]));
    foreach (readablePersonRecords() as $resource => $record) {
        foreach (['view', 'edit'] as $page) {
            foreach ([$record->id, 'wrong-'.$record->id, $resource::personRouteKey($record)] as $key) {
                $this->get(route($resource::getRouteBaseName().'.'.$page, ['record' => $key]))->assertForbidden();
            }
        }
    }
})->with([[User::ROLE_LAB_TECHNICIAN, true], ['unknown', true], [User::ROLE_OWNER, false]]);

test('administrator retains clinical access but not owner-only employee access', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    foreach (readablePersonRecords() as $resource => $record) {
        $response = $this->get($resource::getUrl('edit', ['record' => $record]));
        $resource === EmployeeResource::class ? $response->assertForbidden() : $response->assertOk();
    }
});

test('invalid and missing IDs cannot bind and other patient resources keep numeric links', function () {
    $records = readablePersonRecords();
    foreach (array_keys($records) as $resource) {
        foreach (['no-id', 'name-0', '1oops', 'name-999999999999999999999999999999', 'name-999999'] as $key) {
            expect($resource::resolveRecordRouteBinding($key))->toBeNull();
        }
    }
    $patient = $records[PatientResource::class];
    expect(PartnerPatientResource::getUrl('view', ['record' => $patient]))->toEndWith('/'.$patient->id);
});

test('links generated from loaded records perform no extra queries and patient subpages retain canonical routing', function () {
    $this->actingAs(User::factory()->create());
    $records = readablePersonRecords();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    foreach ($records as $resource => $record) {
        $resource::getUrl('view', ['record' => $record]);
        $resource::getUrl('edit', ['record' => $record]);
    }
    expect($queries)->toBeEmpty();
    $patient = $records[PatientResource::class];
    $this->get(route(PatientResource::getRouteBaseName().'.treatment-plans', ['record' => $patient->id]))
        ->assertRedirect(PatientResource::getUrl('treatment-plans', ['record' => $patient]));
    $this->get(PatientResource::getUrl('treatment-plans', ['record' => $patient]))->assertOk();
});
