<?php

use App\Filament\Pages\FinanceReports;
use App\Filament\Pages\FullDiscountStatistics as StatisticsPage;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\LabCase;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\FullDiscountStatistics;
use App\Services\SalarySettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function statisticsDiscountVisit(Doctor $doctor, Patient $patient, array $lines = [], array $attributes = []): Visit
{
    $visit = Visit::create(array_replace([
        'doctor_id' => $doctor->id, 'patient_id' => $patient->id, 'visit_date' => today(),
        'currency' => 'GEL', 'discount_type' => 'percent', 'discount_value' => 100, 'discount_reason' => 'gift',
        'total_price' => collect($lines)->sum(fn ($line) => $line['price'] * ($line['quantity'] ?? 1)),
    ], $attributes));
    foreach ($lines as $index => $line) {
        $treatment = TreatmentCase::create([
            'name' => $line['name'] ?? 'Analytics service '.$index,
            'category' => $line['category'] ?? 'therapy', 'statistics_group' => $line['group'] ?? 'filling', 'is_active' => true,
        ]);
        $visit->treatmentCaseItems()->create([
            'treatment_case_id' => $treatment->id, 'quantity' => $line['quantity'] ?? 1,
            'unit_price' => $line['price'], 'lab_main_work_id' => $line['lab_main_work_id'] ?? null,
            'currency' => $line['currency'] ?? $visit->currency, 'exchange_rate' => $line['exchange_rate'] ?? null,
        ]);
    }

    return $visit;
}

beforeEach(function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->doctor = Doctor::create(['first_name' => 'Analytics', 'last_name' => 'Doctor', 'compensation_percentage' => 40, 'is_active' => true]);
    $this->patient = Patient::create(['first_name' => 'LazyPrivatePatient', 'last_name' => 'Statistics']);
    $this->statistics = app(FullDiscountStatistics::class);
    $this->filters = ['from' => today()->subDays(13)->toDateString(), 'until' => today()->toDateString(), 'currency' => 'GEL', 'source' => 'all'];
});

test('discount analytics counts exactly 100 percent using original quantities and unique patients', function () {
    $first = statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 250, 'quantity' => 3], ['price' => 125, 'quantity' => 2]]);
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 100]]);
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 999]], ['discount_value' => 99]);
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 500]], ['discount_type' => 'amount', 'discount_value' => 500]);
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 800]])->update(['cancelled_at' => now()]);
    $report = $this->statistics->report($this->filters);

    expect((int) $report['summary']->patients)->toBe(1)
        ->and((int) $report['summary']->visits)->toBe(2)
        ->and((int) $report['summary']->items)->toBe(3)
        ->and((int) $report['summary']->quantity)->toBe(6)
        ->and((float) $report['summary']->original_value)->toBe(1100.0)
        ->and((float) $report['summary']->free_value)->toBe(1100.0)
        ->and((float) $report['summary']->salary_gel)->toBe(0.0)
        ->and((int) $report['summary']->unrecorded_items)->toBe(3)
        ->and($first->paid_amount)->toBe(0.0);
});

test('confirmed salary is linked per manipulation and CT consultation remain excluded', function () {
    $visit = statisticsDiscountVisit($this->doctor, $this->patient, [
        ['price' => 500, 'quantity' => 2],
        ['price' => 200, 'category' => 'tomography', 'group' => 'other'],
        ['price' => 100, 'category' => 'consultation', 'group' => 'other'],
    ]);
    app(SalarySettlementService::class)->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, auth()->id(),
        approvedFullDiscountItemIds: $visit->treatmentCaseItems()->pluck('id')->all());
    $this->doctor->update(['compensation_percentage' => 90]);
    $report = $this->statistics->report($this->filters);
    $statuses = $report['statuses']->keyBy('salary_status');

    expect((float) $report['summary']->salary_gel)->toBe(400.0)
        ->and((float) $report['summary']->original_value)->toBe(1300.0)
        ->and((int) $report['summary']->generated_visits)->toBe(1)
        ->and((int) $report['summary']->no_salary_visits)->toBe(1)
        ->and((int) $statuses['generated']->items)->toBe(1)
        ->and((int) $statuses['excluded']->items)->toBe(2)
        ->and((float) $statuses['excluded']->salary_gel)->toBe(0.0)
        ->and((float) $report['categories']->firstWhere('category_key', 'therapy')->salary_gel)->toBe(400.0)
        ->and(collect($this->statistics->details($this->filters)->items())->pluck('visit_id')->unique()->all())->toBe([$visit->id]);
});

