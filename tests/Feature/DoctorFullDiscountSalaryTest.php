<?php

use App\Filament\Pages\DoctorCompensation;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\DoctorCompensationCalculator;
use App\Services\FullDiscountStatistics;
use App\Services\SalarySettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function fullDiscountSalaryVisit(Doctor $doctor, array $lines, float $discount = 100, float $paid = 0, ?Patient $patient = null): Visit
{
    $patient ??= Patient::create(['first_name' => 'Discount', 'last_name' => 'Patient']);
    $visit = Visit::create([
        'doctor_id' => $doctor->getKey(), 'patient_id' => $patient->getKey(),
        'visit_date' => today(), 'currency' => 'GEL',
        'total_price' => collect($lines)->sum(fn (array $line): float => $line['price'] * ($line['quantity'] ?? 1)),
        'discount_type' => 'percent', 'discount_value' => $discount,
        'discount_reason' => $discount === 100.0 ? 'gift' : null,
    ]);
    foreach ($lines as $index => $line) {
        $service = TreatmentCase::create([
            'name' => 'Discount work '.$index, 'category' => $line['category'] ?? 'therapy',
            'is_active' => true,
        ]);
        $item = $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $service->getKey(),
            'unit_price' => $line['price'], 'quantity' => $line['quantity'] ?? 1,
        ]);
        if ($line['expense'] ?? 0) {
            $item->directExpenses()->create([
                'name' => 'Material', 'amount' => $line['expense'], 'currency' => 'GEL',
            ]);
        }
    }
    if ($paid > 0) {
        $visit->payments()->create([
            'amount' => $paid, 'currency' => 'GEL', 'payment_date' => today(), 'payment_method' => 'cash',
        ]);
    }

    return $visit;
}

beforeEach(function () {
    app()->setLocale('ka');
    $this->doctor = Doctor::create([
        'first_name' => 'Discount', 'last_name' => 'Doctor',
        'compensation_percentage' => 40, 'is_active' => true,
    ]);
});

test('full discount salary uses valued eligible quantities and existing expense deductions', function (array $lines, float $expectedBase, float $expectedSalary) {
    $visit = fullDiscountSalaryVisit($this->doctor, $lines);
    $calculator = app(DoctorCompensationCalculator::class);
    $report = $calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString(),
        approvedFullDiscountItemIds: $visit->treatmentCaseItems()->pluck('id')->all());

    expect($report['totals']['GEL']['base_total'])->toBe($expectedBase)
        ->and($report['totals']['GEL']['doctor_share'])->toBe($expectedSalary)
        ->and($report['totals']['GEL']['paid_total'])->toBe(0.0)
        ->and($report['totals']['GEL']['outstanding_total'])->toBe(0.0)
        ->and($report['details'][0]['visit_id'])->toBe($visit->id)
        ->and($calculator->eligibleVisitsQuery($this->doctor->id, today()->toDateString(), today()->toDateString())->pluck('id')->all())
        ->toBe([$visit->id])
        ->and($visit->fresh()->discount_reason)->toBe('gift');
    foreach ($report['details'][0]['items'] as $item) {
        if ($item['revenue'] === 0.0) {
            expect($item['doctor_share'])->toBe(0.0);
        }
    }
})->with([
    '1000 at 40 percent' => [[['price' => 1000]], 1000.0, 400.0],
    'multiple quantities' => [[['price' => 250, 'quantity' => 3], ['price' => 125, 'quantity' => 2]], 1000.0, 400.0],
    'zero price' => [[['price' => 0, 'quantity' => 3]], 0.0, 0.0],
    'zero price alongside real work' => [[['price' => 1000], ['price' => 0]], 1000.0, 400.0],
    'rounding remainder stays on priced work' => [[['price' => 1, 'expense' => 1], ['price' => 1], ['price' => 1], ['price' => 0]], 2.0, 0.8],
    'eligible direct expenses' => [[['price' => 1000, 'expense' => 200]], 800.0, 320.0],
    'expenses exhaust base' => [[['price' => 1000, 'expense' => 1000]], 0.0, 0.0],
    'excluded work and its expenses' => [[['price' => 1000], ['price' => 200, 'category' => 'tomography', 'expense' => 50], ['price' => 100, 'category' => 'consultation']], 1000.0, 400.0],
]);

