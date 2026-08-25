<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\SalarySettlement;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use App\Services\SalarySettlementService;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Filament\Support\Enums\Width;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('doctor compensation uses performed work minus direct expenses', function () {
    $doctor = Doctor::create([
        'first_name' => 'Salary',
        'last_name' => 'Doctor',
        'compensation_percentage' => 40,
        'is_active' => true,
    ]);
    $patient = Patient::create(['first_name' => 'Salary', 'last_name' => 'Patient']);
    $service = TreatmentCase::create([
        'name' => 'Salary service',
        'category' => 'therapy',
        'is_active' => true,
    ]);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'currency' => 'GEL',
        'total_price' => 10000,
    ]);
    $first = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $service->getKey(),
        'quantity' => 2,
        'unit_price' => 3000,
    ]);
    $second = $visit->treatmentCaseItems()->create([
        'custom_service_name' => 'Manual salary work',
        'quantity' => 1,
        'unit_price' => 4000,
    ]);
    $first->directExpenses()->create(['name' => 'Lab', 'amount' => 1500, 'currency' => 'GEL']);
    $second->directExpenses()->create(['name' => 'Material', 'amount' => 500, 'currency' => 'GEL']);

    $report = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(),
        today()->startOfMonth()->toDateString(),
        today()->toDateString(),
    );

    expect($report['percentage'])->toBe(40.0)
        ->and($report['totals']['GEL']['work_total'])->toBe(10000.0)
        ->and($report['totals']['GEL']['expense_total'])->toBe(2000.0)
        ->and($report['totals']['GEL']['base_total'])->toBe(8000.0)
        ->and($report['totals']['GEL']['doctor_share'])->toBe(3200.0)
        ->and($report['details'])->toHaveCount(1)
        ->and($report['details'][0]['visit_id'])->toBe($visit->getKey());
});

test('doctor compensation respects doctor date and currency boundaries', function () {
    $doctor = Doctor::create(['first_name' => 'Main', 'last_name' => 'Doctor', 'is_active' => true]);
    $otherDoctor = Doctor::create(['first_name' => 'Other', 'last_name' => 'Doctor', 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Boundary', 'last_name' => 'Patient']);

    foreach ([
        [$doctor, today(), 'GEL', 1000],
        [$doctor, today(), 'USD', 200],
        [$doctor, today()->subMonths(2), 'GEL', 5000],
        [$otherDoctor, today(), 'GEL', 7000],
    ] as [$visitDoctor, $date, $currency, $amount]) {
        $visit = Visit::create([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $visitDoctor->getKey(),
            'visit_date' => $date,
            'currency' => $currency,
            'total_price' => $amount,
        ]);
        $visit->treatmentCaseItems()->create([
            'custom_service_name' => 'Boundary work '.$currency,
            'quantity' => 1,
            'unit_price' => $amount,
        ]);
    }

    $report = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(),
        today()->startOfMonth()->toDateString(),
        today()->toDateString(),
        25,
    );

    expect($report['details'])->toHaveCount(2)
        ->and($report['totals']['GEL']['work_total'])->toBe(1000.0)
        ->and($report['totals']['GEL']['doctor_share'])->toBe(250.0)
        ->and($report['totals']['USD']['work_total'])->toBe(200.0)
        ->and($report['totals']['USD']['doctor_share'])->toBe(50.0);
});

test('doctor compensation percentage is configurable and validated', function () {
    $doctor = Doctor::create([
        'first_name' => 'Percent',
        'last_name' => 'Doctor',
        'compensation_percentage' => 35,
        'is_active' => true,
    ]);

    expect((float) $doctor->compensation_percentage)->toBe(35.0)
        ->and(fn () => $doctor->update(['compensation_percentage' => 101]))
        ->toThrow(ValidationException::class);
});

test('doctor compensation page calculates an auditable report', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create([
        'first_name' => 'Page',
        'last_name' => 'Doctor',
        'compensation_percentage' => 40,
        'is_active' => true,
    ]);

    Livewire::test(DoctorCompensation::class)
        ->assertSuccessful()
        ->assertSee('ექიმის ანაზღაურება')
        ->set('doctorId', $doctor->getKey())
        ->assertSet('percentage', 40.0)
        ->call('calculate')
        ->assertHasNoErrors();
});

