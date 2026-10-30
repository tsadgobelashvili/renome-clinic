<?php

use App\Models\ClinicPayrollCycle;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\ProcedureCatalogMapping;
use App\Models\TreatmentCase;
use App\Models\TreatmentCategory;
use App\Models\User;
use App\Services\ClinicPayrollCycleService;
use App\Services\DoctorCompensationCalculator;
use App\Services\DoctorSalaryDiagnostic;
use App\Services\SalarySettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo('2026-10-30 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $doctor = Doctor::create(['first_name' => 'PrivateDoctor', 'last_name' => 'Hidden', 'is_active' => true, 'compensation_percentage' => 40]);
    $patient = Patient::create(['first_name' => 'PrivatePatient', 'last_name' => 'Hidden', 'patient_group_id' => PatientGroup::clinicId()]);
    $this->reference = (int) $patient->patient_number;
    $this->visit = $patient->visits()->create(['doctor_id' => $doctor->id, 'visit_date' => '2026-10-28', 'currency' => 'GEL', 'total_price' => 300]);
    $this->catalog = TreatmentCase::create(['name' => 'PrivateImagingCatalog', 'category' => 'tomography', 'is_active' => true]);
    $this->scan = $this->visit->treatmentCaseItems()->create(['custom_service_name' => 'PrivateManualImaging', 'quantity' => 2, 'unit_price' => 60]);
    $dental = TreatmentCase::create(['name' => 'PrivateDentalCatalog', 'category' => 'therapy', 'is_active' => true]);
    $this->filling = $this->visit->treatmentCaseItems()->create(['treatment_case_id' => $dental->id, 'quantity' => 1, 'unit_price' => 180]);
    $this->visit->payments()->create(['amount' => 300, 'currency' => 'GEL', 'payment_date' => '2026-10-28', 'payment_method' => 'cash']);
    $this->diagnostic = app(DoctorSalaryDiagnostic::class);
});

