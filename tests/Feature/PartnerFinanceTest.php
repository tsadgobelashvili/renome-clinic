<?php

use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Filament\Resources\PartnerFinance\Tables\PartnerFinanceTable;
use App\Filament\Resources\PartnerPatients\PartnerPatientResource;
use App\Models\CashboxTransaction;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\User;
use App\Services\ExpenseDimensions;
use App\Services\FinanceUsdUsageService;
use App\Services\PartnerFinanceSummary;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('israeli finance amount colors follow the transaction classification', function () {
    expect(PartnerFinanceTable::semanticColor('payment'))->toBe('success')
        ->and(PartnerFinanceTable::semanticColor(PartnerFinanceTransaction::TYPE_EXPENSE))->toBe('danger')
        ->and(PartnerFinanceTable::semanticColor(PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL))->toBe('danger')
        ->and(PartnerFinanceTable::semanticColor(PartnerFinanceTransaction::TYPE_EXCHANGE))->toBe('info')
        ->and(PartnerFinanceTable::semanticColor(PartnerFinanceTransaction::TYPE_TRANSFER))->toBe('info');
});

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
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create([
        'first_name' => 'Listed',
        'last_name' => 'Partner',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 250,
        'currency' => 'GEL',
        'payment_method' => 'bank_transfer',
        'paid_at' => today(),
        'notes' => 'Partner receipt',
    ]);
    $patient->partnerPayments()->create([
        'amount' => 25,
        'currency' => 'USD',
        'payment_method' => 'cash',
        'paid_at' => today(),
    ]);
    PartnerFinanceTransaction::create([
        'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
        'transacted_at' => now(),
        'category' => 'laboratory',
        'from_account' => 'bank',
        'amount' => 50,
        'currency' => 'GEL',
    ]);

    Livewire::test(ListPartnerFinance::class)
        ->assertOk()
        ->assertActionExists(TestAction::make('addPatientPayment'))
        ->assertActionExists(TestAction::make('useIsraeliFunds'))
        ->assertCanSeeTableRecords(PartnerFinanceEntry::query()->get())
        ->assertSee($patient->full_name)
        ->assertSee('Partner receipt')
        ->assertSee('250.00 ₾')
        ->callAction(TestAction::make('useIsraeliFunds'), [
            'transacted_at' => now(),
            'operation_type' => 'other',
            'expense_direction_id' => app(ExpenseDimensions::class)->id('direction', 'general'),
            'expense_type_id' => app(ExpenseDimensions::class)->id('type', 'other', app(ExpenseDimensions::class)->id('direction', 'general')),
            'payment_mode' => 'direct_usd',
            'actual_amount' => 25,
            'notes' => 'Action expense',
        ])
        ->assertHasNoActionErrors();

    expect(PartnerFinanceTransaction::query()->where('type', 'expense')->count())->toBe(2)
        ->and(Payment::query()->count())->toBe(0)
        ->and(CashboxTransaction::query()->count())->toBe(0);
});

test('only an Israeli payment patient name receives a profile URL', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'is_active' => true]));
    $patient = Patient::create([
        'first_name' => 'Linked',
        'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $payment = $patient->partnerPayments()->create([
        'amount' => 125,
        'currency' => 'USD',
        'payment_method' => 'cash',
        'paid_at' => today(),
    ]);
    $expense = PartnerFinanceTransaction::create([
        'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'transacted_at' => now(),
        'category' => 'other',
        'from_account' => 'cash',
        'amount' => 10,
        'currency' => 'USD',
    ]);
    $paymentEntry = PartnerFinanceEntry::query()->where('entry_key', 'payment-'.$payment->getKey())->firstOrFail();
    $expenseEntry = PartnerFinanceEntry::query()->where('entry_key', 'transaction-'.$expense->getKey())->firstOrFail();
    $patientUrl = PartnerFinanceTable::recipientUrl($paymentEntry);

    expect($patientUrl)->toBe(PartnerPatientResource::getUrl('view', ['record' => $patient]))
        ->and(PartnerFinanceTable::recipientUrl($expenseEntry))->toBeNull();

    $this->get($patientUrl)->assertOk();
});

