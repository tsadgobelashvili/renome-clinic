<?php

use App\Filament\Pages\FinanceReports;
use App\Filament\Resources\TreatmentCases\Pages\CreateTreatmentCase;
use App\Filament\Resources\TreatmentCases\Pages\ManageCategories;
use App\Filament\Resources\TreatmentCases\Pages\ManageGroups;
use App\Filament\Resources\TreatmentCases\Pages\UncategorizedProcedures;
use App\Filament\Resources\TreatmentCases\TreatmentCaseResource;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ProcedureCatalogMapping;
use App\Models\TreatmentCase;
use App\Models\TreatmentCategory;
use App\Models\TreatmentStatisticsGroup;
use App\Models\User;
use App\Models\Visit;
use App\Services\ProcedureClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER])));

test('categories and category-owned groups can be managed without changing stable IDs', function () {
    Livewire::test(ManageCategories::class)->callTableAction('create', data: ['name' => 'ახალი კატეგორია'])->assertHasNoTableActionErrors();
    $category = TreatmentCategory::where('name', 'ახალი კატეგორია')->sole();
    Livewire::test(ManageGroups::class)->callTableAction('create', data: ['name' => 'ახალი ჯგუფი', 'category_id' => $category->id])->assertHasNoTableActionErrors();
    $group = TreatmentStatisticsGroup::where('name', 'ახალი ჯგუფი')->sole();
    Livewire::test(ManageCategories::class)->callTableAction('edit', $category, ['name' => 'შეცვლილი კატეგორია'])->assertHasNoTableActionErrors();
    expect($category->fresh()->name)->toBe('შეცვლილი კატეგორია')->and($group->fresh()->category_id)->toBe($category->id);
    expect(fn () => $category->delete())->toThrow(ValidationException::class);
    Livewire::test(ManageGroups::class)->callTableAction('delete', $group)->assertHasNoTableActionErrors();
    Livewire::test(ManageCategories::class)->callTableAction('delete', $category)->assertHasNoTableActionErrors();
    expect(TreatmentCategory::find($category->id))->toBeNull();
});

test('catalog groups are filtered by category and stale selections are cleared', function () {
    $page = Livewire::test(CreateTreatmentCase::class)->set('data.category', 'therapy');
    $page->assertFormFieldExists('classification-group-therapy-group', fn ($field) => isset($field->getSearchResults('ენდო')['endodontics']) && ! isset($field->getOptions()['zircon']))
        ->set('data.statistics_group', 'endodontics')->assertHasNoFormErrors(['statistics_group'])
        ->set('data.category', 'orthopedics')->assertFormSet(['statistics_group' => null]);
    expect(fn () => TreatmentCase::create(['name' => 'Invalid', 'category' => 'orthopedics', 'statistics_group' => 'endodontics']))->toThrow(ValidationException::class);
});

test('assignment modal creates a group in the selected category and stores its actual ID', function () {
    $visit = Visit::create(['patient_id' => Patient::create(['first_name' => 'Mapped', 'last_name' => 'Patient'])->id, 'visit_type' => 'treatment', 'visit_date' => today()]);
    $item = $visit->treatmentCaseItems()->create(['custom_service_name' => 'Custom root treatment', 'quantity' => 1, 'unit_price' => 60]);
    $page = Livewire::test(UncategorizedProcedures::class)->mountTableAction('map', $item)
        ->setTableActionData(['category' => 'therapy', 'statistics_group_mode' => 'group'])
        ->callFormComponentAction('classification-group-therapy-group', 'createOption', ['name' => 'Root treatment group'], formName: 'mountedActionSchema0');
    $group = TreatmentStatisticsGroup::where('name', 'Root treatment group')->sole();
    expect($group->category_id)->toBe('therapy');
    expect($page->get('mountedActions.0.data.statistics_group'))->toBe($group->id);
    $page->callMountedTableAction()->assertHasNoTableActionErrors()->assertCanNotSeeTableRecords([$item]);
    $catalog = TreatmentCase::findOrFail(ProcedureCatalogMapping::sole()->treatment_case_id);
    expect($catalog->statistics_group)->toBe($group->id)->and($catalog->category)->toBe('therapy');
    expect($item->fresh()->treatment_case_id)->toBeNull();
    expect(fn () => $group->delete())->toThrow(ValidationException::class);
});

