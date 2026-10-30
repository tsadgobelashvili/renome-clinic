<?php

use App\Filament\Pages\DoctorCompensation;
use App\Filament\Resources\Doctors\Pages\ViewDoctor;
use App\Models\ClinicPayrollCycle;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\ProcedureCatalogMapping;
use App\Models\TreatmentCase;
use App\Models\TreatmentCategory;
use App\Models\User;
use App\Services\ClinicPayrollCycleService;
use App\Services\DoctorCompensationCalculator;
use App\Services\Finance\AccountingLedger;
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
    $this->doctor = Doctor::create(['first_name' => 'Mixed', 'last_name' => 'Salary', 'is_active' => true, 'compensation_percentage' => 40]);
    $this->calculator = app(DoctorCompensationCalculator::class);
});

function mixedSalaryVisit($test, string $classification = 'mapped', float $paid = 300, array $discount = [], bool $israeli = false, array $treatmentPrices = [180])
{
    $category = $classification === 'managed'
        ? TreatmentCategory::create(['name' => 'რადიოლოგია'])->id : 'tomography';
    $scan = TreatmentCase::create(['name' => 'Catalog CBCT', 'category' => $category, 'is_active' => true]);
    $patient = Patient::create(['first_name' => 'Synthetic', 'last_name' => 'Mixed',
        'patient_group_id' => $israeli ? PatientGroup::israelPartnerId() : PatientGroup::clinicId()]);
    $visit = $patient->visits()->create(['doctor_id' => $test->doctor->id, 'visit_date' => today(),
        'currency' => 'GEL', 'total_price' => 120 + array_sum($treatmentPrices), ...$discount]);
    $scanData = match ($classification) {
        'direct', 'managed' => ['treatment_case_id' => $scan->id],
        'exact' => ['custom_service_name' => $scan->name],
        default => ['custom_service_name' => 'Legacy imaging line'],
    };
    $visit->treatmentCaseItems()->create([...$scanData, 'quantity' => 1, 'unit_price' => 120]);
    if ($classification === 'mapped') {
        ProcedureCatalogMapping::create(['normalized_name' => 'legacy imaging line', 'treatment_case_id' => $scan->id]);
    }
    foreach ($treatmentPrices as $index => $price) {
        $catalog = TreatmentCase::create(['name' => 'Dental filling '.$index, 'category' => 'therapy', 'is_active' => true]);
        $visit->treatmentCaseItems()->create(['treatment_case_id' => $catalog->id, 'quantity' => 1, 'unit_price' => $price]);
    }
    if ($paid > 0) {
        $visit->payments()->create(['amount' => $paid, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash']);
    }

    return $visit;
}

function mixedSalaryStorageSnapshot(): array
{
    return collect(['visits', 'visit_treatment_cases', 'payments', 'payment_splits', 'cashbox_days', 'cashbox_transactions',
        'finance_transactions', 'partner_finance_transactions', 'salary_settlements', 'salary_settlement_items', 'owner_salary_shares', 'clinic_payroll_cycles'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
}

test('an existing calculated unpaid 120 plus 180 visit automatically pays 72 without resaving or financial writes', function (string $classification) {
    $visit = mixedSalaryVisit($this, $classification);
    $before = mixedSalaryStorageSnapshot();
    $report = $this->calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());
    expect($report['totals']['GEL']['base_total'])->toBe(180.0)
        ->and($report['totals']['GEL']['doctor_share'])->toBe(72.0)
        ->and($report['details'][0]['items'])->toHaveCount(1)
        ->and($this->calculator->payableSummaries(collect([$this->doctor]))[$this->doctor->id]['clinic']['GEL'])->toBe(72.0)
        ->and($this->calculator->summary($this->doctor)['totals']['GEL']['doctor_share'])->toBe(72.0);
    $preview = app(ClinicPayrollCycleService::class)->preview();
    expect((float) $preview['doctor_totals']['GEL'])->toBe(72.0);
    Livewire::test(DoctorCompensation::class)->assertViewHas('staffRows', fn ($rows) => $rows->firstWhere('id', $this->doctor->id)['sources']['clinic']['GEL'] === 72.0);
    Livewire::test(ViewDoctor::class, ['record' => $this->doctor->getRouteKey()])
        ->mountAction(TestAction::make('calculateSalary')->schemaComponent('compensation'))
        ->assertMountedActionModalSee(['180.00 ₾', '72.00 ₾'])
        ->assertMountedActionModalDontSee('Catalog CBCT');
    expect($this->calculator->cutoffVisitOptions($this->doctor->id, today()->toDateString(), today()->toDateString()))->toHaveKey($visit->id)
        ->and(mixedSalaryStorageSnapshot())->toBe($before);
})->with(['direct', 'exact', 'mapped', 'managed']);

test('mixed compensation preserves proportional discounts and actual paid allocation', function (float $paid, array $discount, float $expected) {
    mixedSalaryVisit($this, paid: $paid, discount: $discount);
    $report = $this->calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());
    expect($report['totals']['GEL']['doctor_share'])->toBe($expected);
})->with([
    'fully paid' => [300, [], 72.0],
    'half paid' => [150, [], 36.0],
    'unpaid' => [0, [], 0.0],
    'percent discount' => [270, ['discount_type' => 'percent', 'discount_value' => 10], 64.8],
    'fixed discount' => [270, ['discount_type' => 'amount', 'discount_value' => 30], 64.8],
    'discount and partial payment' => [135, ['discount_type' => 'percent', 'discount_value' => 10], 32.4],
]);

test('multiple dental manipulations retain their percentages while classified radiology is excluded', function () {
    mixedSalaryVisit($this, treatmentPrices: [100, 80]);
    $report = $this->calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());
    expect($report['details'][0]['items'])->toHaveCount(2)->and($report['totals']['GEL']['doctor_share'])->toBe(72.0);
});

test('radiology only work is excluded from queries summaries and settlement choices', function (string $classification) {
    mixedSalaryVisit($this, $classification, paid: 120, treatmentPrices: []);
    expect($this->calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString())['details'])->toBeEmpty()
        ->and($this->calculator->cutoffVisitOptions($this->doctor->id, today()->toDateString(), today()->toDateString()))->toBeEmpty()
        ->and($this->calculator->payableSummaries(collect([$this->doctor])))->toBeEmpty();
})->with(['direct', 'exact', 'mapped', 'managed']);

