<?php

use App\Filament\Resources\Patients\Pages\ViewPatient;
use App\Filament\Resources\Patients\RelationManagers\VisitsRelationManager;
use App\Filament\Resources\Visits\Pages\CreateVisit;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('patient profile keeps clinical history before doctors and finance without obsolete blocks', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Profile', 'last_name' => 'Patient']);

    $component = Livewire::test(ViewPatient::class, ['record' => $patient->getRouteKey()])
        ->assertSuccessful()
        ->assertSeeHtml('renome-patient-profile-page')
        ->assertSee('ისტორიის '.$patient->formatted_patient_number)
        ->assertDontSee('სახელი და გვარი')
        ->assertDontSee('ისტორიის ნომერი')
        ->assertActionExists('createVisit')
        ->assertActionExists('makePayment')
        ->assertActionExists('treatmentPlans')
        ->assertActionExists('edit')
        ->assertDontSee('ბოლო ვიზიტი')
        ->assertDontSee('ბოლო გადახდა')
        ->assertDontSee('პროდუქტების შეძენა');

    $components = $component->instance()->getSchema('content')->getComponents();

    expect($components)->toHaveCount(3)
        ->and($component->instance()->getPageClasses())->toContain('renome-patient-profile-page')
        ->and($component->instance()->getSubheading())->toBe('ისტორიის '.$patient->formatted_patient_number)
        ->and(substr_count($component->html(), $patient->full_name))->toBe(1)
        ->and($components[0]->getName())->toBe('patientInformation')
        ->and($components[2]->getName())->toBe('patientSummary');
});

test('patient visit history shows manipulation teeth with the compact clinical columns', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Tooth', 'last_name' => 'History']);
    $doctor = Doctor::create(['first_name' => 'Dental', 'last_name' => 'Doctor', 'is_active' => true]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
        'visit_date' => '2026-08-28', 'total_price' => 300, 'currency' => 'GEL',
    ]);

    foreach ([['Extraction', '36'], ['Crown', '11, 12, 13'], ['Consultation', null]] as [$name, $teeth]) {
        $service = TreatmentCase::create(['name' => $name, 'category' => 'therapy', 'is_active' => true]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->getKey(), 'quantity' => 1, 'unit_price' => 100, 'teeth' => $teeth,
        ]);
    }

    Livewire::test(VisitsRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => ViewPatient::class,
    ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visit])
        ->assertTableColumnExists('visit_date')
        ->assertTableColumnExists('doctor.full_name')
        ->assertTableColumnExists('teeth')
        ->assertTableColumnExists('services_summary')
        ->assertTableColumnExists('net_amount')
        ->assertTableColumnExists('payment_status')
        ->assertSee('11, 12, 13, 36')
        ->assertSee('+1');
});

test('patient visit history has no search and filters only by doctors who treated that patient', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Filter', 'last_name' => 'Patient']);
    $firstDoctor = Doctor::create(['first_name' => 'First', 'last_name' => 'Doctor', 'is_active' => true]);
    $secondDoctor = Doctor::create(['first_name' => 'Second', 'last_name' => 'Doctor', 'is_active' => true]);
    $unrelatedDoctor = Doctor::create(['first_name' => 'Unrelated', 'last_name' => 'Doctor', 'is_active' => true]);
    $firstVisit = Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $firstDoctor->getKey(),
        'visit_date' => '2026-08-27', 'total_price' => 100,
    ]);
    $secondVisit = Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $secondDoctor->getKey(),
        'visit_date' => '2026-08-28', 'total_price' => 200,
    ]);

    $component = Livewire::test(VisitsRelationManager::class, [
        'ownerRecord' => $patient,
        'pageClass' => ViewPatient::class,
    ]);

    expect($component->instance()->getTable()->isSearchable())->toBeFalse();
    expect($component->instance()->getTable()->getFiltersLayout())->toBe(FiltersLayout::Hidden)
        ->and($component->instance()->getTable()->getHeader())->toBeNull();

    $component
        ->assertSuccessful()
        ->assertSee('ექიმი:')
        ->assertSee('ყველა')
        ->assertSeeHtml('wire:model.live="tableFilters.doctor_id.value"')
        ->assertTableFilterExists('doctor_id', function (SelectFilter $filter) use ($firstDoctor, $secondDoctor, $unrelatedDoctor): bool {
            $options = $filter->getOptions();

            return isset($options[$firstDoctor->getKey()], $options[$secondDoctor->getKey()])
                && ! isset($options[$unrelatedDoctor->getKey()]);
        })
        ->assertCanSeeTableRecords([$firstVisit, $secondVisit])
        ->filterTable('doctor_id', $firstDoctor->getKey())
        ->assertCanSeeTableRecords([$firstVisit])
        ->assertCanNotSeeTableRecords([$secondVisit])
        ->resetTableFilters()
        ->assertCanSeeTableRecords([$firstVisit, $secondVisit]);
});

test('visit manipulation rows save one multiple and empty tooth values without affecting totals', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create(['first_name' => 'Tooth', 'last_name' => 'Entry']);
    $services = collect(['Single tooth', 'Multiple teeth', 'No tooth'])->map(fn (string $name): TreatmentCase => TreatmentCase::create([
        'name' => $name, 'category' => 'therapy', 'default_price' => 100, 'is_active' => true,
    ]));

    Livewire::test(CreateVisit::class)
        ->assertSee('კბილი / კბილები')
        ->fillForm([
            'patient_id' => $patient->getKey(),
            'visit_type' => 'treatment',
            'treatmentCaseItems' => $services->values()->map(fn (TreatmentCase $service, int $index): array => [
                'service_choice' => (string) $service->getKey(),
                'treatment_case_id' => $service->getKey(),
                'quantity' => 1,
                'unit_price' => 100,
                'teeth' => match ($index) {
                    0 => '36', 1 => '11, 12, 13', default => null
                },
            ])->all(),
        ])
        ->call('create')
        ->assertHasNoErrors();

    $visit = Visit::query()->sole();
    expect($visit->treatmentCaseItems()->orderBy('id')->pluck('teeth')->all())
        ->toBe(['36', '11, 12, 13', null])
        ->and((float) $visit->total_price)->toBe(300.0);
});