test('doctor view salary action opens a reactive modal and confirms the exact work', function () {
    $this->actingAs($user = User::factory()->create());
    $doctor = Doctor::create([
        'first_name' => 'Modal',
        'last_name' => 'Doctor',
        'compensation_percentage' => 40,
        'is_active' => true,
    ]);
    $patient = Patient::create(['first_name' => 'Modal', 'last_name' => 'Patient']);
    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => today(),
        'currency' => 'GEL',
    ]);
    $first = $visit->treatmentCaseItems()->create([
        'custom_service_name' => 'Modal work one',
        'quantity' => 1,
        'unit_price' => 1000,
    ]);
    $second = $visit->treatmentCaseItems()->create([
        'custom_service_name' => 'Modal work two',
        'quantity' => 2,
        'unit_price' => 500,
    ]);
    $first->directExpenses()->create(['name' => 'Lab', 'amount' => 200, 'currency' => 'GEL']);

    $action = TestAction::make('calculateSalary')->schemaComponent('compensation');
    $component = Livewire::test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])
        ->assertActionExists($action, fn (Action $action): bool => $action->getUrl() === null
            && $action->getModalWidth() === Width::SevenExtraLarge)
        ->mountAction($action)
        ->assertActionDataSet([
            'from' => today()->toDateString(),
            'until' => today()->toDateString(),
            'percentage' => 40.0,
        ])
        ->assertMountedActionModalSee([
            'ვიზიტის ჩათვლით',
            'დღის ბოლომდე',
            'Modal Patient',
            'Visit #'.$visit->getKey(),
            'Modal work one',
            'Modal work two',
            '2,000.00 ₾',
            '200.00 ₾',
            '720.00 ₾',
        ]);

    $component
        ->call('saveSalaryExpense', $second->getKey(), null, 'Technician', 100)
        ->assertNotified('შენახულია')
        ->assertMountedActionModalSee([
            '300.00 ₾',
            '1,700.00 ₾',
            '680.00 ₾',
        ]);

    $component->callMountedAction()->assertNotified('ხელფასი დაფიქსირდა.');

    $settlement = SalarySettlement::query()->with('items')->sole();
    expect($settlement->created_by)->toBe($user->getKey())
        ->and((float) $settlement->direct_expense_total)->toBe(300.0)
        ->and((float) $settlement->salary_total)->toBe(680.0)
        ->and($settlement->items)->toHaveCount(2)
        ->and($settlement->items->pluck('visit_treatment_case_id')->all())
        ->toContain($first->getKey(), $second->getKey());

    $component
        ->mountAction($action)
        ->assertMountedActionModalSee([
            'ბოლო დაფიქსირებული ხელფასი',
            'Modal Patient',
            'Visit #'.$visit->getKey(),
        ]);
});