test('normal dental names containing radiology words remain eligible and ambiguous custom names are not guessed', function () {
    $visit = mixedSalaryVisit($this, 'unmapped');
    $visit->treatmentCaseItems()->whereNotNull('treatment_case_id')->first()->treatmentCase->update(['name' => 'Tomography-guided dental restoration']);
    TreatmentCase::create(['name' => 'Legacy imaging line', 'category' => 'tomography', 'is_active' => true]);
    TreatmentCase::create(['name' => 'Legacy imaging line', 'category' => 'therapy', 'is_active' => true]);
    expect($this->calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString())['totals']['GEL']['doctor_share'])->toBe(120.0);
    ProcedureCatalogMapping::create(['normalized_name' => 'legacy imaging line', 'treatment_case_id' => TreatmentCase::where('name', 'Catalog CBCT')->sole()->id]);
    expect($this->calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString())['totals']['GEL']['doctor_share'])->toBe(72.0);
});

test('Israeli visit percentage excludes imaging while fixed visit salary and earned basis remain unchanged', function (?string $type, ?float $rate, float $expected) {
    $this->doctor->update(['israeli_visit_salary_type' => $type, 'israeli_visit_salary_rate' => $rate]);
    mixedSalaryVisit($this, paid: 0, israeli: true);
    expect($this->calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString(), patientGroup: PatientGroup::ISRAEL_PARTNER_SLUG)['totals']['GEL']['doctor_share'])->toBe($expected);
})->with([[null, null, 72.0], ['percent', 40.0, 72.0], ['fixed', 100.0, 100.0]]);

test('owner split retains fifty fifty of eligible paid dental work only', function () {
    $this->doctor->update(['owner_split_enabled' => true]);
    Doctor::create(['first_name' => 'Other', 'last_name' => 'Owner', 'owner_split_enabled' => true, 'is_active' => true]);
    mixedSalaryVisit($this)->update(['owner_split_override' => 'on']);
    $report = $this->calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());
    expect($report['totals']['GEL']['doctor_share'])->toBe(90.0)
        ->and($report['owner_split_preview'][0]['counterpart_share'])->toBe(90.0);
});

test('stored unselected doctor payroll automatically reprojects imaging without rewriting the partial cycle', function (bool $ownerSplit) {
    if ($ownerSplit) {
        $this->doctor->update(['owner_split_enabled' => true]);
        Doctor::create(['first_name' => 'Other', 'last_name' => 'Owner', 'owner_split_enabled' => true, 'is_active' => true]);
    }
    $visit = mixedSalaryVisit($this, 'unmapped');
    if ($ownerSplit) {
        $visit->update(['owner_split_override' => 'on']);
    }
    $service = app(ClinicPayrollCycleService::class);
    $old = $service->preview();
    expect((float) $old['doctor_totals']['GEL'])->toBe($ownerSplit ? 300.0 : 120.0);
    ClinicPayrollCycle::create(['payroll_date' => $old['payroll_date'], 'status' => 'partial', 'snapshot' => ['remaining' => $old]]);
    ProcedureCatalogMapping::create(['normalized_name' => 'legacy imaging line', 'treatment_case_id' => TreatmentCase::where('name', 'Catalog CBCT')->sole()->id]);
    $before = mixedSalaryStorageSnapshot();
    $fresh = $service->preview();
    expect((float) $fresh['doctor_totals']['GEL'])->toBe($ownerSplit ? 180.0 : 72.0)
        ->and((float) $service->overview()['totals']['GEL'])->toBe($ownerSplit ? 180.0 : 72.0)
        ->and($fresh['fingerprint'])->not->toBe($old['fingerprint'])
        ->and(mixedSalaryStorageSnapshot())->toBe($before);
    expect(fn () => $service->finalize($old['payroll_date'], $old['fingerprint'], auth()->user()))->toThrow(ValidationException::class);
    expect(mixedSalaryStorageSnapshot())->toBe($before);
    $cycle = $service->finalize($fresh['payroll_date'], $fresh['fingerprint'], auth()->user());
    expect((float) $cycle->doctorSettlements()->sum('salary_total'))->toBe($ownerSplit ? 180.0 : 72.0)
        ->and($cycle->doctorSettlements()->count())->toBe($ownerSplit ? 2 : 1);
})->with([false, true]);

