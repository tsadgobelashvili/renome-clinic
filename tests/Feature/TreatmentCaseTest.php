<?php

use App\Filament\Resources\DirectExpenses\DirectExpenseResource;
use App\Filament\Resources\DirectExpenses\Pages\ListDirectExpenses;
use App\Filament\Resources\DirectExpenses\Tables\DirectExpensesTable;
use App\Filament\Resources\TreatmentCases\Pages\ListTreatmentCases;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

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
    $consultation = createTreatmentCase('კონსულტაცია', true, 'consultation');

    expect($treatment->category)->toBe('therapy')
        ->and($treatment->category_label)->toBe('თერაპია')
        ->and(array_key_last(TreatmentCase::CATEGORIES))->toBe('pediatric_dentistry')
        ->and($pediatricTreatment->category_label)->toBe('ბავშვთა')
        ->and($consultation->category_label)->toBe('კონსულტაცია')
        ->and(fn () => createTreatmentCase('Unknown', true, 'other'))
        ->toThrow(ValidationException::class);
});

test('catalog category dropdown filters records and includes database categories', function () {
    $this->actingAs(User::factory()->create());
    $pediatric = createTreatmentCase('ბავშვთა მომსახურება', true, 'pediatric_dentistry');
    $therapy = createTreatmentCase('თერაპიული მომსახურება', true, 'therapy');

    DB::table('treatment_cases')->insert([
        'name' => 'Legacy category service',
        'category' => 'legacy_category',
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(TreatmentCase::categoryOptions())
        ->toHaveKey('pediatric_dentistry', 'ბავშვთა')
        ->toHaveKey('legacy_category', 'legacy_category');

    Livewire::test(ListTreatmentCases::class)
        ->assertSuccessful()
        ->assertSee('ყველა კატეგორია')
        ->filterTable('category', 'pediatric_dentistry')
        ->assertCanSeeTableRecords([$pediatric])
        ->assertCanNotSeeTableRecords([$therapy]);
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

test('a manual manipulation is stored on the visit item without creating a catalog service', function () {
    [, , $visit] = createTreatmentCaseTestRecords();
    $catalogCount = TreatmentCase::query()->count();

    $item = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => null,
        'custom_service_name' => 'დროებითი კონსტრუქცია',
        'quantity' => 2,
        'unit_price' => 175,
    ]);
    $visit->syncTreatmentItemsTotal();

    expect($item->treatment_case_id)->toBeNull()
        ->and($item->display_name)->toBe('დროებითი კონსტრუქცია')
        ->and($item->category_label)->toBe('ხელით დამატებული')
        ->and($item->manipulation_total)->toBe(350.0)
        ->and((float) $visit->fresh()->total_price)->toBe(350.0)
        ->and(TreatmentCase::query()->count())->toBe($catalogCount);
});

test('a manual manipulation requires a name and remains distinct from catalog statistics', function () {
    [, , $visit] = createTreatmentCaseTestRecords();

    expect(fn () => $visit->treatmentCaseItems()->create([
        'treatment_case_id' => null,
        'quantity' => 1,
        'unit_price' => 100,
    ]))->toThrow(ValidationException::class, 'მიუთითეთ მანიპულაციის დასახელება.');
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
        ['name' => 'ლაბორატორია', 'quantity' => 2, 'amount' => 100],
        ['name' => 'ტექნიკოსი', 'amount' => 50],
    ]);

    $item->refresh();

    expect($item->manipulation_total)->toBe(500.0)
        ->and($item->direct_expenses_total)->toBe(150.0)
        ->and($item->net_amount)->toBe(350.0)
        ->and($item->directExpenses->first()->quantity)->toBe(2)
        ->and($item->directExpenses->last()->quantity)->toBe(1)
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

    $record = DirectExpenseResource::getEloquentQuery()->findOrFail($visit->getKey());

    expect(DirectExpenseResource::getUrl('index'))->toContain('/admin/direct-expenses')
        ->and($record)->toBeInstanceOf(Visit::class)
        ->and($record->treatmentCaseItems)->toHaveCount(1)
        ->and(DirectExpensesTable::visitWorkTotal($record))->toBe(500.0)
        ->and(DirectExpensesTable::visitExpenseTotal($record))->toBe(100.0);
});

test('direct expenses exposes only the inline period doctor expense status and search controls', function () {
    $this->actingAs(User::factory()->create());

    $component = Livewire::test(ListDirectExpenses::class)
        ->assertSuccessful()
        ->assertSeeHtml('renome-direct-expenses-toolbar')
        ->assertSee('ყველა ექიმი')
        ->assertSee('შევსებული')
        ->assertSee('არ არის შევსებული');

    expect(array_keys($component->instance()->getTable()->getFilters()))->toBe([
        'expense_status',
        'visit_date',
        'doctor_id',
    ]);
});