test('zero recorded salary is distinct from unfinalized work and undo removes confirmed salary', function () {
    $visit = statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 0]]);
    $settlement = app(SalarySettlementService::class)->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, auth()->id(),
        approvedFullDiscountItemIds: $visit->treatmentCaseItems()->pluck('id')->all())[0];
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 1000]]);
    $report = $this->statistics->report($this->filters);
    expect($report['statuses']->pluck('salary_status')->all())->toContain('recorded_zero', 'not_recorded')
        ->and((float) $report['summary']->salary_gel)->toBe(0.0);
    app(SalarySettlementService::class)->undo($settlement->id);
    expect($this->statistics->report($this->filters)['statuses']->pluck('salary_status')->all())->toBe(['not_recorded']);
});

test('doctor category date source and reason filters constrain all aggregates and details', function () {
    $other = Doctor::create(['first_name' => 'Other', 'last_name' => 'Doctor', 'is_active' => true]);
    $partner = Patient::create(['first_name' => 'Israeli', 'last_name' => 'Patient', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $target = statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 200, 'category' => 'surgery', 'group' => 'extraction']], ['discount_reason' => 'management']);
    statisticsDiscountVisit($other, $this->patient, [['price' => 300]]);
    statisticsDiscountVisit($this->doctor, $partner, [['price' => 400]]);
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 500]], ['visit_date' => today()->subMonths(2)]);
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 600]], ['visit_date' => today()->addDay()]);

    foreach ([
        [['doctor' => (string) $this->doctor->id], 2, 600],
        [['category' => 'surgery'], 1, 200],
        [['source' => 'clinic'], 2, 500],
        [['source' => 'israel-partner'], 1, 400],
        [['reason' => 'management'], 1, 200],
        [['from' => null, 'until' => null], 5, 2000],
    ] as [$filter, $visits, $value]) {
        $filters = array_replace($this->filters, $filter);
        $summary = $this->statistics->report($filters)['summary'];
        expect((int) $summary->visits)->toBe($visits)
            ->and((float) $summary->original_value)->toBe((float) $value)
            ->and(count($this->statistics->details($filters)->items()))->toBe($visits);
    }
    expect($this->statistics->details($this->filters, ['reason' => 'management'])->items()[0]->visit_id)->toBe($target->id);
});

test('statistics groups retain distinct patient counts and implantation service breakdown', function () {
    statisticsDiscountVisit($this->doctor, $this->patient, [
        ['price' => 500, 'quantity' => 2, 'name' => 'Implantation Brand A', 'category' => 'surgery', 'group' => 'implantation'],
        ['price' => 800, 'name' => 'Implantation Brand B', 'category' => 'surgery', 'group' => 'implantation'],
    ]);
    $report = $this->statistics->report($this->filters);
    $group = $report['groups']->sole();
    $services = $this->statistics->services($this->filters, ['category_key' => 'surgery', 'group_key' => 'implantation']);
    expect($group->group_key)->toBe('implantation')->and((int) $group->patients)->toBe(1)
        ->and((int) $group->quantity)->toBe(3)->and((float) $group->original_value)->toBe(1800.0)
        ->and($services->pluck('service_name')->all())->toBe(['Implantation Brand A', 'Implantation Brand B']);
});

test('legacy reasons and itemless visits stay visible without rewriting historical data', function () {
    $visit = statisticsDiscountVisit($this->doctor, $this->patient, [], ['discount_reason' => 'other', 'discount_comment' => 'Historical explanation']);
    DB::table('visits')->where('id', $visit->id)->update(['discount_reason' => 'Legacy free-text reason']);
    $summary = $this->statistics->report($this->filters)['summary'];
    $row = $this->statistics->details($this->filters)->items()[0];
    expect((int) $summary->visits)->toBe(1)->and((int) $summary->items)->toBe(0)
        ->and((int) $summary->itemless_visits)->toBe(1)->and((float) $summary->original_value)->toBe(0.0)
        ->and($row->reason)->toBe('Legacy free-text reason')->and($row->discount_comment)->toBe('Historical explanation')
        ->and($visit->fresh()->discount_reason)->toBe('Legacy free-text reason');
});

