<?php

use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\CashboxTransaction;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\User;
use App\Services\PartnerFinanceSummary;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('partner finance preserves revenue through exchange transfer and expense flow', function () {
    $patient = Patient::create([
        'first_name' => 'Finance',
        'last_name' => 'Partner',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 1000,
        'currency' => 'USD',
        'payment_method' => 'cash',
        'paid_at' => '2026-08-27',
    ]);

    PartnerFinanceTransaction::create([
        'type' => PartnerFinanceTransaction::TYPE_EXCHANGE,
        'transacted_at' => '2026-08-27',
        'from_account' => 'cash',
        'from_currency' => 'USD',
        'from_amount' => 1000,
        'to_account' => 'cash',
        'to_currency' => 'GEL',
        'to_amount' => 2700,
        'exchange_rate' => 2.7,
    ]);
    PartnerFinanceTransaction::create([
        'type' => PartnerFinanceTransaction::TYPE_TRANSFER,
        'transacted_at' => '2026-08-27',
        'from_account' => 'cash',
        'to_account' => 'bank',
        'amount' => 2700,
        'currency' => 'GEL',
    ]);
    PartnerFinanceTransaction::create([
        'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
        'transacted_at' => '2026-08-27',
        'category' => 'salary',
        'from_account' => 'bank',
        'amount' => 1000,
        'currency' => 'GEL',
    ]);

    $summary = app(PartnerFinanceSummary::class);

    expect($summary->receivedTotals())->toBe(['GEL' => 0.0, 'USD' => 1000.0])
        ->and($summary->expenseTotals())->toBe(['GEL' => 1000.0, 'USD' => 0.0])
        ->and($summary->accountBalances())->toBe([
            'cash' => ['GEL' => 0.0, 'USD' => 0.0],
            'bank' => ['GEL' => 1700.0, 'USD' => 0.0],
        ])
        ->and(PartnerFinanceEntry::query()->where('transaction_type', 'payment')->count())->toBe(1)
        ->and(PartnerFinanceEntry::query()->count())->toBe(4)
        ->and(Payment::query()->count())->toBe(0)
        ->and(CashboxTransaction::query()->count())->toBe(0)
        ->and(FinanceTransaction::query()->count())->toBe(0);
});

test('partner finance page lists payments and transactions with operational actions', function () {
    $this->actingAs(User::factory()->create());
    $patient = Patient::create([
        'first_name' => 'Listed',
        'last_name' => 'Partner',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 250,
        'currency' => 'GEL',
        'payment_method' => 'bank_transfer',
        'paid_at' => '2026-08-27',
        'notes' => 'Partner receipt',
    ]);
    PartnerFinanceTransaction::create([
        'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
        'transacted_at' => '2026-08-27',
        'category' => 'laboratory',
        'from_account' => 'bank',
        'amount' => 50,
        'currency' => 'GEL',
    ]);

    Livewire::test(ListPartnerFinance::class)
        ->assertOk()
        ->assertActionExists(TestAction::make('addExpense'))
        ->assertActionExists(TestAction::make('transfer'))
        ->assertActionExists(TestAction::make('currencyExchange'))
        ->assertCanSeeTableRecords(PartnerFinanceEntry::query()->get())
        ->assertSee($patient->full_name)
        ->assertSee('Partner receipt')
        ->assertSee('250.00 ₾')
        ->callAction(TestAction::make('addExpense'), [
            'transacted_at' => '2026-08-27',
            'category' => 'other',
            'amount' => 25,
            'currency' => 'GEL',
            'from_account' => 'cash',
            'notes' => 'Action expense',
        ])
        ->assertHasNoActionErrors();

    expect(PartnerFinanceTransaction::query()->where('type', 'expense')->count())->toBe(2)
        ->and(Payment::query()->count())->toBe(0)
        ->and(CashboxTransaction::query()->count())->toBe(0);
});
