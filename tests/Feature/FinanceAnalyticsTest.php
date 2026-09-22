<?php

use App\Filament\Pages\FinanceReports;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\Finance\AccountingLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('analytics uses ledger expenses including bank and cash without imported credit or transfer revenue', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->travelTo(now()->setDate(2026, 9, 22));
    foreach ([['income', 1000], ['expense', 100]] as [$type, $amount]) {
        FinanceTransaction::create(['type' => $type, 'amount' => $amount, 'currency' => 'GEL',
            'category' => $type === 'income' ? 'other_income' : 'materials', 'transaction_date' => today(),
            'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    }
    foreach ([['expense', 'outflow', 200, false], ['settlement', 'inflow', 500, false], ['transfer', 'outflow', 900, false], ['expense', 'outflow', 700, true]] as [$treatment, $direction, $amount, $excluded]) {
        $category = BankCategory::create(['name' => $treatment, 'accounting_treatment' => $treatment]);
        BankTransaction::create(['transaction_date' => today(), 'direction' => $direction, 'amount' => $amount,
            'currency' => 'GEL', 'bank_category_id' => $category->id, 'exclude_from_pnl' => $excluded,
            'is_legacy' => false, 'source' => 'api', 'fingerprint' => Str::random(64), 'deduplication_key' => Str::random(64)]);
    }
    $expense = (float) app(AccountingLedger::class)->pnl('2026-09-01', '2026-09-30')->where('metric', 'expense')->sum('amount');
    expect($expense)->toBe(300.0);
    $page = Livewire::test(FinanceReports::class)->set('dateFrom', '2026-09-01')->set('dateUntil', '2026-09-30');
    $check = function (array $data) use ($expense): bool {
        expect($data['incomeTotal'])->toBe(1000.0)
            ->and($data['expenseTotal'])->toBe($expense)
            ->and($data['profit'])->toBe(700.0)
            ->and(array_sum($data['income']))->toBe(1000.0)
            ->and(array_sum($data['expense']))->toBe(300.0)
            ->and(array_sum($data['trend']['profit']))->toBe(700.0)
            ->and($data['expense'])->toHaveCount(2);

        return true;
    };
    $page->assertViewHas('analytics', $check)
        ->set('selectedDoctorId', 999)->assertViewHas('analytics', $check)
        ->set('financialCurrency', 'USD')->assertViewHas('analytics', fn ($data) => $data['incomeTotal'] === 0.0 && $data['expenseTotal'] === 0.0)
        ->set('financialCurrency', 'GEL')->set('source', 'clinic')
        ->assertViewHas('analytics', fn ($data) => $data['expenseTotal'] === 100.0)
        ->set('source', 'all')->set('dateFrom', '2026-01-01')
        ->assertViewHas('analytics', fn ($data) => count($data['trend']['labels']) === 9 && array_sum($data['trend']['profit']) === 700.0)
        ->set('dateUntil', '2026-08-31')
        ->assertViewHas('analytics', fn ($data) => $data['incomeTotal'] === 0.0 && $data['expenseTotal'] === 0.0);
});
