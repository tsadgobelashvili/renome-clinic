<?php

use App\Filament\Pages\FinanceReports;
use App\Filament\Resources\Employees\Pages\EditEmployee;
use App\Filament\Resources\LabCases\Pages\EditLabCase;
use App\Filament\Resources\LabCases\Pages\ListLabCases;
use App\Models\Doctor;
use App\Models\Employee;
use App\Models\EmployeePosition;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\User;
use App\Services\EmployeePayrollService;
use App\Services\IsraeliLabSalaryItems;
use App\Services\LabPartyAutocomplete;
use App\Support\GeorgianNameTransliterator;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function labAssistant(array $attributes = []): Employee
{
    return Employee::create([
        'first_name' => 'დავით', 'last_name' => 'ჭუმბურიძე',
        'position_id' => EmployeePosition::where('name', 'Assistant')->sole()->id,
        'is_active' => true, ...$attributes,
    ]);
}

test('only active doctors and opted in active assistants appear with role free Latin assistant labels', function () {
    $doctor = Doctor::create(['first_name' => 'დავით', 'last_name' => 'ჭუმბურიძე', 'is_active' => true]);
    Doctor::create(['first_name' => 'Inactive', 'last_name' => 'Doctor', 'is_active' => false]);
    $assistant = labAssistant(['show_in_lab_doctor_list' => true]);
    $off = labAssistant(['first_name' => 'Disabled']);
    labAssistant(['first_name' => 'Inactive', 'show_in_lab_doctor_list' => true, 'is_active' => false]);
    labAssistant(['first_name' => 'Administrator', 'show_in_lab_doctor_list' => true,
        'position_id' => EmployeePosition::where('name', 'Administrator')->sole()->id]);
    $search = app(LabPartyAutocomplete::class);
    expect($off->fresh()->show_in_lab_doctor_list)->toBeFalse()
        ->and($search->practitionerOptions())->toHaveCount(2);
    foreach (['Davit Chumburidze', 'DAVIT', 'ჭუმბურიძე', 'დავით'] as $term) {
        expect($search->doctorSuggestions($term))->toContain($doctor->full_name, 'Davit Chumburidze');
    }
    expect($search->practitionerFromLabel($assistant->full_name.' — Assistant'))->toBe(['doctor_id' => null, 'assistant_employee_id' => $assistant->id])
        ->and($search->practitionerOptionLabel($doctor->id))->toBe($doctor->full_name)
        ->and($search->practitionerFromLabel('Davit Chumburidze'))->toBe(['doctor_id' => null, 'assistant_employee_id' => null]);
    $assistant->update(['show_in_lab_doctor_list' => false]);
    expect($search->doctorSuggestions('davit'))->toBe([$doctor->full_name]);
});

test('Latin stored assistant names support Georgian and case insensitive partial searches', function () {
    $assistant = labAssistant(['first_name' => 'Davit', 'last_name' => 'Chumburidze', 'show_in_lab_doctor_list' => true]);
    foreach (['დავით ჭუმბურიძე', 'DAVIT', 'chumbur', 'ჭუმბურიძე'] as $term) {
        expect(app(LabPartyAutocomplete::class)->doctorSuggestions($term))->toContain('Davit Chumburidze');
    }
    expect($assistant->fresh()->full_name)->toBe('Davit Chumburidze');
});

test('assistant search and selected labels reuse Latin formatting without changing stored names', function () {
    $assistant = labAssistant(['first_name' => 'მარინა', 'last_name' => 'სტეფანიანი', 'show_in_lab_doctor_list' => true]);
    $stored = $assistant->fresh()->getAttributes();
    $search = app(LabPartyAutocomplete::class);
    $label = GeorgianNameTransliterator::transliterate($assistant->full_name);
    foreach (['en', 'ka'] as $locale) {
        foreach (['marina', 'მარინა'] as $term) {
            expect($search->doctorSuggestions($term, $locale))->toBe([$label]);
        }
        expect($search->practitionerOptionLabel('employee:'.$assistant->id, $locale))->toBe($label);
    }
    expect($search->practitionerOptions('marina'))->toBe(['employee:'.$assistant->id => $label])
        ->and($search->practitionerFromLabel($label))->toBe(['doctor_id' => null, 'assistant_employee_id' => $assistant->id])
        ->and($label)->not->toContain('Assistant', 'ასისტენტი')
        ->and($assistant->fresh()->getAttributes())->toBe($stored);
});

test('Personnel toggle is assistant only and persists without changing position or salary', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $assistant = labAssistant(['salary_type' => 'fixed', 'monthly_salary_gel' => 1300]);
    Livewire::test(EditEmployee::class, ['record' => $assistant->id])
        ->assertFormFieldIsVisible('show_in_lab_doctor_list')
        ->fillForm(['show_in_lab_doctor_list' => true])->call('save')->assertHasNoFormErrors();
    expect($assistant->fresh()->show_in_lab_doctor_list)->toBeTrue()
        ->and($assistant->fresh()->position->name)->toBe('Assistant')
        ->and($assistant->fresh()->salary_type)->toBe('fixed');
    $other = labAssistant(['position_id' => EmployeePosition::where('name', 'Administrator')->sole()->id]);
    Livewire::test(EditEmployee::class, ['record' => $other->id])->assertFormFieldIsHidden('show_in_lab_doctor_list');
});