test('service currencies use saved conversion and salary currencies remain separate', function () {
    $visit = statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 100, 'quantity' => 2, 'currency' => 'USD', 'exchange_rate' => 2.5]]);
    $usd = statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 50]], ['currency' => 'USD']);
    app(SalarySettlementService::class)->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, auth()->id(),
        approvedFullDiscountItemIds: $visit->treatmentCaseItems()->pluck('id')->merge($usd->treatmentCaseItems()->pluck('id'))->all());
    $summary = $this->statistics->report($this->filters)['summary'];
    $usdSummary = $this->statistics->report(array_replace($this->filters, ['currency' => 'USD']))['summary'];
    expect((float) $summary->original_value)->toBe(500.0)
        ->and((float) $summary->salary_gel)->toBe((float) $visit->treatmentCaseItems->first()->salarySettlementItem->doctor_share_snapshot)
        ->and((float) $summary->salary_usd)->toBe(0.0)
        ->and((float) $usdSummary->original_value)->toBe(50.0)
        ->and((float) $usdSummary->salary_usd)->toBe(20.0)
        ->and($this->statistics->details(array_replace($this->filters, ['currency' => 'USD']))->items()[0]->visit_id)->toBe($usd->id);
});

test('Israeli lab quantities contribute only through actual linked visit items', function () {
    $this->doctor->update(['israeli_lab_zircon_rate' => 100]);
    $partner = Patient::create(['first_name' => 'Lab', 'last_name' => 'Partner', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $case = LabCase::create(['doctor_id' => $this->doctor->id, 'patient_id' => $partner->id, 'source' => 'israeli', 'case_date' => today()]);
    $linked = $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 2]);
    $case->mainWorks()->create(['material' => 'zircon', 'quantity' => 50]);
    statisticsDiscountVisit($this->doctor, $partner, [['price' => 500, 'quantity' => 2, 'category' => 'orthopedics', 'group' => 'zircon', 'lab_main_work_id' => $linked->id]]);
    $partner->partnerPayments()->create(['amount' => 10000, 'currency' => 'GEL', 'payment_method' => 'cash', 'paid_at' => now()]);
    app(SalarySettlementService::class)->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, auth()->id(), null, PatientGroup::ISRAEL_PARTNER_SLUG);
    $summary = $this->statistics->report($this->filters)['summary'];
    expect((int) $summary->visits)->toBe(1)->and((int) $summary->quantity)->toBe(2)
        ->and((float) $summary->original_value)->toBe(1000.0)->and((float) $summary->salary_gel)->toBe(200.0);
});

test('analytics does not create income or mutate Finance totals', function () {
    FinanceTransaction::create(['type' => 'income', 'transaction_date' => now(), 'category' => 'other_income',
        'amount' => 120, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 1000]]);
    $before = DB::table('finance_transactions')->get()->toJson();
    Livewire::test(FinanceReports::class)->assertViewHas('reportTotal', 120.0);
    Livewire::test(StatisticsPage::class)->assertOk()->call('openDetails');
    Livewire::test(FinanceReports::class)->assertViewHas('reportTotal', 120.0);
    expect(DB::table('finance_transactions')->get()->toJson())->toBe($before)
        ->and(DB::table('payments')->count())->toBe(0);
});

test('patient details are lazy paginated and scoped and filters reset open details', function () {
    $target = statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 1000]], ['discount_reason' => 'other', 'discount_comment' => 'Private reason comment']);
    for ($index = 0; $index < 26; $index++) {
        statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 10]]);
    }
    $page = Livewire::test(StatisticsPage::class)->assertOk()
        ->assertDontSee('LazyPrivatePatient')->assertDontSee('Private reason comment')
        ->assertViewHas('detailRows', null)->assertViewHas('expandedServices', null)
        ->call('expandServices', ['doctor_id' => $this->doctor->id])
        ->assertViewHas('detailRows', null)->assertDontSee('LazyPrivatePatient')
        ->call('openDetails')->assertSee('LazyPrivatePatient')
        ->assertViewHas('detailRows', fn ($rows) => count($rows->items()) === 25 && $rows->hasMorePages())
        ->call('changeDetailsPage', 2)
        ->assertViewHas('detailRows', fn ($rows) => count($rows->items()) === 2 && ! $rows->hasMorePages())
        ->assertSee('Private reason comment')
        ->call('openDetails', ['reason' => 'other'])
        ->assertViewHas('detailRows', fn ($rows) => count($rows->items()) === 1 && $rows->items()[0]->visit_id === $target->id)
        ->set('source', 'israel-partner')->assertViewHas('detailRows', null);
    $page->call('applyPeriod', 'all')->assertSet('dateFrom', null)->assertSet('dateUntil', null);
});