test('israeli exchange preserves unspent gel and records only actual spending as expense', function () {
    $patient = Patient::create([
        'first_name' => 'Exchange',
        'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 5000,
        'currency' => 'USD',
        'payment_method' => 'cash',
        'paid_at' => '2026-09-01',
    ]);

    app(FinanceUsdUsageService::class)->recordIsraeliOperation([
        'transacted_at' => '2026-09-01 12:00:00',
        'operation_type' => 'lab_salary',
        'payment_mode' => 'exchange_usd_gel',
        'usd_amount' => 5000,
        'exchange_rate' => 2.70,
        'actual_amount' => 13473,
        'recipient' => null,
        'notes' => 'September salaries',
    ]);

    expect(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)->count())->toBe(1)
        ->and(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)->value('amount'))->toBe('13473.00')
        ->and(app(FinanceUsdUsageService::class)->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI))->toBe([
            'GEL' => 27.0,
            'USD' => 0.0,
        ])
        ->and(app(PartnerFinanceSummary::class)->expenseTotals())->toBe(['GEL' => 13473.0, 'USD' => 0.0]);
});

test('israeli bank deposit and owner withdrawal stay outside expenses', function () {
    $patient = Patient::create([
        'first_name' => 'Movement',
        'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 1000,
        'currency' => 'USD',
        'payment_method' => 'cash',
        'paid_at' => '2026-09-01',
    ]);
    $service = app(FinanceUsdUsageService::class);

    $service->recordIsraeliOperation([
        'transacted_at' => '2026-09-01 12:00:00',
        'operation_type' => 'bank_deposit',
        'payment_mode' => 'direct_usd',
        'actual_amount' => 300,
    ]);
    $service->recordIsraeliOperation([
        'transacted_at' => '2026-09-01 13:00:00',
        'operation_type' => 'owner_withdrawal',
        'payment_mode' => 'direct_usd',
        'actual_amount' => 200,
        'recipient' => 'Owner',
    ]);

    expect(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_TRANSFER)->count())->toBe(1)
        ->and(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_OWNER_WITHDRAWAL)->count())->toBe(1)
        ->and(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)->count())->toBe(0)
        ->and($service->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI)['USD'])->toBe(500.0)
        ->and(app(PartnerFinanceSummary::class)->expenseTotals())->toBe(['GEL' => 0.0, 'USD' => 0.0]);
});

test('direct gel Israeli fund usage spends gel without creating an exchange', function () {
    $patient = Patient::create([
        'first_name' => 'Direct',
        'last_name' => 'Gel',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 1000,
        'currency' => 'GEL',
        'payment_method' => 'cash',
        'paid_at' => '2026-09-01',
    ]);

    $service = app(FinanceUsdUsageService::class);
    $result = $service->recordIsraeliOperation([
        'transacted_at' => '2026-09-01 12:00:00',
        'operation_type' => 'materials',
        'payment_mode' => 'direct_gel',
        'actual_amount' => 350,
        'recipient' => 'Supplier',
    ]);

    expect($result['exchange'])->toBeNull()
        ->and($result['movement']->type)->toBe(PartnerFinanceTransaction::TYPE_EXPENSE)
        ->and($result['movement']->currency)->toBe('GEL')
        ->and($service->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI))->toBe([
            'GEL' => 650.0,
            'USD' => 0.0,
        ])
        ->and(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)->count())->toBe(0);
});

test('direct gel Israeli fund usage rejects an amount above available gel cash', function () {
    $patient = Patient::create([
        'first_name' => 'Limited',
        'last_name' => 'Gel',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 100,
        'currency' => 'GEL',
        'payment_method' => 'cash',
        'paid_at' => '2026-09-01',
    ]);

    expect(fn () => app(FinanceUsdUsageService::class)->recordIsraeliOperation([
        'transacted_at' => '2026-09-01 12:00:00',
        'operation_type' => 'other',
        'payment_mode' => 'direct_gel',
        'actual_amount' => 100.01,
    ]))->toThrow(ValidationException::class);

    expect(PartnerFinanceTransaction::query()->count())->toBe(0);
});

