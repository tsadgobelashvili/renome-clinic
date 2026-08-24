<?php

use App\Filament\Resources\DirectExpenses\DirectExpenseResource;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

test('selected treatment item with an empty quantity defaults to one in visit total calculation', function () {
    expect(Visit::totalFromTreatmentItemState([
        [
            'treatment_case_id' => 10,
            'quantity' => null,
            'unit_price' => 500,
        ],
    ]))->toBe(500.0);
});

function createTreatmentCaseTestRecords(): array
{
    $patient = Patient::create([
        'first_name' => 'Case',
        'last_name' => 'Patient',
    ]);

    $doctor = Doctor::create([
        'first_name' => 'Case',
        'last_name' => 'Doctor',
        'is_active' => true,
    ]);

    $visit = Visit::create([
        'patient_id' => $patient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => now()->toDateString(),
    ]);

    return [$patient, $doctor, $visit];
}

function createTreatmentCase(string $name, bool $isActive = true, string $category = 'surgery'): TreatmentCase
{
    return TreatmentCase::create([
        'name' => $name,
        'category' => $category,
        'is_active' => $isActive,
    ]);
}

test('catalog treatment requires one of the supported structured categories', function () {
    $treatment = createTreatmentCase('დაბჟენა', true, 'therapy');
    $pediatricTreatment = createTreatmentCase('სარძევე კბილის მკურნალობა', true, 'pediatric_dentistry');

    expect($treatment->category)->toBe('therapy')
        ->and($treatment->category_label)->toBe('თერაპია')
        ->and(array_key_last(TreatmentCase::CATEGORIES))->toBe('tomography')
        ->and($pediatricTreatment->category_label)->toBe('ბავშვთა სტომატოლოგია')
        ->and(fn () => createTreatmentCase('Unknown', true, 'other'))
        ->toThrow(ValidationException::class);
});

test('catalog treatment stores a reusable default price', function () {
    $treatment = TreatmentCase::create([
        'name' => 'დროებითი პროთეზი',
        'category' => 'orthopedics',
        'default_price' => 350,
        'is_active' => true,
    ]);

    expect((float) $treatment->default_price)->toBe(350.0)
        ->and(fn () => TreatmentCase::create([
            'name' => 'Invalid price',
            'category' => 'therapy',
            'default_price' => -1,
        ]))->toThrow(ValidationException::class);
});

test('catalog price is optional while a visit item actual price is required', function () {
    [, , $visit] = createTreatmentCaseTestRecords();
    $treatment = createTreatmentCase('Treatment without catalog price', true, 'therapy');

    expect($treatment->default_price)->toBeNull()
        ->and(fn () => $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $treatment->getKey(),
            'quantity' => 1,
        ]))->toThrow(ValidationException::class, 'მიუთითეთ შესრულებული მანიპულაციის ფასი.');

    $item = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 2,
        'unit_price' => 125,
    ]);
    $visit->syncTreatmentItemsTotal();

    expect((float) $item->unit_price)->toBe(125.0)
        ->and($item->manipulation_total)->toBe(250.0)
        ->and((float) $visit->fresh()->total_price)->toBe(250.0);
});

test('a visit can store multiple independent treatment case items', function () {
    [, , $visit] = createTreatmentCaseTestRecords();

    $surgery = createTreatmentCase('ექსტრაქცია');
    $implantology = createTreatmentCase('იმპლანტაცია');
    $other = createTreatmentCase('ძვლის აუგმენტაცია');

    $visit->treatmentCaseItems()->createMany([
        [
            'treatment_case_id' => $surgery->getKey(),
            'quantity' => 2,
            'unit_price' => 0,
            'teeth' => '15, 25',
            'comment' => 'ექსტრაქცია',
        ],
        [
            'treatment_case_id' => $implantology->getKey(),
            'quantity' => 4,
            'unit_price' => 0,
            'teeth' => '14, 16, 24, 26',
        ],
        [
            'treatment_case_id' => $other->getKey(),
            'quantity' => 1,
            'unit_price' => 0,
            'teeth' => 'ზედა მარჯვენა მხარე',
            'comment' => 'ძვლის აუგმენტაცია',
        ],
    ]);

    expect($visit->treatmentCaseItems)->toHaveCount(3)
        ->and($visit->treatmentCases)->toHaveCount(3)
        ->and($visit->treatmentCaseItems->pluck('quantity')->sort()->values()->all())->toBe([1, 2, 4]);
});

test('a visit manipulation stores multiple direct expenses and calculates its net amount', function () {
    [, , $visit] = createTreatmentCaseTestRecords();
    $treatment = createTreatmentCase('ცირკონის გვირგვინი', true, 'orthopedics');
    $item = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 1,
        'unit_price' => 500,
    ]);

    $item->directExpenses()->createMany([
        ['name' => 'ლაბორატორია', 'amount' => 100],
        ['name' => 'ტექნიკოსი', 'amount' => 50],
    ]);

    $item->refresh();

    expect($item->manipulation_total)->toBe(500.0)
        ->and($item->direct_expenses_total)->toBe(150.0)
        ->and($item->net_amount)->toBe(350.0)
        ->and($item->directExpenses->every(fn ($expense): bool => $expense->currency === 'GEL'))->toBeTrue()
        ->and($item->directExpenses()->count())->toBe(2);
});