test('salary cutoff includes the selected visit and leaves later same-day work unsettled', function () {
    $doctor = Doctor::create([
        'first_name' => 'Cutoff',
        'last_name' => 'Doctor',
        'compensation_percentage' => 30,
        'is_active' => true,
    ]);
    $patients = collect(['First', 'Selected', 'Later'])->map(fn (string $name): Patient => Patient::create([
        'first_name' => $name,
        'last_name' => 'Patient',
    ]));
    $visits = $patients->map(function (Patient $patient) use ($doctor): Visit {
        $visit = Visit::create([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_date' => today(),
            'currency' => 'GEL',
        ]);
        $visit->treatmentCaseItems()->create([
            'custom_service_name' => $patient->first_name.' work',
            'quantity' => 1,
            'unit_price' => 100,
        ]);

        return $visit;
    });
    $calculator = app(DoctorCompensationCalculator::class);
    $selected = $visits[1];

    $options = $calculator->cutoffVisitOptions($doctor->getKey(), today()->toDateString(), today()->toDateString());
    expect($options)->toHaveCount(3)
        ->and($options[$selected->getKey()])->toContain('Selected Patient')
        ->and($options[$selected->getKey()])->toContain('Visit #'.$selected->getKey());

    expect($calculator->cutoffVisitOptions($doctor->getKey(), today()->toDateString(), today()->toDateString(), 'Selected'))
        ->toHaveCount(1)->toHaveKey($selected->getKey())
        ->and($calculator->cutoffVisitOptions($doctor->getKey(), today()->toDateString(), today()->toDateString(), 'Patient'))
        ->toHaveCount(3)
        ->and($calculator->cutoffVisitOptions($doctor->getKey(), today()->toDateString(), today()->toDateString(), 'Selected Patient'))
        ->toHaveCount(1)->toHaveKey($selected->getKey())
        ->and($calculator->cutoffVisitOptions($doctor->getKey(), today()->toDateString(), today()->toDateString(), 'Patient Selected'))
        ->toHaveCount(1)->toHaveKey($selected->getKey())
        ->and($calculator->cutoffVisitOptions($doctor->getKey(), today()->toDateString(), today()->toDateString(), (string) $selected->getKey()))
        ->toHaveCount(1)->toHaveKey($selected->getKey())
        ->and($calculator->cutoffVisitLabel($doctor->getKey(), today()->toDateString(), today()->toDateString(), $selected->getKey()))
        ->toBe('Selected Patient — Visit #'.$selected->getKey());

    $report = $calculator->calculate(
        $doctor->getKey(),
        today()->toDateString(),
        today()->toDateString(),
        30,
        $selected->getKey(),
    );
    expect(collect($report['details'])->pluck('visit_id')->all())
        ->toBe([$selected->getKey(), $visits[0]->getKey()]);

    app(SalarySettlementService::class)->settle(
        $doctor->getKey(),
        today()->toDateString(),
        today()->toDateString(),
        30,
        null,
        $selected->getKey(),
    );

    $nextReport = $calculator->calculate(
        $doctor->getKey(),
        today()->toDateString(),
        today()->toDateString(),
        30,
    );
    expect(collect($nextReport['details'])->pluck('visit_id')->all())
        ->toBe([$visits[2]->getKey()])
        ->and($calculator->cutoffVisitOptions($doctor->getKey(), today()->toDateString(), today()->toDateString()))
        ->toHaveCount(1)->toHaveKey($visits[2]->getKey())
        ->and(SalarySettlement::query()->sole()->items)->toHaveCount(2);
});

test('salary modal reacts to period changes and clears stale cutoff state', function () {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create([
        'first_name' => 'Reactive',
        'last_name' => 'Doctor',
        'compensation_percentage' => 50,
        'is_active' => true,
    ]);
    $patient = Patient::create(['first_name' => 'Reactive', 'last_name' => 'Patient']);
    $visits = collect([
        ['date' => today()->subDays(2), 'name' => 'Old work', 'amount' => 100],
        ['date' => today()->subDay(), 'name' => 'Middle work', 'amount' => 200],
        ['date' => today(), 'name' => 'Today work', 'amount' => 300],
    ])->map(function (array $data) use ($doctor, $patient): Visit {
        $visit = Visit::create([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_date' => $data['date'],
            'currency' => 'GEL',
        ]);
        $visit->treatmentCaseItems()->create([
            'custom_service_name' => $data['name'],
            'quantity' => 1,
            'unit_price' => $data['amount'],
        ]);

        return $visit;
    });

    $action = TestAction::make('calculateSalary')->schemaComponent('compensation');
    $component = Livewire::test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])
        ->mountAction($action)
        ->assertMountedActionModalSee(['Old work', 'Middle work', 'Today work', '600.00 ₾']);

    $component
        ->set('mountedActions.0.data.cutoff_visit_id', $visits[2]->getKey())
        ->set('mountedActions.0.data.until', today()->subDay()->toDateString())
        ->assertActionDataSet(['cutoff_visit_id' => null])
        ->assertMountedActionModalSee(['Old work', 'Middle work', '300.00 ₾'])
        ->assertMountedActionModalDontSee(['Today work']);

    $component->call(
        'callSchemaComponentMethod',
        'mountedActionSchema0.salary-cutoff',
        'getSearchResultsForJs',
        ['search' => 'Reactive'],
    );
    $searchResults = collect(data_get($component->effects, 'returns.0'));
    expect($searchResults)->toHaveCount(2)
        ->and($searchResults->firstWhere('value', (string) $visits[0]->getKey())['label'] ?? null)
        ->toBe('Reactive Patient — Visit #'.$visits[0]->getKey())
        ->and($searchResults->firstWhere('value', (string) $visits[1]->getKey())['label'] ?? null)
        ->toBe('Reactive Patient — Visit #'.$visits[1]->getKey());

    $cutoffReport = app(DoctorCompensationCalculator::class)->calculate(
        $doctor->getKey(),
        today()->subDays(2)->toDateString(),
        today()->subDay()->toDateString(),
        50,
        $visits[0]->getKey(),
    );
    expect(collect($cutoffReport['details'])->pluck('visit_id')->all())->toBe([$visits[0]->getKey()]);

    $component
        ->set('mountedActions.0.data.cutoff_visit_id', $visits[1]->getKey())
        ->set('mountedActions.0.data.from', today()->subDay()->toDateString())
        ->assertActionDataSet(['cutoff_visit_id' => null])
        ->assertMountedActionModalSee(['Middle work', '200.00 ₾'])
        ->assertMountedActionModalDontSee(['Old work', 'Today work']);

    expect(app(DoctorCompensationCalculator::class)->cutoffVisitOptions(
        $doctor->getKey(),
        today()->subDay()->toDateString(),
        today()->subDay()->toDateString(),
    ))->toHaveCount(1)->toHaveKey($visits[1]->getKey());
});