test('israeli finance summary shows current cash and cumulative bank deposits separately', function () {
    $patient = Patient::create([
        'first_name' => 'Summary',
        'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    foreach (['GEL' => 1000, 'USD' => 500] as $currency => $amount) {
        $patient->partnerPayments()->create([
            'amount' => $amount,
            'currency' => $currency,
            'payment_method' => 'cash',
            'paid_at' => '2026-09-01',
        ]);
    }
    foreach (['GEL' => 300, 'USD' => 100] as $currency => $amount) {
        PartnerFinanceTransaction::create([
            'type' => PartnerFinanceTransaction::TYPE_TRANSFER,
            'transacted_at' => '2026-09-01',
            'category' => 'bank_deposit',
            'from_account' => 'cash',
            'to_account' => 'bank',
            'amount' => $amount,
            'currency' => $currency,
        ]);
    }

    $summary = app(PartnerFinanceSummary::class);

    expect($summary->currentCashTotals())->toBe(['GEL' => 700.0, 'USD' => 400.0])
        ->and($summary->bankDepositedTotals())->toBe(['GEL' => 300.0, 'USD' => 100.0]);
});

test('israeli finance page renders three overview cards with inline period and movement filters', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-05 12:00:00'));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create([
        'first_name' => 'History',
        'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 500,
        'currency' => 'USD',
        'payment_method' => 'cash',
        'paid_at' => '2026-09-01',
    ]);
    PartnerFinanceTransaction::create([
        'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
        'transacted_at' => '2026-09-01',
        'category' => 'materials',
        'from_account' => 'cash',
        'amount' => 50,
        'currency' => 'USD',
    ]);

    $payments = PartnerFinanceEntry::query()->where('transaction_type', 'payment')->get();
    $expenses = PartnerFinanceEntry::query()->where('transaction_type', PartnerFinanceTransaction::TYPE_EXPENSE)->get();

    Livewire::test(ListPartnerFinance::class)
        ->assertOk()
        ->assertSee('ისრაელის ფინანსები')
        ->assertSee('მიმდინარე ქეში')
        ->assertSee('შემოსავალი')
        ->assertSee('ხარჯი')
        ->assertDontSee('ბანკში შეტანილი GEL')
        ->assertDontSeeHtml('fi-ta-filters-dropdown')
        ->assertSet('period', '7_days')
        ->set('movementType', 'payment')
        ->assertCanSeeTableRecords($payments)
        ->assertCanNotSeeTableRecords($expenses)
        ->set('movementType', PartnerFinanceTransaction::TYPE_EXPENSE)
        ->assertCanSeeTableRecords($expenses)
        ->assertCanNotSeeTableRecords($payments)
        ->set('period', '1_month')
        ->assertSet('dateUntil', today()->toDateString())
        ->set('dateFrom', today()->subDays(2)->toDateString())
        ->assertSet('period', 'custom');
});

test('israeli finance summary separates direct gel from exchange funded expenses and groups three movement dates', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create([
        'first_name' => 'Funding', 'last_name' => 'Summary',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 2000, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now(),
    ]);
    app(FinanceUsdUsageService::class)->recordIsraeliOperation([
        'transacted_at' => now()->startOfSecond(),
        'operation_type' => 'lab_salary', 'payment_mode' => 'exchange_usd_gel',
        'usd_amount' => 1500, 'exchange_rate' => 2.61, 'actual_amount' => 3500,
    ]);
    PartnerFinanceTransaction::create([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
        'transacted_at' => now()->addMinute(), 'category' => 'other_expense',
        'from_account' => 'cash', 'amount' => 100, 'currency' => 'GEL',
    ]);
    foreach ([1, 2, 3] as $daysAgo) {
        PartnerFinanceTransaction::create([
            'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
            'type' => PartnerFinanceTransaction::TYPE_TRANSFER,
            'transacted_at' => now()->subDays($daysAgo), 'category' => 'bank_deposit',
            'from_account' => 'cash', 'to_account' => 'bank',
            'amount' => 10, 'currency' => 'GEL',
        ]);
    }

    Livewire::test(ListPartnerFinance::class)
        ->assertSee('პირდაპირი GEL:')
        ->assertSee('გადახურდავებული USD-დან')
        ->assertSee('$1,500.00 → 3,500.00 ₾')
        ->assertSee(today()->format('d.m.Y'))
        ->assertSee(today()->subDay()->format('d.m.Y'))
        ->assertSee(today()->subDays(2)->format('d.m.Y'));

    $overview = Livewire::test(ListPartnerFinance::class)->instance()->overview();
    expect($overview['expense_funding'])->toBe([
        'direct_gel' => 100.0,
        'exchanged_usd' => 1500.0,
        'funded_gel' => 3500.0,
    ])->and($overview['movements'])->toHaveCount(3)
        ->and(collect($overview['movements'])->pluck('date')->all())->not->toContain(today()->subDays(3)->format('d.m.Y'));
});

test('Israeli usage labels are short while existing payment mode keys remain unchanged', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    Livewire::test(ListPartnerFinance::class)->mountAction(TestAction::make('useIsraeliFunds'))
        ->assertFormFieldExists('payment_mode', fn ($field) => $field->getOptions() === [
            'direct_gel' => 'GEL', 'direct_usd' => 'USD', 'exchange_usd_gel' => 'USD → GEL გაცვლა',
        ]);
});
