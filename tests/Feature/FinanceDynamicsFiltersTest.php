<?php

use App\Filament\Pages\FinanceReports;
use App\Models\FinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00'));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
});

function dynamicsFilterIncome(string $date, float $amount, string $currency = 'GEL'): void
{
    FinanceTransaction::create(['type' => 'income', 'category' => 'other_income', 'transaction_date' => $date,
        'amount' => $amount, 'currency' => $currency, 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
}

test('Dynamics presets use the requested inclusive ranges', function ($preset, $from, $until) {
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'dynamics')
        ->call('applyDynamicsDatePreset', $preset)
        ->assertSet('period', $preset)->assertSet('dateFrom', $from)->assertSet('dateUntil', $until);
})->with([
    ['14_days', '2026-08-28', '2026-09-10'],
    ['1_month', '2026-08-11', '2026-09-10'],
    ['3_months', '2026-06-11', '2026-09-10'],
    ['6_months', '2026-03-11', '2026-09-10'],
    ['all', '', ''],
]);

test('Dynamics reuses the compact toolbar and exposes exactly five localized presets', function ($locale, $labels) {
    app()->setLocale($locale);
    $page = Livewire::test(FinanceReports::class)->call('selectSectionTab', 'dynamics')
        ->assertSeeHtml('renome-visits-toolbar__period')->assertSeeHtml('renome-visits-toolbar__period-dropdown')
        ->assertSeeHtml('x-model="fromDisplay"')->assertSeeHtml('x-model="untilDisplay"')
        ->assertSeeHtml('wire:model.live="source"')->assertSeeHtml('wire:model.live="currency"');
    foreach ($labels as $label) {
        $page->assertSee($label);
    }
    expect(substr_count($page->html(), 'wire:click="applyDynamicsDatePreset('))->toBe(5);
    $page->assertDontSeeHtml("applyDynamicsDatePreset('1_year')")->assertDontSeeHtml('wire:click="applyDoctorsDatePreset(');
})->with([
    ['en', ['2 weeks', '1 month', '3 months', '6 months', 'All']],
    ['ka', ['2 კვირა', '1 თვე', '3 თვე', '6 თვე', 'სულ']],
]);

test('All includes pre-2000 history and chart gaps without artificial date bounds', function () {
    dynamicsFilterIncome('1999-01-15', 100);
    dynamicsFilterIncome('1999-03-15', 200);
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'dynamics')->call('applyDynamicsDatePreset', 'all')
        ->assertSet('dateFrom', '')->assertSet('dateUntil', '')->assertDontSee('01.01.2000')
        ->assertViewHas('dynamics', fn ($data) => $data['incomeTotal'] === 300.0
            && $data['labels'] === ['01.1999', '02.1999', '03.1999'] && $data['income'] === [100.0, 0.0, 200.0]);
});

test('custom dates after All stay custom and source currency and chart totals keep working', function () {
    dynamicsFilterIncome('2026-09-01', 100);
    dynamicsFilterIncome('2026-09-05', 200);
    dynamicsFilterIncome('2026-09-05', 75, 'USD');
    $partner = Patient::create(['first_name' => 'Dynamics', 'last_name' => 'Partner', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $partner->partnerPayments()->create(['amount' => 50, 'currency' => 'GEL', 'payment_method' => 'cash', 'paid_at' => '2026-09-05']);
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'dynamics')->call('applyDynamicsDatePreset', 'all')
        ->assertViewHas('dynamics', fn ($data) => $data['incomeTotal'] === 350.0)
        ->set('dateFrom', '2026-09-05')->set('dateUntil', '2026-09-05')->assertSet('period', 'custom')
        ->assertViewHas('dynamics', fn ($data) => $data['incomeTotal'] === 250.0 && $data['labels'] === ['05.09'])
        ->set('source', 'clinic')->assertSet('period', 'custom')
        ->assertViewHas('dynamics', fn ($data) => $data['incomeTotal'] === 200.0)
        ->set('currency', 'USD')->assertViewHas('dynamics', fn ($data) => $data['incomeTotal'] === 75.0)
        ->set('source', 'partner')->set('currency', 'GEL')
        ->assertViewHas('dynamics', fn ($data) => $data['incomeTotal'] === 50.0);
});

test('presets refresh Dynamics once using the existing six aggregate queries', function () {
    dynamicsFilterIncome('2026-09-01', 100);
    $page = Livewire::test(FinanceReports::class)->call('selectSectionTab', 'dynamics');
    foreach (['14_days', '3_months', '6_months', 'all'] as $preset) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('applyDynamicsDatePreset', $preset);
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        expect($queries)->toHaveCount(6);
        foreach ($queries as $query) {
            expect(strtolower($query['query']))->toContain('sum(')->toContain('group by');
            if ($preset === 'all') {
                expect(strtolower($query['query']))->not->toContain('between');
            }
        }
    }
});

test('month presets clamp the previous month and switching sections retains existing defaults', function () {
    $this->travelTo(CarbonImmutable::parse('2026-03-31 12:00:00'));
    Livewire::test(FinanceReports::class)->call('selectSectionTab', 'dynamics')
        ->call('applyDynamicsDatePreset', '1_month')->assertSet('dateFrom', '2026-03-01')
        ->call('applyDynamicsDatePreset', 'all')->call('selectSectionTab', 'doctors')
        ->assertSet('period', 'all')->assertSet('dateFrom', '2000-01-01')
        ->call('applyDoctorsDatePreset', '14_days')->assertSet('dateFrom', '2026-03-18');
});