test('initial query count stays fixed and no raw patient detail query runs', function () {
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 100]]);
    $measure = function (): array {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->statistics->report($this->filters);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return ['queries' => count($queries), 'database_ms' => array_sum(array_column($queries, 'time'))];
    };
    $small = $measure();
    for ($index = 0; $index < 40; $index++) {
        statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 100]]);
    }
    $large = $measure();
    DB::enableQueryLog();
    DB::flushQueryLog();
    Livewire::test(StatisticsPage::class)->assertViewHas('detailRows', null);
    $pageQueries = DB::getQueryLog();
    DB::flushQueryLog();
    $this->get(StatisticsPage::getUrl())->assertOk();
    $httpQueries = DB::getQueryLog();
    DB::flushQueryLog();
    $this->statistics->details($this->filters);
    $detailQueries = DB::getQueryLog();
    DB::disableQueryLog();
    $metrics = ['one_visit' => $small, '41_visits' => $large,
        'initial_page_queries' => count($pageQueries), 'initial_page_database_ms' => array_sum(array_column($pageQueries, 'time')),
        'full_http_queries' => count($httpQueries), 'full_http_database_ms' => array_sum(array_column($httpQueries, 'time')),
        'detail_queries' => count($detailQueries)];
    file_put_contents(storage_path('logs/full-discount-statistics-performance.json'), json_encode($metrics, JSON_PRETTY_PRINT));
    expect($small['queries'])->toBe(6)->and($large['queries'])->toBe(6)
        ->and(count($pageQueries))->toBe(7)->and(count($detailQueries))->toBe(1)
        ->and(collect($pageQueries)->contains(fn ($query) => str_contains($query['query'], 'detail_patient')))->toBeFalse();
});

test('statistics lives in analytics navigation and preserves access to existing links', function () {
    expect(StatisticsPage::shouldRegisterNavigation())->toBeFalse();
    $this->get(FinanceReports::getUrl())->assertOk();
    $navigationUrls = collect(filament()->getNavigation())
        ->flatMap(fn ($group) => $group->getItems())
        ->map(fn ($item) => $item->getUrl());
    expect($navigationUrls)->not->toContain(StatisticsPage::getUrl());
    app()->setLocale('ka');
    expect(StatisticsPage::getNavigationLabel())->toBe('100% ფასდაკლებები');
    app()->setLocale('en');
    expect(StatisticsPage::getNavigationLabel())->toBe('100% Discounts')
        ->and(parse_url(StatisticsPage::getUrl(), PHP_URL_PATH))->toBe('/admin/full-discount-statistics');
    $this->get(StatisticsPage::getUrl())->assertOk();
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    expect(StatisticsPage::canAccess())->toBeFalse();
    $this->get(StatisticsPage::getUrl())->assertForbidden();
});

test('analytics switches through keyed sections and mounts discount aggregates only when active', function () {
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 1000]]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $page = Livewire::test(FinanceReports::class)
            ->assertSee(__('discount-statistics.title'))
            ->assertDontSee('data-full-discount-statistics', false);
        foreach (['dynamics', 'doctors', 'finance', 'dynamics', 'doctors'] as $tab) {
            $page->call('selectSectionTab', $tab)->assertSet('sectionTab', $tab)
                ->assertSee('reports-'.$tab.'-section', false)
                ->assertDontSee('data-full-discount-statistics', false);
        }
        expect(collect(DB::getQueryLog())->contains(fn ($query) => str_contains($query['query'], 'discount_work')))->toBeFalse();

        foreach ([1, 2] as $activation) {
            DB::flushQueryLog();
            $page->call('selectSectionTab', 'full_discounts')
                ->assertSee('reports-full-discounts-section', false)
                ->assertSee('data-full-discount-statistics', false)
                ->assertDontSee('LazyPrivatePatient');
            $queries = collect(DB::getQueryLog());
            expect($queries->filter(fn ($query) => str_contains($query['query'], 'discount_work'))->count())->toBe(6)
                ->and($queries->contains(fn ($query) => str_contains($query['query'], 'detail_patient')))->toBeFalse();
            DB::flushQueryLog();
            $page->call('selectSectionTab', 'finance')->assertDontSee('data-full-discount-statistics', false);
            expect(collect(DB::getQueryLog())->contains(fn ($query) => str_contains($query['query'], 'discount_work')))->toBeFalse();
        }
    } finally {
        DB::disableQueryLog();
    }
});

test('embedded statistics reuses filters and lazy details without page chrome', function () {
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 1000]]);
    Livewire::test(StatisticsPage::class, ['embedded' => true])
        ->assertViewIs('filament.pages.partials.full-discount-statistics-content')
        ->assertViewHas('detailRows', null)->assertDontSee('LazyPrivatePatient')
        ->call('openDetails')->assertSee('LazyPrivatePatient')
        ->set('source', 'israel-partner')->assertViewHas('detailRows', null)
        ->assertDontSee('LazyPrivatePatient');
});

