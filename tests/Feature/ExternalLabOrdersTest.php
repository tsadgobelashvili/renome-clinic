<?php

use App\Filament\Pages\ExternalLabOrders;
use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use App\Services\ExternalLabCaseData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function externalLabOrder(array $attributes = []): LabCase
{
    return LabCase::create([
        'source' => 'external', 'case_date' => today(), 'external_clinic_name' => 'Outside Clinic',
        'external_doctor_name' => 'External Doctor', 'external_patient_name' => 'External Patient',
        ...$attributes,
    ]);
}

test('Lab Technician creates and edits external work without any core doctor or patient', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    $technician = Employee::create(['first_name' => 'Lab', 'last_name' => 'Technician', 'is_active' => true,
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id]);
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'external', 'external_clinic_name' => 'Sun Clinic', 'notes' => 'External case notes',
        'mainWorks' => [['doctor_search' => 'Dr Outside', 'patient_search' => 'Outside Patient',
            'material' => 'zircon', 'quantity' => 2, 'shade' => 'A1', 'technician_id' => $technician->id]],
        'additionalWorks' => [['work_type' => 'milling', 'quantity' => 1, 'technician_id' => $technician->id, 'note' => 'Extra detail']],
    ])->assertFormFieldIsVisible('mainWorks.0.doctor_search')->assertFormFieldIsVisible('mainWorks.0.patient_search')
        ->assertFormFieldExists('external_doctor_name', fn ($field) => $field instanceof \Filament\Forms\Components\Hidden)
        ->assertFormFieldExists('external_patient_name', fn ($field) => $field instanceof \Filament\Forms\Components\Hidden)
        ->assertActionDataSet(['external_doctor_name' => 'Dr Outside', 'external_patient_name' => 'Outside Patient'])
        ->callMountedAction()->assertHasNoActionErrors();
    $case = LabCase::sole();
    expect($case->only(['source', 'external_clinic_name', 'external_doctor_name', 'external_patient_name', 'patient_id', 'doctor_id', 'assistant_employee_id']))
        ->toBe(['source' => 'external', 'external_clinic_name' => 'Sun Clinic', 'external_doctor_name' => 'Dr Outside',
            'external_patient_name' => 'Outside Patient', 'patient_id' => null, 'doctor_id' => null, 'assistant_employee_id' => null])
        ->and($case->mainWorks->sole()->quantity)->toBe(2)
        ->and($case->mainWorks->sole()->shade)->toBe('A1')
        ->and($case->additionalWorks->sole()->note)->toBe('Extra detail')
        ->and(Patient::count())->toBe(0)->and(Doctor::count())->toBe(0);
    Livewire::test(ListLabCases::class)->filterTable('toolbar', ['source' => 'external'])
        ->assertCanSeeTableRecords([$case])->assertSee('Dr Outside')->assertSee('Outside Patient');
    $edit = Livewire::test(EditLabCase::class, ['record' => $case->id]);
    $workKey = array_key_first($edit->get('data.mainWorks'));
    $edit->set('data.mainWorks.'.$workKey.'.patient_search', 'Renamed External Patient')
        ->fillForm(['notes' => 'Updated notes'])->call('save')->assertHasNoFormErrors();
    expect($case->fresh()->external_patient_name)->toBe('Renamed External Patient')
        ->and($case->fresh()->notes)->toBe('Updated notes')
        ->and(Patient::count())->toBe(0)->and(Doctor::count())->toBe(0);
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    Livewire::test(ExternalLabOrders::class)->assertSee('Dr Outside')->assertSee('Renamed External Patient')->assertSee('Sun Clinic');
});