test('shared Lab account can create edit and filter assistant cases without creating a Doctor', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_LAB_TECHNICIAN]));
    $assistant = labAssistant(['show_in_lab_doctor_list' => true]);
    $patient = Patient::create(['first_name' => 'Lab', 'last_name' => 'Patient']);
    Livewire::test(ListLabCases::class)->mountAction('create')->fillForm([
        'case_date' => today()->toDateString(), 'source' => 'clinic',
        'mainWorks' => [['patient_search' => $patient->lab_selection_label,
            'doctor_search' => 'Davit Chumburidze', 'material' => 'zircon', 'quantity' => 1]],
    ])->assertFormFieldExists('mainWorks.0.doctor_search', fn ($field): bool => $field instanceof Select
        && ! $field->isNative()
        && in_array('Davit Chumburidze', $field->getSearchResults('Davit Chumburidze'), true)
        && $field->getOptionLabel() === 'Davit Chumburidze')
        ->assertActionDataSet(['doctor_id' => null, 'assistant_employee_id' => $assistant->id])
        ->callMountedAction()->assertHasNoActionErrors();
    $case = LabCase::sole();
    expect($case->doctor_id)->toBeNull()->and($case->assistant_employee_id)->toBe($assistant->id)
        ->and($case->doctor_display)->toBe('Davit Chumburidze')->and(Doctor::count())->toBe(0);
    Livewire::test(ListLabCases::class)->filterTable('toolbar', ['doctor_id' => 'employee:'.$assistant->id])
        ->assertCanSeeTableRecords([$case]);
    $assistant->update(['is_active' => false, 'show_in_lab_doctor_list' => false]);
    Livewire::test(EditLabCase::class, ['record' => $case->id])->fillForm(['notes' => 'Historical link retained'])
        ->call('save')->assertHasNoFormErrors();
    expect($case->fresh()->assistant_employee_id)->toBe($assistant->id)
        ->and($case->fresh()->doctor_display)->toBe('Davit Chumburidze');
    expect(fn () => $assistant->delete())->toThrow(ValidationException::class);
});

test('assistant laboratory work leaves fixed payroll and doctor salary eligibility unchanged', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $assistant = labAssistant(['show_in_lab_doctor_list' => true]);
    $assistant->payrollSettings()->create(['source' => 'clinic', 'salary_model' => 'fixed_net', 'net_amount' => 1300,
        'currency' => 'GEL', 'is_active' => true, 'effective_from' => '2026-09-01', 'default_payment_method' => 'bank_transfer']);
    $service = app(EmployeePayrollService::class);
    $before = $service->calculate($assistant, 'clinic', '2026-09-01', '2026-09-30');
    $doctor = Doctor::create(['first_name' => 'Real', 'last_name' => 'Doctor', 'is_active' => true, 'israeli_lab_zircon_rate' => 100]);
    $patient = Patient::create(['first_name' => 'Test', 'last_name' => 'Patient']);
    $existing = LabCase::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'source' => 'israeli', 'case_date' => today()]);
    $doctorWork = $existing->mainWorks()->create(['material' => 'zircon', 'quantity' => 1]);
    $baselineStatistics = null;
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', function (array $stats) use (&$baselineStatistics): bool {
            $baselineStatistics = $stats;

            return true;
        });
    foreach (['clinic', 'israeli', 'external'] as $source) {
        $case = LabCase::create(['patient_id' => $patient->id, 'assistant_employee_id' => $assistant->id, 'source' => $source, 'case_date' => today()]);
        $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 3]);
    }
    expect($service->calculate($assistant->fresh(), 'clinic', '2026-09-01', '2026-09-30')['net_amount'])->toBe($before['net_amount'])
        ->and($before['net_amount'])->toBe(1300.0)
        ->and(Doctor::count())->toBe(1)
        ->and(LabCase::whereNotNull('doctor_id')->pluck('id')->all())->toBe([$existing->id])
        ->and(app(IsraeliLabSalaryItems::class)->eligible($doctor)->modelKeys())->toBe([$doctorWork->id])
        ->and($existing->fresh()->doctor->is($doctor))->toBeTrue();
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', fn (array $stats): bool => $stats === $baselineStatistics)
        ->assertDontSee($assistant->full_name);
});

test('new case cannot reference an ineligible assistant or two practitioners', function () {
    $assistant = labAssistant();
    $patient = Patient::create(['first_name' => 'Test', 'last_name' => 'Patient']);
    $data = ['patient_id' => $patient->id, 'assistant_employee_id' => $assistant->id, 'source' => 'clinic', 'case_date' => today()];
    expect(fn () => LabCase::create($data))->toThrow(ValidationException::class);
    $assistant->update(['show_in_lab_doctor_list' => true]);
    $doctor = Doctor::create(['first_name' => 'Real', 'last_name' => 'Doctor', 'is_active' => true]);
    expect(fn () => LabCase::create([...$data, 'doctor_id' => $doctor->id]))->toThrow(ValidationException::class);
});