function diagnosticStorageSnapshot(): array
{
    return collect(['visits', 'visit_treatment_cases', 'patients', 'payments', 'payment_splits', 'cashbox_days', 'cashbox_transactions',
        'finance_transactions', 'partner_finance_transactions', 'salary_settlements', 'salary_settlement_items', 'owner_salary_shares', 'clinic_payroll_cycles'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

test('quantity two imaging at sixty plus a one hundred eighty filling yields seventy two without touching revenue or records', function (string $classification) {
    if ($classification === 'generated') {
        $this->catalog->update(['category' => TreatmentCategory::create(['name' => 'რადიოლოგია'])->id]);
    }
    match ($classification) {
        'direct', 'generated' => $this->scan->update(['treatment_case_id' => $this->catalog->id]),
        'exact' => $this->scan->update(['custom_service_name' => $this->catalog->name]),
        'mapped' => ProcedureCatalogMapping::create(['normalized_name' => 'privatemanualimaging', 'treatment_case_id' => $this->catalog->id]),
    };
    $before = diagnosticStorageSnapshot();
    $result = $this->diagnostic->trace($this->visit->id, $this->reference);
    $scan = collect($result['items'])->firstWhere('id', $this->scan->id);
    expect($result['visit']['reference_matches'])->toBeTrue()
        ->and($result['visit']['gross'])->toBe(300.0)->and($result['visit']['paid'])->toBe(300.0)
        ->and($result['item_total_matches_stored_gross'])->toBeTrue()
        ->and($scan['quantity'])->toBe(2)->and($scan['unit_price'])->toBe(60.0)
        ->and($scan['native_line_total'])->toBe(120.0)->and($scan['salary_eligible'])->toBeFalse()
        ->and($result['live_calculation']['visit_rows'][0]['base_total'])->toBe(180.0)
        ->and($result['live_calculation']['visit_rows'][0]['doctor_share'])->toBe(72.0)
        ->and(app(DoctorCompensationCalculator::class)->payableSummaries(collect([$this->visit->doctor]))[$this->visit->doctor_id]['clinic']['GEL'])->toBe(72.0)
        ->and(diagnosticStorageSnapshot())->toBe($before);
    $json = json_encode($result, JSON_THROW_ON_ERROR);
    foreach (['PrivatePatient', 'PrivateDoctor', 'PrivateImagingCatalog', 'PrivateManualImaging', 'PrivateDentalCatalog', 'patient_id', 'doctor_id', 'patient_number'] as $private) {
        expect($json)->not->toContain($private);
    }
})->with(['direct', 'exact', 'mapped', 'generated']);

test('diagnostic distinguishes missing mapping from successful exclusion without guessing names or editing the visit', function () {
    $before = diagnosticStorageSnapshot();
    $missing = $this->diagnostic->trace($this->visit->id, $this->reference + 1);
    expect($missing['visit']['reference_matches'])->toBeFalse()
        ->and($missing['items'][0]['resolved_category'])->toBeNull()
        ->and($missing['items'][0]['mapping_catalog_id'])->toBeNull()
        ->and($missing['items'][0]['salary_eligible'])->toBeTrue()
        ->and($missing['live_calculation']['visit_rows'][0]['doctor_share'])->toBe(120.0)
        ->and(diagnosticStorageSnapshot())->toBe($before);
    ProcedureCatalogMapping::create(['normalized_name' => 'privatemanualimaging', 'treatment_case_id' => $this->catalog->id]);
    $before = diagnosticStorageSnapshot();
    expect($this->diagnostic->trace($this->visit->id)['live_calculation']['visit_rows'][0]['doctor_share'])->toBe(72.0)
        ->and(diagnosticStorageSnapshot())->toBe($before);
});

test('diagnostic flags a quantity unit price mismatch instead of assuming the displayed price is a line total', function () {
    $this->scan->update(['unit_price' => 120]);
    $result = $this->diagnostic->trace($this->visit->id);
    expect($result['items'][0]['native_line_total'])->toBe(240.0)
        ->and($result['item_total_matches_stored_gross'])->toBeFalse()
        ->and($result['visit']['gross'])->toBe(300.0);
});

test('an already classified catalog wins over a mapping and unresolved generated labels are reported without free text', function () {
    $category = TreatmentCategory::create(['name' => 'PrivatePatient generated category']);
    $wrong = TreatmentCase::create(['name' => 'PrivateManualImaging', 'category' => $category->id, 'is_active' => true]);
    $this->scan->update(['treatment_case_id' => $wrong->id]);
    ProcedureCatalogMapping::create(['normalized_name' => 'privatemanualimaging', 'treatment_case_id' => $this->catalog->id]);
    $result = $this->diagnostic->trace($this->visit->id);
    expect($result['items'][0]['original_catalog_takes_precedence'])->toBeTrue()
        ->and($result['items'][0]['resolved_catalog_id'])->toBe($wrong->id)
        ->and($result['items'][0]['mapping_catalog_id'])->toBe($this->catalog->id)
        ->and($result['items'][0]['category_label']['known_label'])->toBeNull()
        ->and(json_encode($result))->not->toContain('PrivatePatient');
});

test('diagnostic compares stored deferred 120 to live 72 while payroll refreshes without resaving the visit or snapshot', function () {
    $service = app(ClinicPayrollCycleService::class);
    $old = $service->preview();
    ClinicPayrollCycle::create(['payroll_date' => $old['payroll_date'], 'status' => 'partial', 'snapshot' => ['remaining' => $old]]);
    ProcedureCatalogMapping::create(['normalized_name' => 'privatemanualimaging', 'treatment_case_id' => $this->catalog->id]);
    $before = diagnosticStorageSnapshot();
    $result = $this->diagnostic->trace($this->visit->id);
    expect($result['deferred_snapshot'][0]['automatic_reprojection_trigger'])->toBeTrue()
        ->and((float) $result['deferred_snapshot'][0]['stored_visit_rows'][0]['doctor_share'])->toBe(120.0)
        ->and($result['live_calculation']['visit_rows'][0]['doctor_share'])->toBe(72.0)
        ->and((float) $service->preview()['doctor_totals']['GEL'])->toBe(72.0)
        ->and(diagnosticStorageSnapshot())->toBe($before);
});

test('diagnostic separates immutable finalized salary from live unsettled work and does not rewrite either', function () {
    $settlement = app(SalarySettlementService::class)->settle($this->visit->doctor_id, '2026-10-28', '2026-10-28', 40, auth()->id())[0];
    ProcedureCatalogMapping::create(['normalized_name' => 'privatemanualimaging', 'treatment_case_id' => $this->catalog->id]);
    $before = diagnosticStorageSnapshot();
    $result = $this->diagnostic->trace($this->visit->id);
    expect($result['live_calculation']['visit_rows'])->toBeEmpty()
        ->and($result['settled_snapshots'])->toHaveCount(2)
        ->and((float) $settlement->fresh()->salary_total)->toBe(120.0)
        ->and($result['items'][0]['unsettled'])->toBeFalse()
        ->and(diagnosticStorageSnapshot())->toBe($before);
});

test('missing visit returns only source metadata and a missing flag', function () {
    $result = $this->diagnostic->trace(999999);
    expect($result['found'])->toBeFalse()->and($result['runtime']['web_fpm_release_verified'])->toBeFalse()
        ->and($result['runtime']['source'])->toHaveCount(4)
        ->and($result['runtime']['exclusion_support'])->each->toBeTrue();
});

test('diagnostic command refuses names and databases without enforced read only protection', function () {
    $before = diagnosticStorageSnapshot();
    $this->artisan('salary:diagnose-visit', ['visit' => 'PrivatePatient'])
        ->expectsOutputToContain('Use positive numeric IDs only. Do not supply names.')->assertExitCode(2);
    $this->artisan('salary:diagnose-visit', ['visit' => $this->visit->id])
        ->expectsOutputToContain('Requires PostgreSQL')->assertExitCode(1);
    expect(diagnosticStorageSnapshot())->toBe($before);
});
