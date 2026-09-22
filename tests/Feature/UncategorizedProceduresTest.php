<?php

use App\Filament\Pages\FinanceReports;
use App\Filament\Resources\TreatmentCases\Pages\UncategorizedProcedures;
use App\Filament\Resources\TreatmentCases\TreatmentCaseResource;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\ProcedureCatalogMapping;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use App\Services\FullDiscountStatistics;
use App\Services\ProcedureClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Mapping', 'last_name' => 'Doctor', 'is_active' => true]);
    $this->patient = Patient::create(['first_name' => 'Mapping', 'last_name' => 'Patient']);
    $this->makeProcedure = function (string $name, bool $discount = false) {
        $visit = Visit::create(['doctor_id' => $this->doctor->id, 'patient_id' => $this->patient->id,
            'visit_type' => 'treatment', 'visit_date' => today()->subDay(), 'total_price' => 100, 'currency' => 'GEL',
            'discount_type' => $discount ? 'percent' : null, 'discount_value' => $discount ? 100 : 0,
            'discount_reason' => $discount ? 'gift' : null]);

        return $visit->treatmentCaseItems()->create(['custom_service_name' => $name, 'quantity' => 1, 'unit_price' => 100]);
    };
});

test('unknown procedures use Uncategorized and mapping updates historical reports without touching clinical records', function () {
    $item = ($this->makeProcedure)('Unknown implantation');
    $discount = ($this->makeProcedure)('Unknown implantation', true);
    $before = $item->fresh()->getRawOriginal();
    $salary = fn () => app(DoctorCompensationCalculator::class)
        ->calculate($this->doctor->id, today()->subDay()->toDateString(), today()->toDateString(), 40);
    $salaryBefore = $salary();
    $report = fn () => app(FullDiscountStatistics::class)->report(['currency' => 'GEL']);
    expect(ProcedureClassification::uncategorized()->sole()->usage_count)->toBe(2)
        ->and($report()['categories']->pluck('category_key')->all())->toBe(['uncategorized']);
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'doctors')
        ->assertViewHas('doctorStatistics', fn ($stats) => collect($stats['categories'])->pluck('key')->all() === ['uncategorized']);

    $catalog = TreatmentCase::create(['name' => 'Implant surgery', 'category' => 'surgery', 'statistics_group' => 'implantation']);
    $page = Livewire::test(UncategorizedProcedures::class)->assertCanSeeTableRecords([$item]);
    $page
        ->callTableAction('map', $item, ['category' => 'surgery', 'statistics_group_mode' => 'group', 'statistics_group' => 'implantation'])->assertHasNoTableActionErrors()
        ->assertCanNotSeeTableRecords([$item]);
    expect(ProcedureClassification::uncategorized()->count())->toBe(0)
        ->and($report()['categories']->pluck('category_key')->all())->toBe(['surgery'])
        ->and($item->fresh()->getRawOriginal())->toBe($before)
        ->and($salary())->toBe($salaryBefore)
        ->and($discount->fresh()->treatment_case_id)->toBeNull();
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'doctors')->call('toggleDoctor', $this->doctor->id)
        ->assertViewHas('doctorStatistics', fn ($stats) => collect($stats['categories'])->pluck('key')->all() === ['surgery']
            && collect($stats['details'][$this->doctor->id]['categories'])->pluck('key')->all() === ['surgery']);

    ProcedureClassification::assign('  UNKNOWN IMPLANTATION ', $catalog->id);
    expect(ProcedureCatalogMapping::count())->toBe(1);
    $catalog->update(['category' => 'therapy', 'statistics_group' => null]);
    expect($report()['categories']->pluck('category_key')->all())->toBe(['therapy']);
});