test('salary modal date filtering stays reactive for each real doctor data shape', function (string $firstName, string $lastName) {
    $this->actingAs(User::factory()->create());
    $doctor = Doctor::create([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'compensation_percentage' => 40,
        'is_active' => true,
    ]);
    $patient = Patient::create(['first_name' => 'დავით', 'last_name' => 'კილაძე']);

    foreach ([
        ['date' => '2025-08-23', 'name' => 'Legacy work'],
        ['date' => '2026-08-07', 'name' => 'Backdated work'],
        ['date' => '2026-08-22', 'name' => 'Included work'],
        ['date' => '2026-08-24', 'name' => 'Later work'],
    ] as $data) {
        $visit = Visit::create([
            'patient_id' => $patient->getKey(),
            'doctor_id' => $doctor->getKey(),
            'visit_date' => $data['date'],
            'currency' => 'GEL',
        ]);
        $visit->treatmentCaseItems()->create([
            'custom_service_name' => $data['name'],
            'quantity' => 1,
            'unit_price' => 100,
        ]);
    }

    $component = Livewire::test(ViewDoctor::class, ['record' => $doctor->getRouteKey()])
        ->mountAction(TestAction::make('calculateSalary')->schemaComponent('compensation'))
        ->set('mountedActions.0.data.from', '2025-01-01')
        ->set('mountedActions.0.data.until', '2026-08-25')
        ->assertMountedActionModalSee(['Legacy work', 'Backdated work', 'Included work', 'Later work'])
        ->set('mountedActions.0.data.until', '2026-08-23')
        ->assertMountedActionModalSee(['Legacy work', 'Backdated work', 'Included work'])
        ->assertMountedActionModalDontSee(['Later work']);

    $component->set('mountedActions.0.data.until', '2026-08-22');
    $component->call(
        'callSchemaComponentMethod',
        'mountedActionSchema0.salary-cutoff',
        'getSearchResultsForJs',
        ['search' => 'დავით'],
    );
    expect(data_get($component->effects, 'returns.0'))->not->toBeEmpty();
})->with([
    'ლევან ბერიკაშვილი' => ['ლევან', 'ბერიკაშვილი'],
    'დავით ჭუმბურიძე' => ['დავით', 'ჭუმბურიძე'],
    'ნოდარ ელიშაკოვი' => ['ნოდარ', 'ელიშაკოვი'],
]);