test('historical finalized snapshots stay unchanged when classification corrects pending work', function () {
    $old = mixedSalaryVisit($this, 'unmapped');
    $settlement = app(SalarySettlementService::class)->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, auth()->id())[0];
    expect((float) $settlement->salary_total)->toBe(120.0);
    $new = mixedSalaryVisit($this, 'unmapped');
    $before = mixedSalaryStorageSnapshot();
    ProcedureCatalogMapping::create(['normalized_name' => 'legacy imaging line', 'treatment_case_id' => TreatmentCase::where('name', 'Catalog CBCT')->first()->id]);
    $report = $this->calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());
    expect((float) $settlement->fresh()->salary_total)->toBe(120.0)
        ->and($report['details'])->toHaveCount(1)->and($report['details'][0]['visit_id'])->toBe($new->id)
        ->and($report['totals']['GEL']['doctor_share'])->toBe(72.0)
        ->and(mixedSalaryStorageSnapshot())->toBe($before);
});

test('doctor compensation reads leave visit totals revenue tomography receipts and cash unchanged', function () {
    $visit = mixedSalaryVisit($this, 'direct');
    $payments = $visit->payments()->with('visit.treatmentCaseItems.treatmentCase')->get();
    $tomography = PaymentPresentation::tomographyAmountsByCurrency($payments)->all();
    $pnl = app(AccountingLedger::class)->pnlTotals(null, null)->toJson();
    $before = mixedSalaryStorageSnapshot();
    $this->calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());
    expect((float) $visit->fresh()->total_price)->toBe(300.0)->and($visit->fresh()->paid_amount)->toBe(300.0)
        ->and($tomography)->toBe(['GEL' => 120.0])
        ->and(PaymentPresentation::tomographyAmountsByCurrency($payments)->all())->toBe($tomography)
        ->and(app(AccountingLedger::class)->pnlTotals(null, null)->toJson())->toBe($pnl)
        ->and(mixedSalaryStorageSnapshot())->toBe($before);
});

test('a deferred imaging-only row becomes zero without leaving payroll stuck on an empty batch', function () {
    mixedSalaryVisit($this, 'unmapped', paid: 120, treatmentPrices: []);
    $service = app(ClinicPayrollCycleService::class);
    $old = $service->preview();
    expect((float) $old['doctor_totals']['GEL'])->toBe(48.0);
    ClinicPayrollCycle::create(['payroll_date' => $old['payroll_date'], 'status' => 'partial', 'snapshot' => ['remaining' => $old]]);
    ProcedureCatalogMapping::create(['normalized_name' => 'legacy imaging line', 'treatment_case_id' => TreatmentCase::where('name', 'Catalog CBCT')->sole()->id]);
    $before = mixedSalaryStorageSnapshot();
    expect($service->preview()['doctors'])->toBeEmpty()
        ->and(array_sum($service->overview()['totals']))->toEqual(0)
        ->and(mixedSalaryStorageSnapshot())->toBe($before);
});

test('explicit settlement snapshots the corrected dental salary once without changing patient payments', function () {
    $visit = mixedSalaryVisit($this);
    $patientBefore = DB::table('payments')->orderBy('id')->get()->toJson();
    $service = app(SalarySettlementService::class);
    $settlement = $service->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, auth()->id())[0];
    expect((float) $settlement->salary_total)->toBe(72.0)
        ->and((float) $settlement->base_total)->toBe(180.0)
        ->and($settlement->items)->toHaveCount(1)
        ->and((float) $settlement->items->sole()->doctor_share_snapshot)->toBe(72.0)
        ->and((float) $visit->fresh()->total_price)->toBe(300.0)
        ->and(DB::table('payments')->orderBy('id')->get()->toJson())->toBe($patientBefore);
    $expenses = FinanceTransaction::where('type', 'expense')->count();
    expect(fn () => $service->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, auth()->id()))->toThrow(ValidationException::class);
    expect(FinanceTransaction::where('type', 'expense')->count())->toBe($expenses)
        ->and(DB::table('salary_settlements')->count())->toBe(1);
});