test('direct assignment preserves visits and does not invent a group', function () {
    $visit = Visit::create(['patient_id' => Patient::create(['first_name' => 'Direct', 'last_name' => 'Patient'])->id, 'visit_type' => 'treatment', 'visit_date' => today()]);
    $item = $visit->treatmentCaseItems()->create(['custom_service_name' => 'Direct custom', 'quantity' => 2, 'unit_price' => 70]);
    $before = $item->fresh()->getAttributes();
    Livewire::test(UncategorizedProcedures::class)->callTableAction('map', $item, ['category' => 'orthodontics', 'statistics_group_mode' => 'direct'])
        ->assertHasNoTableActionErrors()->assertCanNotSeeTableRecords([$item]);
    $resolved = ProcedureClassification::resolvedItems()->where('procedure_items.id', $item->id)->first();
    expect($resolved->category)->toBe('orthodontics')->and($resolved->statistics_group)->toBeNull()
        ->and($item->fresh()->getAttributes())->toBe($before);
});

test('statistics combines catalog manipulations using the managed group and current labels', function () {
    $group = TreatmentStatisticsGroup::create(['name' => 'Managed endodontics', 'category_id' => 'therapy']);
    $visit = Visit::create(['doctor_id' => Doctor::create(['first_name' => 'Test', 'last_name' => 'Doctor'])->id,
        'patient_id' => Patient::create(['first_name' => 'Stats', 'last_name' => 'Patient'])->id, 'visit_type' => 'treatment', 'visit_date' => today()]);
    foreach (['Root one', 'Root two'] as $name) {
        $catalog = TreatmentCase::create(['name' => $name, 'category' => 'therapy', 'statistics_group' => $group->id]);
        $visit->treatmentCaseItems()->create(['treatment_case_id' => $catalog->id, 'quantity' => 2, 'unit_price' => 100]);
    }
    $group->update(['name' => 'Renamed endodontics']);
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'doctors')->assertViewHas('doctorStatistics', function ($stats) use ($group) {
        $row = collect($stats['treatmentGroups'])->firstWhere('key', $group->id);

        return $row && $row['label'] === 'Renamed endodontics' && $row['quantity'] === 4 && $row['amount'] === 400.0 && count($row['breakdown']) === 2;
    });
});

test('hierarchy migration preserves cross-category legacy groups and rolls back safely', function () {
    $migration = require database_path('migrations/2026_09_23_120000_create_treatment_classification_tables.php');
    $migration->down();
    $id = DB::table('treatment_cases')->insertGetId([
        'name' => 'Legacy cross-category group', 'category' => 'therapy', 'statistics_group' => 'zircon',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $before = DB::table('treatment_cases')->where('id', $id)->first();
    $migration->up();
    $after = TreatmentCase::findOrFail($id);
    $group = TreatmentStatisticsGroup::findOrFail($after->statistics_group);
    expect($after->category)->toBe('therapy')->and($after->name)->toBe($before->name)
        ->and($group->category_id)->toBe('therapy')->and($group->name)->toBe('ცირკონი');
    $migration->down();
    expect(DB::table('treatment_cases')->where('id', $id)->value('statistics_group'))->toBe('zircon');
    $migration->up();
});

test('hierarchy pages preserve catalog access restrictions', function (string $role, bool $allowed) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    foreach (['categories', 'groups'] as $page) {
        $response = $this->get(TreatmentCaseResource::getUrl($page));
        $allowed ? $response->assertOk() : $response->assertForbidden();
    }
})->with([['owner', true], ['administrator', true], ['lab_technician', false]]);

test('diagnostic catalog work appears under the managed tomography category', function () {
    $group = TreatmentStatisticsGroup::create(['name' => 'Diagnostic imaging', 'category_id' => 'tomography']);
    $catalog = TreatmentCase::create(['name' => 'Diagnostic scan', 'category' => 'tomography', 'statistics_group' => $group->id]);
    $visit = Visit::create(['patient_id' => Patient::create(['first_name' => 'Scan', 'last_name' => 'Patient'])->id, 'visit_type' => 'diagnostic', 'visit_date' => today()]);
    $visit->treatmentCaseItems()->create(['treatment_case_id' => $catalog->id, 'quantity' => 1, 'unit_price' => 60]);
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'doctors')->assertViewHas('doctorStatistics', fn ($stats) => collect($stats['treatmentGroups'])->firstWhere('key', $group->id)['quantity'] === 1
        && $stats['consultations']['total'] === 0);
});