test('external names are required but registered relationships and clinic name are optional', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    $component = Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'source' => 'external', 'mainWorks' => [['material' => 'pmma', 'quantity' => 1]],
    ])->callMountedAction()->assertHasFormErrors(['mainWorks.0.doctor_search' => 'required', 'mainWorks.0.patient_search' => 'required']);
    $component->fillForm(['mainWorks' => [['doctor_search' => 'Independent Doctor', 'patient_search' => 'Independent Patient', 'material' => 'pmma', 'quantity' => 1]]])
        ->callMountedAction()->assertHasNoActionErrors();
    expect(LabCase::sole()->patient_id)->toBeNull()->and(LabCase::sole()->external_clinic_name)->toBeNull();
    $data = ExternalLabCaseData::prepare(['source' => 'external', 'patient_id' => 999, 'doctor_id' => 999, 'assistant_employee_id' => 999,
        'external_doctor_name' => 'Text doctor', 'external_patient_name' => 'Text patient']);
    expect($data)->toMatchArray(['doctor_id' => null, 'patient_id' => null, 'assistant_employee_id' => null]);
});

test('external rows share Clinic column widths and carry consistent case names when adding work', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    $page = Livewire::test(ListLabCases::class)->mountAction('create');
    foreach (['clinic', 'israeli', 'external'] as $source) {
        $page->fillForm(['source' => $source])->assertFormFieldExists('mainWorks', fn ($field): bool =>
            array_map(fn ($column) => $column->getWidth(), $field->getTableColumns()) === ['18%', '22%', '18%', '10%', '10%', '17%', '5%']);
    }
    $page->assertFormFieldExists('external_clinic_name', fn ($field): bool => $field->getLabel() === __('lab.clinic') && ! $field->isRequired())
        ->fillForm(['mainWorks' => [[
            'doctor_search' => 'Outside Doctor', 'patient_search' => 'Outside Patient',
            'material' => 'pmma', 'quantity' => 2, 'shade' => 'A1',
        ]]])->callFormComponentAction('mainWorks', 'add', formName: 'mountedActionSchema0');
    $rows = $page->get('mountedActions.0.data.mainWorks');
    $keys = array_keys($rows);
    expect(array_values($rows)[1])->toMatchArray([
        'doctor_search' => 'Outside Doctor', 'patient_search' => 'Outside Patient', 'material' => 'zircon', 'quantity' => 2, 'shade' => 'A1',
    ]);
    $page->set('mountedActions.0.data.mainWorks.'.$keys[1].'.doctor_search', 'Updated Doctor')
        ->set('mountedActions.0.data.mainWorks.'.$keys[1].'.patient_search', 'Updated Patient');
    foreach ($page->get('mountedActions.0.data.mainWorks') as $row) {
        expect($row)->toMatchArray(['doctor_search' => 'Updated Doctor', 'patient_search' => 'Updated Patient']);
    }
    $page->callMountedAction()->assertHasNoActionErrors();
    expect(LabCase::sole()->external_doctor_name)->toBe('Updated Doctor')
        ->and(LabCase::sole()->external_patient_name)->toBe('Updated Patient')
        ->and(LabCase::sole()->mainWorks)->toHaveCount(2)
        ->and(Patient::count())->toBe(0)->and(Doctor::count())->toBe(0);
});

test('Owner review lists only external cases and reads no core doctor or patient tables', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $external = externalLabOrder(['notes' => 'Review notes']);
    $external->mainWorks()->create(['material' => 'zircon', 'quantity' => 2]);
    $patient = Patient::create(['first_name' => 'Internal', 'last_name' => 'Patient']);
    $clinic = LabCase::create(['patient_id' => $patient->id, 'case_date' => today(), 'source' => 'clinic']);
    $israeli = LabCase::create(['patient_id' => $patient->id, 'case_date' => today(), 'source' => 'israeli']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    Livewire::test(ExternalLabOrders::class)->assertSuccessful()->assertCanSeeTableRecords([$external])
        ->assertCanNotSeeTableRecords([$clinic, $israeli])->assertSee('External Doctor')->assertSee('External Patient')->assertSee('Review notes');
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect($queries->contains(fn ($sql) => (bool) preg_match('/(?:from|join)\s+["`]?\b(?:patients|doctors)\b/i', $sql)))->toBeFalse();
    $this->get(ExternalLabOrders::getUrl())->assertOk();
    expect(ExternalLabOrders::canAccess())->toBeTrue()
        ->and(ExternalLabOrders::getNavigationParentItem())->toBe(__('lab.navigation.group'));
    $labItem = collect(filament()->getNavigation())->flatMap(fn ($group) => $group->getItems())
        ->first(fn ($item) => $item->getLabel() === __('lab.navigation.group'));
    expect(collect($labItem?->getChildItems())->contains(fn ($item) => $item->getUrl() === ExternalLabOrders::getUrl()))->toBeTrue();
});

