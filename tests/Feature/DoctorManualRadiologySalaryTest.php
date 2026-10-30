<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Models\ClinicPayrollCycle;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\ProcedureCatalogMapping;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\VisitTreatmentCase;
use App\Services\ClinicPayrollCycleService;
use App\Services\DoctorCompensationCalculator;
use App\Services\DoctorSalaryDiagnostic;
use App\Services\DoctorSalaryImagingAudit;
use App\Services\Finance\AccountingLedger;
use App\Services\ProcedureClassification;
use App\Services\SalarySettlementService;
use App\Support\PaymentPresentation;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-10-30 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'SyntheticPrivateDoctor', 'last_name' => 'Radiology', 'compensation_percentage' => 40, 'is_active' => true]);
    $this->calculator = app(DoctorCompensationCalculator::class);
});

function manualRadiologyVisit($test, string $name = '3დ ტომოგრაფია', float $paid = 300, array $discount = [])
{
    $patient = Patient::create(['first_name' => 'SyntheticPrivatePatient', 'last_name' => 'Radiology', 'patient_group_id' => PatientGroup::clinicId()]);
    $visit = $patient->visits()->create(['doctor_id' => $test->doctor->id, 'visit_date' => '2026-10-28', 'currency' => 'GEL', 'total_price' => 300, ...$discount]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => $name, 'quantity' => 2, 'unit_price' => 60]);
    $visit->treatmentCaseItems()->create(['custom_service_name' => 'Eligible dental filling', 'quantity' => 1, 'unit_price' => 180]);
    if ($paid > 0) {
        $visit->payments()->create(['amount' => $paid, 'currency' => 'GEL', 'payment_date' => '2026-10-28', 'payment_method' => 'cash']);
    }

    return $visit;
}