test('non full discounts preserve paid amount salary including partial payments', function (float $discount, float $paid, float $expectedSalary) {
    fullDiscountSalaryVisit($this->doctor, [['price' => 1000]], $discount, $paid);
    $report = app(DoctorCompensationCalculator::class)->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());

    expect($report['totals']['GEL']['doctor_share'])->toBe($expectedSalary)
        ->and($report['totals']['GEL']['base_total'])->toBe($paid)
        ->and($report['details'][0]['salary_from_original_value'])->toBeFalse();
})->with([
    'no discount partial payment' => [0.0, 200.0, 80.0],
    '50 percent fully paid' => [50.0, 500.0, 200.0],
    '50 percent partially paid' => [50.0, 200.0, 80.0],
    '50 percent unpaid' => [50.0, 0.0, 0.0],
    '99.99 percent unpaid' => [99.99, 0.0, 0.0],
]);

test('full discount preserves category percentages and proportional expense allocation', function () {
    $this->doctor->update(['compensation_category_percentages' => ['therapy' => 40, 'surgery' => 50]]);
    $visit = fullDiscountSalaryVisit($this->doctor, [
        ['price' => 600, 'category' => 'therapy', 'expense' => 100],
        ['price' => 400, 'category' => 'surgery'],
    ]);
    $report = app(DoctorCompensationCalculator::class)->calculate($this->doctor->id, today()->toDateString(), today()->toDateString(),
        approvedFullDiscountItemIds: $visit->treatmentCaseItems()->pluck('id')->all());
    $items = collect($report['details'][0]['items'])->keyBy('category');

    expect($items['therapy']['salary_base'])->toBe(540.0)
        ->and($items['surgery']['salary_base'])->toBe(360.0)
        ->and($items['therapy']['applied_percentage'])->toBe(40.0)
        ->and($items['surgery']['applied_percentage'])->toBe(50.0)
        ->and($report['totals']['GEL']['doctor_share'])->toBe(396.0);
});

test('fully discounted CT and consultation alone remain excluded', function (string $category) {
    fullDiscountSalaryVisit($this->doctor, [['price' => 1000, 'category' => $category]]);
    $calculator = app(DoctorCompensationCalculator::class);
    $report = $calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());

    expect($report['details'])->toBe([])
        ->and($calculator->eligibleVisitsQuery($this->doctor->id, today()->toDateString(), today()->toDateString())->exists())->toBeFalse();
})->with(['tomography', 'consultation']);

test('full discount does not switch Israeli or owner split salary paths', function () {
    $group = PatientGroup::query()->where('slug', PatientGroup::ISRAEL_PARTNER_SLUG)->sole();
    $patient = Patient::create(['first_name' => 'Partner', 'last_name' => 'Discount', 'patient_group_id' => $group->id]);
    $partner = fullDiscountSalaryVisit($this->doctor, [['price' => 1000, 'expense' => 100]], patient: $patient);
    $this->doctor->update(['owner_split_key' => 'levan']);
    $owner = fullDiscountSalaryVisit($this->doctor, [['price' => 1000]]);
    $owner->update(['owner_split_override' => 'on']);
    $report = app(DoctorCompensationCalculator::class)->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());
    $rows = collect($report['details'])->keyBy('visit_id');

    expect($rows[$partner->id]['salary_from_original_value'])->toBeFalse()
        ->and($rows[$partner->id]['doctor_share'])->toBe(360.0)
        ->and($rows[$owner->id]['salary_from_original_value'])->toBeFalse()
        ->and($rows[$owner->id]['owner_split'])->toBeTrue()
        ->and($rows[$owner->id]['doctor_share'])->toBe(0.0);
});