test('external review filters dates clinic names material and technician', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $technician = Employee::create(['first_name' => 'Selected', 'last_name' => 'Technician', 'is_active' => true,
        'position_id' => EmployeePosition::where('name', 'Technician')->sole()->id]);
    $match = externalLabOrder(['external_clinic_name' => 'Selected Clinic', 'external_doctor_name' => 'DAVIT Outside', 'external_patient_name' => 'NINO Outside']);
    $match->mainWorks()->create(['material' => 'zircon', 'quantity' => 1, 'technician_id' => $technician->id]);
    $other = externalLabOrder();
    $other->mainWorks()->create(['material' => 'pmma', 'quantity' => 1]);
    $old = externalLabOrder(['case_date' => today()->subYear()]);
    $page = Livewire::test(ExternalLabOrders::class)->assertCanSeeTableRecords([$match, $other])->assertCanNotSeeTableRecords([$old]);
    foreach ([['clinic' => 'Selected Clinic'], ['doctor' => 'davit'], ['patient' => 'nino'], ['material' => 'zircon'], ['technician' => $technician->id]] as $filter) {
        $page->filterTable('review', $filter)->assertCanSeeTableRecords([$match])->assertCanNotSeeTableRecords([$other]);
    }
    $page->filterTable('review', ['from' => null, 'until' => null])->assertCanSeeTableRecords([$old]);
});

