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

test('Finance All custom source and currency keep totals and donut rows aligned', function () {
    financeFilterIncome('1999-01-15', 100);
    financeFilterIncome('2026-09-05', 200);
    financeFilterIncome('2026-09-05', 75, 'USD');
    $partner = Patient::create(['first_name' => 'Finance', 'last_name' => 'Partner', 'patient_group_id' => PatientGroup::israelPartnerId()]);
    $partner->partnerPayments()->create(['amount' => 50, 'currency' => 'GEL', 'payment_method' => 'cash', 'paid_at' => '2026-09-05']);
    Livewire::test(FinanceReports::class)->call('applyFinanceDatePreset', 'all')
        ->assertSet('dateFrom', '')->assertSet('dateUntil', '')->assertDontSee('01.01.2000')
        ->assertViewHas('reportTotal', 350.0)
        ->assertViewHas('chartRows', fn ($rows) => array_sum(array_column($rows, 'amount')) === 350.0)
        ->set('dateFrom', '2026-09-05')->set('dateUntil', '2026-09-05')->assertSet('period', 'custom')
        ->assertViewHas('reportTotal', 250.0)
        ->set('source', 'clinic')->assertViewHas('reportTotal', 200.0)
        ->set('currency', 'USD')->assertViewHas('reportTotal', 75.0)
        ->set('source', 'partner')->set('currency', 'GEL')->assertViewHas('reportTotal', 50.0);
});

test('Finance All includes historical expenses in totals and donuts', function ($tab) {
    FinanceTransaction::create(['type' => 'expense', 'category' => 'other', 'transaction_date' => '1999-01-15',
        'amount' => 125, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    Livewire::test(FinanceReports::class)->call('selectReportTab', $tab)
        ->call('applyFinanceDatePreset', '1_month')->assertViewHas('reportTotal', 0.0)
        ->call('applyFinanceDatePreset', 'all')->assertViewHas('reportTotal', 125.0)
        ->assertViewHas('chartRows', fn ($rows) => array_sum(array_column($rows, 'amount')) === 125.0)
        ->call('selectSectionTab', 'dynamics')->assertSet('dateFrom', '')
        ->call('selectSectionTab', 'finance')->assertViewHas('reportTotal', 125.0);
})->with(['expense', 'cash_out']);

test('Finance presets retain the existing query count for every report tab', function ($tab) {
    financeFilterIncome('1999-01-15', 100);
    financeFilterIncome('2026-09-05', 200);
    $page = Livewire::test(FinanceReports::class)->call('selectReportTab', $tab);
    $baseline = null;
    foreach (['1_month', '14_days', '3_months', '6_months', '1_year', 'all'] as $preset) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $page->call('applyFinanceDatePreset', $preset);
        $queries = collect(DB::getQueryLog());
        DB::disableQueryLog();
        $baseline ??= $queries->count();
        expect($queries)->toHaveCount($baseline);
        expect($queries->contains(fn ($query) => str_contains(strtolower($query['query']), 'sum(')))->toBeTrue();
        if ($preset === 'all') {
            foreach ($queries as $query) {
                expect(strtolower($query['query']))->not->toContain('between');
            }
        }
    }
})->with(['income', 'expense', 'cash_out']);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-10 12:00:00'));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
});

function financeFilterIncome(string $date, float $amount, string $currency = 'GEL'): void
{
    FinanceTransaction::create(['type' => 'income', 'category' => 'other_income', 'transaction_date' => $date,
        'amount' => $amount, 'currency' => $currency, 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
}

test('Finance presets use the requested inclusive ranges', function ($preset, $from, $until) {
    Livewire::test(FinanceReports::class)
        ->call('applyFinanceDatePreset', $preset)
        ->assertSet('period', $preset)->assertSet('dateFrom', $from)->assertSet('dateUntil', $until);
})->with([
    ['14_days', '2026-08-28', '2026-09-10'],
    ['1_month', '2026-08-11', '2026-09-10'],
    ['3_months', '2026-06-11', '2026-09-10'],
    ['6_months', '2026-03-11', '2026-09-10'],
    ['1_year', '2025-09-11', '2026-09-10'],
    ['all', '', ''],
]);

test('Finance reuses the compact toolbar and exposes exactly six localized presets', function ($locale, $labels) {
    app()->setLocale($locale);
    $page = Livewire::test(FinanceReports::class)
        ->assertSeeHtml('renome-visits-toolbar__period')->assertSeeHtml('renome-visits-toolbar__period-dropdown')
        ->assertSeeHtml('x-model="fromDisplay"')->assertSeeHtml('x-model="untilDisplay"')
        ->assertSeeHtml('wire:model.live="source"')->assertSeeHtml('wire:model.live="currency"');
    foreach ($labels as $label) {
        $page->assertSee($label);
    }
    expect(substr_count($page->html(), 'wire:click="applyFinanceDatePreset('))->toBe(6);
    $page->assertDontSeeHtml('wire:click="applyDoctorsDatePreset(');
})->with([
    ['en', ['2 weeks', '1 month', '3 months', '6 months', '1 year', 'All']],
    ['ka', ['2 კვირა', '1 თვე', '3 თვე', '6 თვე', '1 წელი', 'სულ']],
]);