test('salary modal hides cutoff and finalizes discounted work without consuming later same day visits', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $first = fullDiscountSalaryVisit($this->doctor, [['price' => 1000]]);
    Livewire::test(DoctorCompensation::class)
        ->call('openDoctorSalary', $this->doctor->id, 'clinic')
        ->assertMountedActionModalDontSee(['ვიზიტის ჩათვლით', 'დღის ბოლომდე'])
        ->assertMountedActionModalSee(['100% ფასდაკლება', '400.00 ₾'])
        ->set('mountedActions.0.data.approved_full_discount_item_ids', $first->treatmentCaseItems()->pluck('id')->map('strval')->all())
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $settlement = $this->doctor->salarySettlements()->with('items.visit')->sole();
    expect((float) $settlement->salary_total)->toBe(400.0)
        ->and($settlement->last_included_item->visit_id)->toBe($first->id)
        ->and($settlement->items->pluck('visit_treatment_case_id')->all())->toBe($first->treatmentCaseItems()->pluck('id')->all());

    $later = fullDiscountSalaryVisit($this->doctor, [['price' => 500]]);
    $calculator = app(DoctorCompensationCalculator::class);
    $report = $calculator->calculate($this->doctor->id, $calculator->defaultPeriodStart($this->doctor->id), today()->toDateString(),
        approvedFullDiscountItemIds: $later->treatmentCaseItems()->pluck('id')->all());
    expect(array_column($report['details'], 'visit_id'))->toBe([$later->id])
        ->and($report['totals']['GEL']['doctor_share'])->toBe(200.0)
        ->and($settlement->fresh()->last_included_item->visit_id)->toBe($first->id);

    $next = app(SalarySettlementService::class)->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, auth()->id(),
        approvedFullDiscountItemIds: $later->treatmentCaseItems()->pluck('id')->all());
    expect($next[0]->items->pluck('visit_id')->all())->toBe([$later->id])
        ->and((float) $next[0]->salary_total)->toBe(200.0);
});

test('full discount potential remains visible while approval defaults off and controls only payout', function () {
    $visit = fullDiscountSalaryVisit($this->doctor, [['price' => 130]]);
    $itemId = $visit->treatmentCaseItems()->sole()->id;
    $calculator = app(DoctorCompensationCalculator::class);
    $off = $calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());
    $on = $calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString(), approvedFullDiscountItemIds: [$itemId]);

    expect($off['details'][0]['items'][0]['potential_doctor_share'])->toBe(52.0)
        ->and($off['details'][0]['items'][0]['salary_approved'])->toBeFalse()
        ->and($off['totals']['GEL']['doctor_share'])->toBe(0.0)
        ->and($on['details'][0]['items'][0]['potential_doctor_share'])->toBe(52.0)
        ->and($on['details'][0]['items'][0]['salary_approved'])->toBeTrue()
        ->and($on['totals']['GEL']['doctor_share'])->toBe(52.0);
});

test('salary modal saves independent item approvals and immutable potential snapshots', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $visit = fullDiscountSalaryVisit($this->doctor, [['price' => 130], ['price' => 200]]);
    [$approved, $declined] = $visit->treatmentCaseItems()->orderBy('id')->get()->all();
    $page = Livewire::test(DoctorCompensation::class)
        ->call('openDoctorSalary', $this->doctor->id, 'clinic')
        ->assertActionDataSet(['approved_full_discount_item_ids' => []])
        ->assertMountedActionModalSee(['გაცემა', '52.00 ₾', '80.00 ₾'])
        ->set('mountedActions.0.data.approved_full_discount_item_ids', [(string) $approved->id])
        ->callMountedAction()->assertHasNoActionErrors();

    $settlement = $this->doctor->salarySettlements()->with('items')->sole();
    $snapshots = $settlement->items->keyBy('visit_treatment_case_id');
    expect((float) $settlement->salary_total)->toBe(52.0)
        ->and($snapshots[$approved->id]->is_full_discount_snapshot)->toBeTrue()
        ->and($snapshots[$approved->id]->salary_approved)->toBeTrue()
        ->and((float) $snapshots[$approved->id]->potential_doctor_share_snapshot)->toBe(52.0)
        ->and((float) $snapshots[$approved->id]->doctor_share_snapshot)->toBe(52.0)
        ->and($snapshots[$declined->id]->is_full_discount_snapshot)->toBeTrue()
        ->and($snapshots[$declined->id]->salary_approved)->toBeFalse()
        ->and((float) $snapshots[$declined->id]->potential_doctor_share_snapshot)->toBe(80.0)
        ->and((float) $snapshots[$declined->id]->doctor_share_snapshot)->toBe(0.0);
    $this->doctor->update(['compensation_percentage' => 90]);
    expect((float) $snapshots[$declined->id]->fresh()->potential_doctor_share_snapshot)->toBe(80.0)
        ->and(app(DoctorCompensationCalculator::class)->calculate($this->doctor->id, today()->toDateString(), today()->toDateString())['details'])->toBe([]);
    $page->call('openDoctorSalary', $this->doctor->id, 'clinic')
        ->assertActionDataSet(['approved_full_discount_item_ids' => []])
        ->call('toggleDoctorSalaryHistory', $this->doctor->id)
        ->assertMountedActionModalSee(['ხელფასი დამტკიცებულია', 'ხელფასი არ გაიცა', '80.00 ₾']);
});