test('assigning a procedure creates its catalog classification without rewriting visits', function () {
    $item = ($this->makeProcedure)('Temporary custom crown');
    Livewire::test(UncategorizedProcedures::class)
        ->callTableAction('map', $item, ['category' => 'orthopedics', 'statistics_group_mode' => 'group', 'statistics_group' => 'pmma'])
        ->assertHasNoTableActionErrors()->assertCanNotSeeTableRecords([$item]);
    $catalog = TreatmentCase::where('name', 'Temporary custom crown')->sole();
    expect(ProcedureCatalogMapping::sole()->treatment_case_id)->toBe($catalog->id)
        ->and($catalog->statistics_group)->toBe('pmma')->and($item->fresh()->treatment_case_id)->toBeNull();
});
test('adding an unambiguous exact catalog name resolves history but does not guess similar names', function () {
    $item = ($this->makeProcedure)('Bone augmentation');
    $similar = ($this->makeProcedure)('Bone augmentation plus');
    $catalog = TreatmentCase::create(['name' => '  BONE AUGMENTATION ', 'category' => 'surgery']);
    $rows = ProcedureClassification::resolvedItems()->get()->keyBy('item_id');
    expect($rows[$item->id]->id)->toBe($catalog->id)->and($rows[$similar->id]->category)->toBeNull();
    TreatmentCase::create(['name' => 'Bone augmentation', 'category' => 'therapy']);
    expect(ProcedureClassification::resolvedItems()->where('procedure_items.id', $item->id)->first()->category)->toBeNull();
    ProcedureClassification::assign('Bone augmentation', $catalog->id);
    expect(ProcedureClassification::resolvedItems()->where('procedure_items.id', $item->id)->first()->category)->toBe('surgery');
});

test('explicit Other and existing catalog relationships stay intact and usage counts distinct visits', function () {
    $item = ($this->makeProcedure)('Explicit service', true);
    $catalog = TreatmentCase::create(['name' => 'Explicit service', 'category' => 'other']);
    $item->update(['treatment_case_id' => $catalog->id]);
    $different = TreatmentCase::create(['name' => 'Different', 'category' => 'therapy']);
    ProcedureClassification::assign('Explicit service', $different->id);
    expect(ProcedureClassification::resolvedItems()->where('procedure_items.id', $item->id)->first()->category)->toBe('other');
    $report = app(FullDiscountStatistics::class)->report(['currency' => 'GEL', 'category' => 'other']);
    expect((float) $report['summary']->original_value)->toBe(100.0);
    $unknown = ($this->makeProcedure)('Unknown');
    $unknown->visit->treatmentCaseItems()->create(['custom_service_name' => 'Unknown', 'quantity' => 2, 'unit_price' => 100]);
    ($this->makeProcedure)(' unknown ');
    expect(ProcedureClassification::uncategorized()->sole()->usage_count)->toBe(2);
});

test('uncategorized catalog page preserves catalog authorization', function (string $role, bool $allowed) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $response = $this->get(TreatmentCaseResource::getUrl('uncategorized'));
    $allowed ? $response->assertOk() : $response->assertForbidden();
})->with([['owner', true], ['administrator', true], ['lab_technician', false]]);

test('assignment selector searches category labels directly', function () {
    $item = ($this->makeProcedure)('Unmapped search procedure');
    $page = Livewire::test(UncategorizedProcedures::class)->mountTableAction('map', $item);
    $field = $page->instance()->getSchema('mountedActionSchema0')->getComponent('category');
    foreach (['თერაპია', 'თერ', mb_strtoupper('თერაპია')] as $search) {
        expect($field->getSearchResults($search))->toHaveKey('therapy')->not->toHaveKey('surgery');
    }
    foreach (['ქირურგია', 'ქირუ', mb_strtoupper('ქირურგია')] as $search) {
        expect($field->getSearchResults($search))->toHaveKey('surgery')->not->toHaveKey('therapy');
    }
    $page->setTableActionData(['category' => 'therapy', 'statistics_group_mode' => 'direct'])->callMountedTableAction()->assertHasNoTableActionErrors();
    expect(ProcedureClassification::uncategorized()->count())->toBe(0);
});