test('direct expenses table groups manipulations by visit and preserves expense editing', function () {
    $this->actingAs(User::factory()->create());
    [, , $visit] = createTreatmentCaseTestRecords();
    $firstTreatment = createTreatmentCase('პროთეზი', true, 'orthopedics');
    $secondTreatment = createTreatmentCase('ცირკონის გვირგვინი', true, 'orthopedics');
    $first = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $firstTreatment->getKey(),
        'quantity' => 1,
        'unit_price' => 500,
    ]);
    $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $secondTreatment->getKey(),
        'quantity' => 5,
        'unit_price' => 650,
    ]);

    $component = Livewire::test(ListDirectExpenses::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$visit])
        ->assertCountTableRecords(1)
        ->assertSee('პროთეზი')
        ->assertSee('ცირკონის გვირგვინი')
        ->set('tableSearch', 'ცირკონის გვირგვინი')
        ->assertCanSeeTableRecords([$visit])
        ->call('saveExpense', $first->getKey(), null, 'ლაბ', 100)
        ->assertNotified('შენახულია');

    $expense = $first->directExpenses()->sole();
    $component
        ->call('saveExpense', $first->getKey(), $expense->getKey(), 'ტექნიკი', 150)
        ->call('saveExpense', $first->getKey(), null, 'ლაბ', 70)
        ->assertNotified('შენახულია');

    expect($first->refresh()->direct_expenses_total)->toBe(220.0)
        ->and($first->directExpenses)->toHaveCount(2)
        ->and($first->directExpenses->pluck('name')->all())->toContain('ტექნიკი', 'ლაბ')
        ->and(DirectExpenseResource::getEloquentQuery()->count())->toBe(1);
});

test('direct expenses salary page only includes surgery and orthopedics and filters by doctor', function () {
    $this->actingAs(User::factory()->create());
    [$patient, $doctor, $visit] = createTreatmentCaseTestRecords();
    $otherDoctor = Doctor::create(['first_name' => 'Other', 'last_name' => 'Doctor', 'is_active' => true]);
    $surgery = createTreatmentCase('ქირურგიული სამუშაო', true, 'surgery');
    $orthopedics = createTreatmentCase('ორთოპედიული სამუშაო', true, 'orthopedics');
    $tomography = createTreatmentCase('3D CT test', true, 'tomography');
    $consultation = createTreatmentCase('კონსულტაცია test', true, 'consultation');

    $surgeryItem = $visit->treatmentCaseItems()->create(['treatment_case_id' => $surgery->getKey(), 'quantity' => 1, 'unit_price' => 500]);
    $orthopedicItem = $visit->treatmentCaseItems()->create(['treatment_case_id' => $orthopedics->getKey(), 'quantity' => 1, 'unit_price' => 600]);
    $tomographyItem = $visit->treatmentCaseItems()->create(['treatment_case_id' => $tomography->getKey(), 'quantity' => 1, 'unit_price' => 60]);
    $surgeryItem->directExpenses()->create(['name' => 'Surgery expense', 'amount' => 100]);
    $orthopedicItem->directExpenses()->create(['name' => 'Orthopedic expense', 'amount' => 150]);
    $tomographyItem->directExpenses()->create(['name' => 'CT expense', 'amount' => 50]);

    $otherVisit = Visit::create(['patient_id' => $patient->getKey(), 'doctor_id' => $otherDoctor->getKey(), 'visit_date' => today()]);
    $otherVisit->treatmentCaseItems()->create(['treatment_case_id' => $surgery->getKey(), 'quantity' => 1, 'unit_price' => 300]);
    $consultationVisit = Visit::create(['patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(), 'visit_date' => today(), 'visit_type' => 'consultation']);
    $consultationVisit->treatmentCaseItems()->create(['treatment_case_id' => $consultation->getKey(), 'quantity' => 1, 'unit_price' => 50]);

    $record = DirectExpenseResource::getEloquentQuery()->findOrFail($visit->getKey());
    expect($record->treatmentCaseItems)->toHaveCount(2)
        ->and($record->treatmentCaseItems->pluck('id')->all())->toContain($surgeryItem->getKey(), $orthopedicItem->getKey())
        ->and(DirectExpensesTable::visitExpenseTotal($record))->toBe(250.0)
        ->and(DirectExpenseResource::getEloquentQuery()->whereKey($consultationVisit)->exists())->toBeFalse();

    Livewire::test(ListDirectExpenses::class)
        ->assertSuccessful()
        ->assertSet('tableFilters.visit_date.from', today()->subDays(13)->toDateString())
        ->assertSet('tableFilters.visit_date.until', today()->toDateString())
        ->assertSee('ყველა ექიმი')
        ->assertSee('ქირურგიული სამუშაო')
        ->assertSee('ორთოპედიული სამუშაო')
        ->assertDontSee('3D CT test')
        ->assertDontSee('კონსულტაცია test')
        ->filterTable('doctor_id', $doctor->getKey())
        ->assertCanSeeTableRecords([$visit])
        ->assertCanNotSeeTableRecords([$otherVisit]);
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