test('finalizing with no selection records declined salary rather than leaving work unsettled', function () {
    $visit = fullDiscountSalaryVisit($this->doctor, [['price' => 130]]);
    $settlement = app(SalarySettlementService::class)->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, null)[0];
    $snapshot = $settlement->items->sole();
    expect((float) $settlement->salary_total)->toBe(0.0)
        ->and($snapshot->visit_id)->toBe($visit->id)
        ->and($snapshot->salary_approved)->toBeFalse()
        ->and((float) $snapshot->potential_doctor_share_snapshot)->toBe(52.0)
        ->and((float) $snapshot->doctor_share_snapshot)->toBe(0.0);
    $report = app(FullDiscountStatistics::class)->report(['currency' => 'GEL']);
    expect($report['statuses']->sole()->salary_status)->toBe('declined')
        ->and((float) $report['summary']->salary_gel)->toBe(0.0)
        ->and((int) $report['summary']->unrecorded_items)->toBe(0);
});

test('discount statistics counts only the approved confirmed item and keeps declined work separate', function () {
    $visit = fullDiscountSalaryVisit($this->doctor, [['price' => 130], ['price' => 200]]);
    $approved = $visit->treatmentCaseItems()->orderBy('id')->first();
    $statistics = app(FullDiscountStatistics::class);
    expect((float) $statistics->report(['currency' => 'GEL'])['summary']->salary_gel)->toBe(0.0);
    app(SalarySettlementService::class)->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, null,
        approvedFullDiscountItemIds: [$approved->id]);
    $report = $statistics->report(['currency' => 'GEL']);
    $statuses = $report['statuses']->keyBy('salary_status');
    expect((float) $report['summary']->salary_gel)->toBe(52.0)
        ->and((int) $statuses['generated']->items)->toBe(1)
        ->and((int) $statuses['declined']->items)->toBe(1)
        ->and((float) $statuses['declined']->salary_gel)->toBe(0.0)
        ->and(count($statistics->details(['currency' => 'GEL'], ['salary_status' => 'declined'])->items()))->toBe(1);
});

test('approval selections do not affect normal or partially discounted visits or add queries', function () {
    $free = fullDiscountSalaryVisit($this->doctor, [['price' => 130]]);
    $normal = fullDiscountSalaryVisit($this->doctor, [['price' => 1000]], 50, 200);
    $freeId = $free->treatmentCaseItems()->sole()->id;
    $normalId = $normal->treatmentCaseItems()->sole()->id;
    $calculator = app(DoctorCompensationCalculator::class);
    $calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());
    DB::enableQueryLog();
    DB::flushQueryLog();
    $off = $calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString());
    $offCount = count(DB::getQueryLog());
    DB::flushQueryLog();
    $on = $calculator->calculate($this->doctor->id, today()->toDateString(), today()->toDateString(),
        approvedFullDiscountItemIds: [$freeId, $normalId, 999999]);
    $onCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    $normalRow = collect($on['details'])->firstWhere('visit_id', $normal->id);
    expect($off['totals']['GEL']['doctor_share'])->toBe(80.0)
        ->and($on['totals']['GEL']['doctor_share'])->toBe(132.0)
        ->and($normalRow['items'][0]['salary_approved'])->toBeNull()
        ->and($normalRow['doctor_share'])->toBe(80.0)
        ->and($onCount)->toBe($offCount);
});

test('salary approval resets when changing the period or source', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $visit = fullDiscountSalaryVisit($this->doctor, [['price' => 130]]);
    $itemId = (string) $visit->treatmentCaseItems()->sole()->id;
    Livewire::test(DoctorCompensation::class)->call('openDoctorSalary', $this->doctor->id, 'clinic')
        ->set('mountedActions.0.data.approved_full_discount_item_ids', [$itemId])
        ->set('mountedActions.0.data.from', today()->subDay()->toDateString())
        ->assertActionDataSet(['approved_full_discount_item_ids' => []])
        ->set('mountedActions.0.data.approved_full_discount_item_ids', [$itemId])
        ->set('mountedActions.0.data.patient_group', PatientGroup::ISRAEL_PARTNER_SLUG)
        ->assertActionDataSet(['approved_full_discount_item_ids' => []])
        ->assertMountedActionModalDontSee('ხელფასი გაიცეს');
});