function manualRadiologyStorageSnapshot(): array
{
    return collect(['visits', 'visit_treatment_cases', 'patients', 'payments', 'payment_splits', 'cashbox_days', 'cashbox_transactions',
        'finance_transactions', 'partner_finance_transactions', 'salary_settlements', 'salary_settlement_items', 'owner_salary_shares', 'clinic_payroll_cycles',
        'treatment_cases', 'treatment_categories', 'treatment_statistics_groups', 'procedure_catalog_mappings'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

test('the confirmed production manual two by sixty scan and one hundred eighty filling automatically calculate seventy two', function () {
    $visit = manualRadiologyVisit($this);
    $items = $visit->treatmentCaseItems()->orderBy('id')->get();
    expect($items[0]->treatment_case_id)->toBeNull()->and($items[0]->custom_service_name)->toBe('3დ ტომოგრაფია')
        ->and(ProcedureCatalogMapping::count())->toBe(0);
    $statistics = ProcedureClassification::resolvedItems()->whereIn('procedure_items.id', $items->modelKeys())->get()->toJson();
    $receipts = PaymentPresentation::tomographyAmountsByCurrency($visit->payments()->with('visit.treatmentCaseItems.treatmentCase')->get())->all();
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toJson();
    $before = manualRadiologyStorageSnapshot();
    $trace = app(DoctorSalaryDiagnostic::class)->trace($visit->id);
    expect($trace['items'][0]['catalog_id'])->toBeNull()->and($trace['items'][0]['mapping_id'])->toBeNull()
        ->and($trace['items'][0]['resolved_category'])->toBeNull()
        ->and($trace['items'][0]['salary_category'])->toBe('tomography')
        ->and($trace['items'][0]['salary_classification_source'])->toBe('approved_manual_radiology_identifier')
        ->and($trace['items'][0]['salary_eligible'])->toBeFalse()
        ->and($trace['items'][1]['salary_eligible'])->toBeTrue()
        ->and($trace['live_calculation']['visit_rows'][0]['base_total'])->toBe(180.0)
        ->and($trace['live_calculation']['visit_rows'][0]['doctor_share'])->toBe(72.0)
        ->and(VisitTreatmentCase::where('visit_id', $visit->id)->salaryEligible()->pluck('id')->all())->toBe([$items[1]->id])
        ->and($this->calculator->payableSummaries(collect([$this->doctor]))[$this->doctor->id]['clinic']['GEL'])->toBe(72.0)
        ->and($this->calculator->summary($this->doctor)['totals']['GEL']['doctor_share'])->toBe(72.0)
        ->and((float) app(ClinicPayrollCycleService::class)->preview()['doctor_totals']['GEL'])->toBe(72.0);
    Livewire::test(DoctorCompensation::class)->assertViewHas('staffRows', fn ($rows) => $rows->firstWhere('id', $this->doctor->id)['sources']['clinic']['GEL'] === 72.0);
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction(TestAction::make('calculateSalary')->schemaComponent('compensation'))
        ->assertMountedActionModalSee(['180.00 ₾', '72.00 ₾'])->assertMountedActionModalDontSee('3დ ტომოგრაფია');
    expect((float) $visit->fresh()->total_price)->toBe(300.0)->and($visit->fresh()->paid_amount)->toBe(300.0)
        ->and(ProcedureClassification::resolvedItems()->whereIn('procedure_items.id', $items->modelKeys())->get()->toJson())->toBe($statistics)
        ->and(PaymentPresentation::tomographyAmountsByCurrency($visit->payments()->with('visit.treatmentCaseItems.treatmentCase')->get())->all())->toBe($receipts)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toJson())->toBe($pnl)
        ->and(manualRadiologyStorageSnapshot())->toBe($before);
});

test('whole service identifiers support reviewed Georgian and English variants', function (string $name) {
    manualRadiologyVisit($this, $name);
    expect($this->calculator->calculate($this->doctor->id, '2026-10-28', '2026-10-28')['totals']['GEL']['doctor_share'])->toBe(72.0);
})->with([' 3დ ტომოგრაფია ', '3D ტომოგრაფია', 'ტომოგრაფია 3დ', 'ტომოგრაფია 3D', 'CBCT', 'RADIOGRAPHY', 'რადიოლოგია', 'პანორამული რენტგენი', 'orthopantomogram']);

test('classified CT and panorama continue using existing catalog classifications', function (string $name) {
    $visit = manualRadiologyVisit($this);
    $catalog = TreatmentCase::where('name', $name)->sole();
    $visit->treatmentCaseItems()->oldest('id')->first()->update(['treatment_case_id' => $catalog->id]);
    $trace = app(DoctorSalaryDiagnostic::class)->trace($visit->id);
    expect($trace['items'][0]['salary_classification_source'])->toBe('catalog')
        ->and($trace['items'][0]['salary_eligible'])->toBeFalse()
        ->and($trace['live_calculation']['visit_rows'][0]['doctor_share'])->toBe(72.0);
})->with(['3D CT', 'პანორამა']);

test('ambiguous service descriptions remain eligible until explicitly classified', function (string $name) {
    $visit = manualRadiologyVisit($this, $name);
    expect($this->calculator->calculate($this->doctor->id, '2026-10-28', '2026-10-28')['totals']['GEL']['doctor_share'])->toBe(120.0);
    $audit = app(DoctorSalaryImagingAudit::class)->report();
    expect($audit['summary']['affected_unfinalized_visit_count'])->toBe(0)
        ->and($audit['summary']['ambiguous_item_count'])->toBe(1)
        ->and($audit['summary']['doctor_compensation_correction_by_currency']['GEL'])->toBe(0.0);
    $catalog = TreatmentCase::where('name', '3D CT')->sole();
    ProcedureClassification::assign($name, $catalog->id);
    expect($this->calculator->calculate($this->doctor->id, '2026-10-28', '2026-10-28')['totals']['GEL']['doctor_share'])->toBe(72.0)
        ->and(app(DoctorSalaryImagingAudit::class)->report($visit->id)['summary']['potential_visit_count'])->toBe(0);
})->with(['Tomography-guided filling', '3დ ტომოგრაფია და დაბჟენა', 'CT guided dental treatment', '3D', 'CBCT guided restoration']);

test('explicit dental mappings and catalog classifications override approved imaging aliases', function (bool $direct) {
    $visit = manualRadiologyVisit($this);
    $catalog = TreatmentCase::create(['name' => '3დ ტომოგრაფია', 'category' => 'therapy', 'is_active' => true]);
    if ($direct) {
        $visit->treatmentCaseItems()->oldest('id')->first()->update(['treatment_case_id' => $catalog->id]);
    } else {
        ProcedureCatalogMapping::create(['normalized_name' => '3დ ტომოგრაფია', 'treatment_case_id' => $catalog->id]);
    }
    expect($this->calculator->calculate($this->doctor->id, '2026-10-28', '2026-10-28')['totals']['GEL']['doctor_share'])->toBe(120.0)
        ->and(app(DoctorSalaryImagingAudit::class)->report()['summary']['potential_visit_count'])->toBe(0);
})->with([false, true]);

test('duplicate catalog names block automatic fallback and require an explicit mapping', function () {
    $visit = manualRadiologyVisit($this);
    $scan = TreatmentCase::create(['name' => '3დ ტომოგრაფია', 'category' => 'tomography', 'is_active' => true]);
    TreatmentCase::create(['name' => '3დ ტომოგრაფია', 'category' => 'therapy', 'is_active' => true]);
    expect($this->calculator->calculate($this->doctor->id, '2026-10-28', '2026-10-28')['totals']['GEL']['doctor_share'])->toBe(120.0);
    $audit = app(DoctorSalaryImagingAudit::class)->report($visit->id);
    expect($audit['summary']['ambiguous_item_count'])->toBe(1)
        ->and($audit['visits'][0]['items'][0]['reason'])->toBe('catalog_or_mapping_requires_review');
    ProcedureClassification::assign('3დ ტომოგრაფია', $scan->id);
    expect($this->calculator->calculate($this->doctor->id, '2026-10-28', '2026-10-28')['totals']['GEL']['doctor_share'])->toBe(72.0);
});

test('multiple unfinalized visits refresh together while audit identifies approved and ambiguous impact without writes', function () {
    foreach (range(1, 3) as $unused) {
        manualRadiologyVisit($this);
    }
    manualRadiologyVisit($this, 'PrivateName tomography-assisted filling');
    $before = manualRadiologyStorageSnapshot();
    $audit = app(DoctorSalaryImagingAudit::class)->report();
    expect($audit['summary']['potential_visit_count'])->toBe(4)
        ->and($audit['summary']['affected_unfinalized_visit_count'])->toBe(3)
        ->and($audit['summary']['potential_imaging_revenue_by_currency']['GEL'])->toBe(480.0)
        ->and($audit['summary']['approved_excluded_revenue_by_currency']['GEL'])->toBe(360.0)
        ->and($audit['summary']['ambiguous_revenue_by_currency']['GEL'])->toBe(120.0)
        ->and($audit['summary']['doctor_compensation_correction_by_currency']['GEL'])->toBe(144.0)
        ->and($this->calculator->calculate($this->doctor->id, '2026-10-28', '2026-10-28')['totals']['GEL']['doctor_share'])->toBe(336.0)
        ->and(manualRadiologyStorageSnapshot())->toBe($before);
    foreach (['SyntheticPrivatePatient', 'SyntheticPrivateDoctor', 'PrivateName', 'patient_id', 'doctor_id', 'custom_service_name'] as $private) {
        expect(json_encode($audit, JSON_THROW_ON_ERROR))->not->toContain($private);
    }
});

test('audit uses actual paid discounted salary rules rather than multiplying potential revenue by percentage', function (float $paid, array $discount, float $salary, float $correction) {
    manualRadiologyVisit($this, paid: $paid, discount: $discount);
    $audit = app(DoctorSalaryImagingAudit::class)->report();
    expect($audit['visits'][0]['after']['source_doctor_share'])->toBe($salary)
        ->and($audit['summary']['doctor_compensation_correction_by_currency']['GEL'])->toBe($correction);
})->with([
    [0.0, [], 0.0, 0.0], [150.0, [], 36.0, 24.0],
    [135.0, ['discount_type' => 'percent', 'discount_value' => 10], 32.4, 21.6],
]);

test('existing deferred payroll reprojects exact manual imaging and rejects the old approval without changing saved data', function () {
    $names = config('procedure_classification.manual_salary_radiology_names');
    config(['procedure_classification.manual_salary_radiology_names' => []]);
    manualRadiologyVisit($this);
    $service = app(ClinicPayrollCycleService::class);
    $old = $service->preview();
    expect((float) $old['doctor_totals']['GEL'])->toBe(120.0);
    ClinicPayrollCycle::create(['payroll_date' => $old['payroll_date'], 'status' => 'partial', 'snapshot' => ['remaining' => $old]]);
    config(['procedure_classification.manual_salary_radiology_names' => $names]);
    $before = manualRadiologyStorageSnapshot();
    $fresh = $service->preview();
    expect((float) $fresh['doctor_totals']['GEL'])->toBe(72.0)
        ->and($fresh['fingerprint'])->not->toBe($old['fingerprint'])
        ->and((float) $service->overview()['totals']['GEL'])->toBe(72.0)
        ->and(manualRadiologyStorageSnapshot())->toBe($before);
    expect(fn () => $service->finalize($old['payroll_date'], $old['fingerprint'], auth()->user()))->toThrow(ValidationException::class);
    expect(manualRadiologyStorageSnapshot())->toBe($before);
});

test('confirmed salary snapshots stay immutable and are absent from the unfinalized audit', function () {
    $names = config('procedure_classification.manual_salary_radiology_names');
    config(['procedure_classification.manual_salary_radiology_names' => []]);
    $settled = manualRadiologyVisit($this);
    $settlement = app(SalarySettlementService::class)->settle($this->doctor->id, '2026-10-28', '2026-10-28', 40, auth()->id())[0];
    config(['procedure_classification.manual_salary_radiology_names' => $names]);
    $unsettled = manualRadiologyVisit($this);
    $before = manualRadiologyStorageSnapshot();
    $audit = app(DoctorSalaryImagingAudit::class)->report();
    expect((float) $settlement->fresh()->salary_total)->toBe(120.0)
        ->and($audit['summary']['affected_unfinalized_visit_count'])->toBe(1)
        ->and(array_column($audit['visits'], 'visit_id'))->toBe([$unsettled->id])
        ->and(app(DoctorSalaryImagingAudit::class)->report($settled->id)['summary']['potential_visit_count'])->toBe(0)
        ->and($this->calculator->calculate($this->doctor->id, '2026-10-28', '2026-10-28')['totals']['GEL']['doctor_share'])->toBe(72.0)
        ->and(manualRadiologyStorageSnapshot())->toBe($before);
});

test('audit includes owner counterpart correction without double counting source salary', function () {
    $this->doctor->update(['owner_split_enabled' => true]);
    Doctor::create(['first_name' => 'Synthetic', 'last_name' => 'Other', 'owner_split_enabled' => true, 'is_active' => true]);
    manualRadiologyVisit($this)->update(['owner_split_override' => 'on']);
    $audit = app(DoctorSalaryImagingAudit::class)->report();
    expect($audit['visits'][0]['before']['source_doctor_share'])->toBe(150.0)
        ->and($audit['visits'][0]['after']['source_doctor_share'])->toBe(90.0)
        ->and($audit['summary']['doctor_compensation_correction_by_currency']['GEL'])->toBe(120.0);
});

test('audit restores process configuration if baseline calculation fails', function () {
    manualRadiologyVisit($this);
    $names = config('procedure_classification.manual_salary_radiology_names');
    $calculator = Mockery::mock(DoctorCompensationCalculator::class);
    $calculator->shouldReceive('calculate')->once()->ordered()->andReturn(['details' => [], 'owner_split_preview' => []]);
    $calculator->shouldReceive('calculate')->once()->ordered()->andThrow(new RuntimeException('Synthetic failure'));
    $before = manualRadiologyStorageSnapshot();
    expect(fn () => (new DoctorSalaryImagingAudit($calculator))->report())->toThrow(RuntimeException::class)
        ->and(config('procedure_classification.manual_salary_radiology_names'))->toBe($names)
        ->and(manualRadiologyStorageSnapshot())->toBe($before);
});

test('imaging audit retains fail closed database protection and validates its optional arguments', function () {
    $this->artisan('salary:diagnose-visit')->assertExitCode(2);
    $this->artisan('salary:diagnose-visit', ['--audit-unclassified-imaging' => true, '--reference' => 1])->assertExitCode(2);
    $this->artisan('salary:diagnose-visit', ['--audit-unclassified-imaging' => true])->expectsOutputToContain('Requires PostgreSQL')->assertExitCode(1);
});