test('external review combines all filters with inclusive dates and keeps internal sources excluded', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $position = EmployeePosition::where('name', 'Technician')->sole();
    $technician = Employee::create(['first_name' => 'Selected', 'last_name' => 'Tech', 'is_active' => true, 'position_id' => $position->id]);
    $otherTechnician = Employee::create(['first_name' => 'Other', 'last_name' => 'Tech', 'is_active' => true, 'position_id' => $position->id]);
    $make = function (array $attributes = [], string $material = 'zircon', ?int $technicianId = null) use ($technician): LabCase {
        $case = externalLabOrder([
            'external_clinic_name' => 'Selected Clinic', 'external_doctor_name' => 'DAVIT Outside',
            'external_patient_name' => 'NINO External Patient', ...$attributes,
        ]);
        $case->mainWorks()->create(['material' => $material, 'quantity' => 1, 'technician_id' => $technicianId ?? $technician->id]);

        return $case;
    };
    $upperBoundary = $make();
    $lowerBoundary = $make(['case_date' => today()->subDay()]);
    $excluded = collect([
        $make(['case_date' => today()->subDays(2)]), $make(['case_date' => today()->addDay()]),
        $make(['external_clinic_name' => 'Different Clinic']), $make(['external_doctor_name' => 'Different Doctor']),
        $make(['external_patient_name' => 'Different Patient']), $make([], 'pmma'), $make([], 'zircon', $otherTechnician->id),
    ]);
    $patient = Patient::create(['first_name' => 'Internal', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Internal', 'last_name' => 'Doctor', 'is_active' => true]);
    foreach (['clinic', 'israeli'] as $source) {
        $excluded->push($make(['source' => $source, 'patient_id' => $patient->id, 'doctor_id' => $doctor->id]));
    }
    Livewire::test(ExternalLabOrders::class)->filterTable('review', [
        'from' => today()->subDay()->toDateString(), 'until' => today()->toDateString(),
        'clinic' => 'Selected Clinic', 'doctor' => ' dAvIt ', 'patient' => ' eXtErNaL ',
        'technician' => $technician->id, 'material' => 'zircon',
    ])->assertCanSeeTableRecords([$lowerBoundary, $upperBoundary])->assertCanNotSeeTableRecords($excluded)
        ->assertCountTableRecords(2);
});

test('external review filter order preserves calendar controls and clear All options', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $page = Livewire::test(ExternalLabOrders::class);
    $page->assertSeeHtml('renome-visits-toolbar__period')
        ->assertSeeHtml('x-ref="fromPicker"')
        ->assertSeeHtml('x-ref="untilPicker"')
        ->assertSeeHtml('wire:click="removeTableFilters"');
    expect($page->instance()->getTable()->getFiltersLayout())->toBe(\Filament\Tables\Enums\FiltersLayout::Hidden);
    $fields = $page->instance()->getTable()->getFilter('review')->getFormSchema();
    expect(array_map(fn ($field) => $field->getName(), $fields))->toBe(['from', 'until', 'clinic', 'doctor', 'patient', 'technician', 'material'])
        ->and($fields[0])->toBeInstanceOf(\Filament\Forms\Components\DatePicker::class)
        ->and($fields[1])->toBeInstanceOf(\Filament\Forms\Components\DatePicker::class)
        ->and($fields[2]->isSearchable())->toBeTrue()
        ->and($fields[2]->getPlaceholder())->toBe(__('lab.clinic'))
        ->and($fields[5]->getPlaceholder())->toBe(__('lab.technicians_placeholder'))
        ->and($fields[2]->getOptions()[''])->toBe(__('lab.all'))
        ->and($fields[5]->getOptions()[''])->toBe(__('lab.all'))
        ->and($fields[6]->getPlaceholder())->toBe(__('lab.all_materials'))
        ->and($fields[6]->getOptions())->toBe(LabCase::MATERIALS);
    foreach (array_slice($fields, 2) as $field) {
        expect($field->isLabelHidden())->toBeTrue();
    }
    $page->filterTable('review', ['clinic' => 'Clinic', 'doctor' => 'Doctor', 'patient' => 'Patient', 'material' => 'pmma'])
        ->call('removeTableFilters');
    foreach (['from', 'until', 'clinic', 'doctor', 'patient', 'technician', 'material'] as $field) {
        $page->assertSet('tableFilters.review.'.$field, null);
    }
});

test('Lab Technician Administrator and inactive Owner cannot access External Orders', function (string $role, bool $active) {
    $this->actingAs(User::factory()->create(['role' => $role, 'is_active' => $active]));
    expect(ExternalLabOrders::canAccess())->toBeFalse();
    Livewire::test(ExternalLabOrders::class)->assertForbidden();
    $this->get(ExternalLabOrders::getUrl())->assertForbidden();
    if ($active) {
        $items = collect(filament()->getNavigation())->flatMap(fn ($group) => $group->getItems());
        expect($items->contains(fn ($item) => $item->getLabel() === __('lab.external_orders')))->toBeFalse();
    }
})->with([[User::ROLE_LAB_TECHNICIAN, true], [User::ROLE_ADMINISTRATOR, true], [User::ROLE_OWNER, false]]);

test('editing historical external cases snapshots names and retains original links', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create(['first_name' => 'Historical', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Historical', 'last_name' => 'Doctor', 'is_active' => true]);
    $case = LabCase::create(['source' => 'external', 'patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'case_date' => today()]);
    $case->mainWorks()->create(['material' => 'pmma', 'quantity' => 1]);
    Livewire::test(EditLabCase::class, ['record' => $case->id])->fillForm(['notes' => 'Legacy retained'])
        ->call('save')->assertHasNoFormErrors();
    expect($case->fresh()->patient_id)->toBe($patient->id)->and($case->fresh()->doctor_id)->toBe($doctor->id)
        ->and($case->fresh()->external_patient_name)->toBe('Historical Patient')
        ->and($case->fresh()->external_doctor_name)->toBe('Historical Doctor')
        ->and(Patient::count())->toBe(1)->and(Doctor::count())->toBe(1);
});