test('settlement snapshots exact items and excludes them from the next salary', function () {
    $user = User::factory()->create();
    $doctor = Doctor::create(['first_name' => 'Snapshot', 'last_name' => 'Doctor', 'compensation_percentage' => 40, 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Snapshot', 'last_name' => 'Patient']);
    $visit = Visit::create(['patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(), 'visit_date' => today(), 'currency' => 'GEL']);
    $first = $visit->treatmentCaseItems()->create(['custom_service_name' => 'First work', 'quantity' => 1, 'unit_price' => 1000]);
    $second = $visit->treatmentCaseItems()->create(['custom_service_name' => 'Second work', 'quantity' => 2, 'unit_price' => 500]);
    $first->directExpenses()->create(['name' => 'Lab', 'amount' => 200, 'currency' => 'GEL']);

    $settlements = app(SalarySettlementService::class)->settle(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 40, $user->getKey(),
    );
    $settlement = $settlements[0];

    expect($settlement->items)->toHaveCount(2)
        ->and($settlement->doctor_id)->toBe($doctor->getKey())
        ->and($settlement->created_by)->toBe($user->getKey())
        ->and($settlement->status)->toBe('confirmed')
        ->and($settlement->period_start->isSameDay(today()))->toBeTrue()
        ->and($settlement->period_end->isSameDay(today()))->toBeTrue()
        ->and((float) $settlement->performed_total)->toBe(2000.0)
        ->and((float) $settlement->direct_expense_total)->toBe(200.0)
        ->and((float) $settlement->base_total)->toBe(1800.0)
        ->and((float) $settlement->salary_total)->toBe(720.0)
        ->and($settlement->items->pluck('visit_treatment_case_id')->all())->toContain($first->getKey(), $second->getKey())
        ->and($settlement->last_included_item->visit_id)->toBe($visit->getKey())
        ->and($settlement->last_included_item->visit->patient->full_name)->toBe($patient->full_name);

    $summary = app(DoctorCompensationCalculator::class)->summary($doctor);
    expect($summary['last_patient'])->toBe($patient->full_name)
        ->and($summary['last_visit_id'])->toBe($visit->getKey())
        ->and($summary['last_salary'])->toBe('720.00 ₾');

    $first->update(['unit_price' => 5000]);
    expect((float) $settlement->fresh()->performed_total)->toBe(2000.0)
        ->and(app(DoctorCompensationCalculator::class)->calculate(
            $doctor->getKey(), today()->toDateString(), today()->toDateString(), 40,
        )['details'])->toBeEmpty();
});

test('new work on the same settlement date remains for the next salary', function () {
    $doctor = Doctor::create(['first_name' => 'Same day', 'last_name' => 'Doctor', 'compensation_percentage' => 25, 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Same day', 'last_name' => 'Patient']);
    $visit = Visit::create(['patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(), 'visit_date' => today(), 'currency' => 'GEL']);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Settled work', 'quantity' => 1, 'unit_price' => 100]);
    app(SalarySettlementService::class)->settle($doctor->getKey(), today()->toDateString(), today()->toDateString(), 25, null);

    $laterVisit = Visit::create(['patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(), 'visit_date' => today(), 'currency' => 'GEL']);
    $laterItem = $laterVisit->treatmentCaseItems()->create(['custom_service_name' => 'Later work', 'quantity' => 1, 'unit_price' => 300]);
    $report = app(DoctorCompensationCalculator::class)->calculate($doctor->getKey(), today()->toDateString(), today()->toDateString(), 25);

    expect($report['details'])->toHaveCount(1)
        ->and($report['details'][0]['items'][0]['id'])->toBe($laterItem->getKey())
        ->and($report['totals']['GEL']['doctor_share'])->toBe(75.0)
        ->and(SalarySettlement::query()->count())->toBe(1);
});

test('doctor with no unsettled work cannot create an empty settlement', function () {
    $doctor = Doctor::create(['first_name' => 'Empty', 'last_name' => 'Doctor', 'compensation_percentage' => 30, 'is_active' => true]);

    expect(fn () => app(SalarySettlementService::class)->settle(
        $doctor->getKey(), today()->toDateString(), today()->toDateString(), 30, null,
    ))->toThrow(ValidationException::class);
});