test('visit total is synchronized from all manipulation quantities and unit prices', function () {
    [, , $visit] = createTreatmentCaseTestRecords();
    $implant = createTreatmentCase('Zimmer TSV');
    $other = createTreatmentCase('Another treatment', true, 'therapy');

    $first = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $implant->getKey(),
        'quantity' => 5,
        'unit_price' => 1200,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $other->getKey(),
        'quantity' => 1,
        'unit_price' => 500,
    ]);

    $visit->syncTreatmentItemsTotal();
    expect((float) $visit->fresh()->total_price)->toBe(6500.0);

    $first->update(['quantity' => 4, 'unit_price' => 1000]);
    $visit->syncTreatmentItemsTotal();
    expect((float) $visit->fresh()->total_price)->toBe(4500.0);

    $first->delete();
    $visit->syncTreatmentItemsTotal();
    expect((float) $visit->fresh()->total_price)->toBe(500.0);
});

test('direct expenses cannot exceed the manipulation total', function () {
    [, , $visit] = createTreatmentCaseTestRecords();
    $treatment = createTreatmentCase('ვინირი', true, 'orthopedics');
    $item = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 2,
        'unit_price' => 100,
    ]);

    $item->directExpenses()->create(['name' => 'ლაბორატორია', 'amount' => 150]);

    expect(fn () => $item->directExpenses()->create(['name' => 'ტექნიკოსი', 'amount' => 51]))
        ->toThrow(ValidationException::class)
        ->and(fn () => $item->update(['unit_price' => 70]))
        ->toThrow(ValidationException::class);
});

test('direct expense totals only include the visit currency', function () {
    [, , $visit] = createTreatmentCaseTestRecords();
    $visit->update(['currency' => 'GEL']);
    $treatment = createTreatmentCase('Currency treatment', true, 'therapy');
    $item = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 1,
        'unit_price' => 500,
    ]);

    $item->directExpenses()->create(['name' => 'GEL expense', 'amount' => 100, 'currency' => 'GEL']);
    $item->directExpenses()->create(['name' => 'USD expense', 'amount' => 200, 'currency' => 'USD']);

    expect($item->refresh()->direct_expenses_total)->toBe(100.0)
        ->and($item->net_amount)->toBe(400.0)
        ->and((float) $item->directExpenses()->where('currency', 'USD')->sum('amount'))->toBe(200.0);
});

test('deleting a visit manipulation deletes only its dependent direct expenses', function () {
    [, , $visit] = createTreatmentCaseTestRecords();
    $treatment = createTreatmentCase('პროთეზი', true, 'orthopedics');
    $item = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 1,
        'unit_price' => 300,
    ]);
    $expense = $item->directExpenses()->create(['name' => 'ლაბორატორია', 'amount' => 100]);

    $item->delete();

    $this->assertDatabaseMissing('direct_expenses', ['id' => $expense->getKey()]);
    $this->assertDatabaseHas('visits', ['id' => $visit->getKey()]);
});

test('direct expenses quick-entry page is registered', function () {
    [, , $visit] = createTreatmentCaseTestRecords();
    $treatment = createTreatmentCase('იმპლანტაცია');
    $item = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatment->getKey(),
        'quantity' => 1,
        'unit_price' => 500,
    ]);
    $item->directExpenses()->create(['name' => 'ლაბორატორია', 'amount' => 100]);

    expect(DirectExpenseResource::getUrl('index'))->toContain('/admin/direct-expenses')
        ->and(DirectExpenseResource::getEloquentQuery()->find($item->getKey())->direct_expenses_sum_amount)
        ->toEqual(100);
});

test('an exact treatment case item cannot be duplicated within a visit', function () {
    [, , $visit] = createTreatmentCaseTestRecords();
    $treatmentCase = createTreatmentCase('იმპლანტაცია');

    $data = [
        'treatment_case_id' => $treatmentCase->getKey(),
        'quantity' => 2,
        'unit_price' => 0,
        'teeth' => '14, 16',
        'comment' => 'Same details',
    ];

    $visit->treatmentCaseItems()->create($data);

    expect(fn () => $visit->treatmentCaseItems()->create($data))
        ->toThrow(ValidationException::class);
});

test('deleting a treatment case preserves its visit and removes only the related item', function () {
    [, , $visit] = createTreatmentCaseTestRecords();
    $treatmentCase = createTreatmentCase('არხის მკურნალობა');

    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatmentCase->getKey(),
        'quantity' => 1,
        'unit_price' => 0,
    ]);

    $treatmentCase->delete();

    expect($visit->refresh()->exists)->toBeTrue()
        ->and($visit->treatmentCaseItems()->count())->toBe(0);
});

test('a catalog treatment case can be reused by visits of different patients', function () {
    [, $doctor, $firstVisit] = createTreatmentCaseTestRecords();

    $otherPatient = Patient::create([
        'first_name' => 'Other',
        'last_name' => 'Patient',
    ]);

    $secondVisit = Visit::create([
        'patient_id' => $otherPatient->getKey(),
        'doctor_id' => $doctor->getKey(),
        'visit_date' => now()->toDateString(),
    ]);

    $treatmentCase = createTreatmentCase('ვინირი');

    $firstVisit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatmentCase->getKey(),
        'quantity' => 1,
        'unit_price' => 0,
    ]);

    $secondVisit->treatmentCaseItems()->create([
        'treatment_case_id' => $treatmentCase->getKey(),
        'quantity' => 2,
        'unit_price' => 0,
    ]);

    expect($treatmentCase->visits()->count())->toBe(2);
});