test('management overview has exactly four KPIs and only the requested filters', function () {
    statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 1000]]);
    $page = Livewire::test(StatisticsPage::class)
        ->assertSee(__('discount-statistics.patients'))->assertSee(__('discount-statistics.visits'))
        ->assertSee(__('discount-statistics.free_value'))->assertSee(__('discount-statistics.salary'))
        ->assertSeeHtml('data-discount-salary-breakdown')
        ->assertSeeHtml('data-salary-breakdown="generated"')->assertSeeHtml('data-salary-breakdown="no_salary"')
        ->assertDontSeeHtml('wire:model.live="reason"')->assertDontSeeHtml('wire:model.live="discount"')
        ->assertDontSee(__('discount-statistics.value_note'))->assertDontSee(__('discount-statistics.salary_note'))
        ->assertDontSeeHtml('data-discount-patient-details')->assertDontSee('LazyPrivatePatient');
    expect(substr_count($page->html(), 'data-discount-kpi='))->toBe(4);
    foreach (['dateFrom', 'dateUntil', 'source', 'doctor', 'category', 'currency'] as $filter) {
        $page->assertSeeHtml('wire:model.live="'.$filter.'"');
    }
    expect(__('discount-statistics.patients', [], 'ka'))->toBe('პაციენტები')
        ->and(__('discount-statistics.visits', [], 'ka'))->toBe('ვიზიტები')
        ->and(__('discount-statistics.free_value', [], 'ka'))->toBe('უფასო მომსახურების ღირებულება')
        ->and(__('discount-statistics.salary', [], 'ka'))->toBe('გაცემული ექიმის ხელფასი');
    $page->assertViewHas('categoryOptions', fn ($options) => array_keys($options) === ['therapy', 'surgery', 'orthopedics', 'other']);
});

test('paid and unpaid salary breakdowns lazily open only their matching visits', function () {
    $paid = statisticsDiscountVisit($this->doctor, $this->patient, [['price' => 1000, 'name' => 'Paid service']]);
    app(SalarySettlementService::class)->settle($this->doctor->id, today()->toDateString(), today()->toDateString(), 40, auth()->id(),
        approvedFullDiscountItemIds: $paid->treatmentCaseItems()->pluck('id')->all());
    $unpaidPatient = Patient::create(['first_name' => 'UnpaidPrivatePatient', 'last_name' => 'Statistics']);
    $unpaid = statisticsDiscountVisit($this->doctor, $unpaidPatient, [['price' => 500, 'name' => 'Unpaid service']]);
    Livewire::test(StatisticsPage::class)
        ->assertViewHas('detailRows', null)->assertDontSee('LazyPrivatePatient')->assertDontSee('UnpaidPrivatePatient')
        ->call('openDetails', ['salary_status' => 'generated'])
        ->assertSee('LazyPrivatePatient')->assertDontSee('UnpaidPrivatePatient')
        ->assertSee(__('discount-statistics.salary_paid'))->assertSee(__('discount-statistics.yes'))
        ->assertViewHas('detailRows', fn ($rows) => count($rows->items()) === 1 && $rows->items()[0]->visit_id === $paid->id)
        ->call('openDetails', ['salary_status' => 'no_salary'])
        ->assertSee('UnpaidPrivatePatient')->assertDontSee('LazyPrivatePatient')->assertSee(__('discount-statistics.no'))
        ->assertViewHas('detailRows', fn ($rows) => count($rows->items()) === 1 && $rows->items()[0]->visit_id === $unpaid->id)
        ->call('closeDetails')->assertViewHas('detailRows', null);
});

test('Other category filter matches the existing collapsed Other category totals', function () {
    statisticsDiscountVisit($this->doctor, $this->patient, [
        ['price' => 100, 'category' => 'therapy'],
        ['price' => 150, 'category' => 'consultation'],
        ['price' => 200, 'category' => 'periodontology'],
    ]);
    $all = $this->statistics->report($this->filters);
    $other = $this->statistics->report([...$this->filters, 'category' => 'other']);
    expect((float) $other['summary']->free_value)->toBe(350.0)
        ->and((float) $other['summary']->free_value)->toBe((float) $all['categories']->firstWhere('category_key', 'other')->free_value);
    Livewire::test(StatisticsPage::class)->set('category', 'other')->call('openDetails')
        ->assertViewHas('detailRows', fn ($rows) => count($rows->items()) === 2);
});
