<?php

use App\Filament\Pages\Finance;
use App\Filament\Resources\PartnerFinance\Pages\ListPartnerFinance;
use App\Models\CashboxTransaction;
use App\Models\DirectExpense;
use App\Models\Doctor;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceEntry;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\PatientGroup;
use App\Models\Payment;
use App\Models\TreatmentCase;
use App\Models\User;
use App\Models\Visit;
use App\Services\FinanceManager;
use App\Services\FinanceUsdUsageService;
use App\Support\CashboxManager;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('finance history amount colors follow transaction type semantics', function () {
    expect(Finance::amountTextClasses('income'))->toContain('text-emerald-600')
        ->and(Finance::amountTextClasses('expense'))->toContain('text-rose-600')
        ->and(Finance::amountTextClasses('salary_cash'))->toContain('text-rose-600')
        ->and(Finance::amountTextClasses('owner_withdrawal'))->toContain('text-rose-600')
        ->and(Finance::amountTextClasses('exchange'))->toContain('text-sky-600')
        ->and(Finance::amountTextClasses('transfer'))->toContain('text-gray-700')
        ->and(Finance::typeBadgeClasses('income'))->toContain('bg-emerald-50')
        ->and(Finance::typeBadgeClasses('expense'))->toContain('bg-rose-50')
        ->and(Finance::typeBadgeClasses('salary_cash'))->toContain('bg-rose-50')
        ->and(Finance::typeBadgeClasses('owner_withdrawal'))->toContain('bg-rose-50')
        ->and(Finance::typeBadgeClasses('exchange'))->toContain('bg-sky-50')
        ->and(Finance::typeBadgeClasses('transfer'))->toContain('bg-gray-100');
});

function financeVisit(float $total = 3000): Visit
{
    $patient = Patient::create(['first_name' => 'Finance', 'last_name' => 'Patient']);
    $doctor = Doctor::create(['first_name' => 'Finance', 'last_name' => 'Doctor', 'is_active' => true]);

    return Visit::create([
        'patient_id' => $patient->getKey(), 'doctor_id' => $doctor->getKey(),
        'visit_date' => today(), 'total_price' => $total, 'currency' => 'GEL',
    ]);
}

test('patient payment is finance income while cashier keeps each method and physical cash separately', function (array $splits, float $cashExpected) {
    $visit = financeVisit();
    $payment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 500, 'currency' => 'GEL',
        'payment_date' => today()->toDateString(),
    ], $splits);

    $cashboxAmount = (float) CashboxTransaction::query()->where('payment_id', $payment->getKey())->sum('amount');
    $financeIncome = (float) Payment::query()->whereKey($payment)->sum('amount');

    expect($financeIncome)->toBe(500.0)
        ->and($cashboxAmount)->toBe(500.0)
        ->and(app(CashboxManager::class)->today()->summary()['cashIncome'])->toBe($cashExpected)
        ->and(Payment::query()->whereKey($payment)->count())->toBe(1)
        ->and(CashboxTransaction::query()->where('payment_id', $payment->getKey())->count())->toBe(count($splits));
})->with([
    'cash' => [[['payment_method' => 'cash', 'amount' => 500]], 500],
    'card' => [[['payment_method' => 'card', 'amount' => 500]], 0],
    'bank transfer' => [[['payment_method' => 'bank_transfer', 'amount' => 500]], 0],
    'split' => [[['payment_method' => 'cash', 'amount' => 200], ['payment_method' => 'card', 'amount' => 300]], 200],
]);

test('finance payment history reconciles gel usd and mixed splits with cashbox exactly once', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $visit = financeVisit(5000);
    $gelPayment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 100, 'currency' => 'GEL', 'payment_date' => today(),
    ], [['payment_method' => 'cash', 'amount' => 100, 'currency' => 'GEL']]);
    $usdPayment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 123.88, 'currency' => 'GEL', 'payment_date' => today(),
    ], [['payment_method' => 'cash', 'amount' => 45.88, 'currency' => 'USD', 'exchange_rate' => 2.7]]);
    $mixedPayment = Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 712.97, 'currency' => 'GEL', 'payment_date' => today(),
    ], [
        ['payment_method' => 'cash', 'amount' => 300, 'currency' => 'GEL'],
        ['payment_method' => 'cash', 'amount' => 152.95, 'currency' => 'USD', 'exchange_rate' => 2.7],
    ]);

    $page = Livewire::test(Finance::class)
        ->call('showHistory', 'payments')
        ->set('source', 'clinic')
        ->set('currency', 'GEL')
        ->assertViewHas('entries', function ($entries) use ($gelPayment, $mixedPayment): bool {
            $paymentEntries = $entries->where('category', 'პაციენტის გადახდა');

            return $paymentEntries->count() === 2
                && $paymentEntries->pluck('key')->sort()->values()->all() === collect([
                    'payment-'.$gelPayment->getKey(), 'payment-'.$mixedPayment->getKey(),
                ])->sort()->values()->all()
                && round((float) $paymentEntries->sum('amount'), 2) === 400.0;
        })
        ->assertSee('300.00 ₾ + $152.95')
        ->set('currency', 'USD')
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['GEL']['income'] === 400.0
            && $totals['USD']['income'] === 198.83)
        ->assertViewHas('entries', function ($entries) use ($usdPayment, $mixedPayment): bool {
            $paymentEntries = $entries->where('category', 'პაციენტის გადახდა');

            return $paymentEntries->count() === 2
                && $paymentEntries->pluck('key')->unique()->count() === 2
                && $paymentEntries->pluck('key')->sort()->values()->all() === collect([
                    'payment-'.$usdPayment->getKey(), 'payment-'.$mixedPayment->getKey(),
                ])->sort()->values()->all()
                && round((float) $paymentEntries->sum('amount'), 2) === 198.83;
        });

    expect((float) CashboxTransaction::query()->where('type', 'patient_payment')->where('currency', 'GEL')->sum('amount'))->toBe(400.0)
        ->and(round((float) CashboxTransaction::query()->where('type', 'patient_payment')->where('currency', 'USD')->sum('amount'), 2))->toBe(198.83)
        ->and($page->get('historyMode'))->toBe('payments');
});

test('finance expenses affect current cashier only when explicitly sourced from it', function (string $method, ?string $source, float $cashExpected) {
    $manager = app(FinanceManager::class);
    $expense = $manager->create([
        'type' => 'expense', 'transaction_date' => now(), 'category' => 'materials',
        'description' => 'Supplier', 'amount' => 200, 'currency' => 'GEL',
        'payment_method' => $method, 'cash_source' => $source,
    ]);

    expect(FinanceTransaction::query()->whereKey($expense)->sum('amount'))->toEqual(200)
        ->and((float) CashboxTransaction::query()->where('finance_transaction_id', $expense->getKey())->sum('amount'))->toBe($cashExpected)
        ->and(app(CashboxManager::class)->today()->summary()['cashExpenses'])->toBe($cashExpected);
})->with([
    'current cashier' => ['cash', 'current_cashier', 200],
    'previously withdrawn cash' => ['cash', 'withdrawn_cash', 0],
    'card' => ['card', null, 0],
    'bank transfer' => ['bank_transfer', null, 0],
]);

test('linked finance expense updates and deletes without duplicate or orphan cashier movements', function () {
    $manager = app(FinanceManager::class);
    $expense = $manager->create([
        'type' => 'expense', 'transaction_date' => now(), 'category' => 'office',
        'amount' => 300, 'currency' => 'GEL', 'payment_method' => 'cash',
        'cash_source' => 'current_cashier',
    ]);
    $manager->update($expense, ['amount' => 250]);

    expect(CashboxTransaction::query()->where('finance_transaction_id', $expense->getKey())->count())->toBe(1)
        ->and((float) $expense->refresh()->cashboxTransaction->amount)->toBe(250.0);

    $manager->delete($expense);

    expect(FinanceTransaction::query()->whereKey($expense)->exists())->toBeFalse()
        ->and(CashboxTransaction::query()->where('finance_transaction_id', $expense->getKey())->exists())->toBeFalse();
});

test('main finance creates one israeli cash expense shared by both finance views', function () {
    $owner = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->actingAs($owner);
    $patient = Patient::create([
        'first_name' => 'Shared', 'last_name' => 'Expense',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 100, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now(),
    ]);

    $expense = app(FinanceManager::class)->create([
        'type' => 'expense', 'transaction_date' => now(), 'category' => 'rent',
        'description' => 'Israeli rent', 'amount' => 40, 'currency' => 'USD',
        'payment_method' => 'cash', 'cash_source' => 'israeli',
    ]);

    expect($expense)->toBeInstanceOf(PartnerFinanceTransaction::class)
        ->and(PartnerFinanceTransaction::query()->where('type', 'expense')->count())->toBe(1)
        ->and(FinanceTransaction::query()->count())->toBe(0)
        ->and(CashboxTransaction::query()->count())->toBe(0)
        ->and(app(FinanceUsdUsageService::class)->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI)['USD'])->toBe(60.0);

    Livewire::test(Finance::class)
        ->set('source', 'partner')
        ->call('showHistory', 'expenses')
        ->set('currency', 'USD')
        ->assertSee('Israeli rent')
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['USD']['expense'] === 40.0);

    Livewire::test(ListPartnerFinance::class)
        ->assertCanSeeTableRecords(PartnerFinanceEntry::query()->where('transaction_type', 'expense')->get())
        ->assertSee('Israeli rent');
});

test('day closing carryover changes physical allocation without creating an expense or withdrawal', function () {
    $cashbox = app(CashboxManager::class);
    $day = $cashbox->today();
    $visit = financeVisit();
    Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 3000, 'currency' => 'GEL', 'payment_date' => today(),
    ], [['payment_method' => 'cash', 'amount' => 3000]]);

    $cashbox->close($day, 3000, 300);
    $next = $cashbox->dayFor(today()->addDay()->toDateString());

    expect((float) $day->fresh()->cash_withdrawal_total)->toBe(0.0)
        ->and($day->transactions()->where('type', 'cash_withdrawal')->count())->toBe(0)
        ->and((float) $next->opening_balance)->toBe(300.0)
        ->and(FinanceTransaction::query()->where('type', 'expense')->sum('amount'))->toEqual(0);
});

test('visit direct expense remains separate from general finance expense', function () {
    $visit = financeVisit();
    $service = TreatmentCase::create(['name' => 'Clinical work', 'category' => 'orthopedics', 'is_active' => true]);
    $item = $visit->treatmentCaseItems()->create([
        'treatment_case_id' => $service->getKey(), 'quantity' => 1, 'unit_price' => 500,
    ]);
    $direct = $item->directExpenses()->create(['name' => 'Lab', 'amount' => 100, 'currency' => 'GEL']);

    expect($direct)->toBeInstanceOf(DirectExpense::class)
        ->and(FinanceTransaction::query()->count())->toBe(0);
});

test('finance page derives patient income and manual expenses without duplicate income rows', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $visit = financeVisit();
    Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 500, 'currency' => 'GEL', 'payment_date' => today()->toDateString(),
    ], [['payment_method' => 'card', 'amount' => 500]]);
    app(FinanceManager::class)->create([
        'type' => 'expense', 'transaction_date' => now(), 'category' => 'rent',
        'amount' => 200, 'currency' => 'GEL', 'payment_method' => 'bank_transfer',
    ]);

    $page = Livewire::test(Finance::class)->assertSuccessful();

    expect($page->get('dateFrom'))->toBe(today()->subMonth()->addDay()->toDateString())
        ->and($page->get('dateUntil'))->toBe(today()->toDateString())
        ->and($page->get('period'))->toBe('1_month')
        ->and($page->get('currency'))->toBe('GEL')
        ->and((float) Payment::query()->whereDate('payment_date', $page->get('dateUntil'))->sum('amount'))->toBe(500.0);

    $page
        ->assertViewHas('income', 500.0)
        ->assertViewHas('expense', 200.0)
        ->assertViewHas('result', 300.0)
        ->assertViewHas('entries', fn ($entries): bool => $entries->isEmpty())
        ->call('showHistory', 'payments')
        ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 1
            && $entries->pluck('key')->unique()->count() === 1
            && $entries->where('key', 'payment-'.Payment::query()->sole()->getKey())->count() === 1)
        ->assertSee($visit->patient->full_name)
        ->call('showHistory', 'expenses')
        ->assertSee('ქირა');

    expect(FinanceTransaction::query()->where('type', 'income')->count())->toBe(0);
});

test('finance overview presets update dates and manual dates switch to custom', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));

    Livewire::test(Finance::class)
        ->set('period', '7_days')
        ->assertSet('dateFrom', today()->subDays(6)->toDateString())
        ->assertSet('dateUntil', today()->toDateString())
        ->set('period', '3_months')
        ->assertSet('dateFrom', today()->subMonths(3)->addDay()->toDateString())
        ->set('period', '1_year')
        ->assertSet('dateFrom', today()->subYear()->addDay()->toDateString())
        ->set('dateFrom', today()->subDays(10)->toDateString())
        ->assertSet('period', 'custom')
        ->set('dateUntil', today()->subDay()->toDateString())
        ->assertSet('period', 'custom');
});

test('finance history buttons toggle and keep only one section active', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));

    Livewire::test(Finance::class)
        ->assertSet('historyMode', 'overview')
        ->call('showHistory', 'payments')
        ->assertSet('historyMode', 'payments')
        ->assertSeeHtml('bg-primary-600 text-white')
        ->call('showHistory', 'payments')
        ->assertSet('historyMode', 'overview')
        ->call('showHistory', 'expenses')
        ->assertSet('historyMode', 'expenses')
        ->call('showHistory', 'cash_flow')
        ->assertSet('historyMode', 'cash_flow')
        ->call('showHistory', 'cash_flow')
        ->assertSet('historyMode', 'overview')
        ->call('showHistory', 'cash_flow')
        ->assertSet('historyMode', 'cash_flow')
        ->call('showHistory', 'payments')
        ->assertSet('historyMode', 'payments')
        ->call('showHistory', 'expenses')
        ->assertSet('historyMode', 'expenses')
        ->call('showHistory', 'expenses')
        ->assertSet('historyMode', 'overview');
});

test('finance overview source filter combines and separates clinic and partner totals', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $clinicVisit = financeVisit();
    Payment::createWithSplits([
        'visit_id' => $clinicVisit->getKey(), 'amount' => 500, 'currency' => 'GEL',
        'payment_date' => today(),
    ], [['payment_method' => 'card', 'amount' => 500]]);
    app(FinanceManager::class)->create([
        'type' => 'expense', 'transaction_date' => now(), 'category' => 'rent',
        'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'bank_transfer',
    ]);

    $partnerPatient = Patient::create([
        'first_name' => 'Partner', 'last_name' => 'Report',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $partnerPatient->partnerPayments()->create([
        'amount' => 300, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now(),
    ]);
    PartnerFinanceTransaction::create([
        'type' => PartnerFinanceTransaction::TYPE_EXPENSE, 'transacted_at' => now(),
        'category' => 'other', 'from_account' => 'cash', 'amount' => 50, 'currency' => 'USD',
    ]);

    $page = Livewire::test(Finance::class)
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['GEL'] === [
            'income' => 500.0, 'expense' => 100.0, 'result' => 400.0,
        ] && $totals['USD'] === [
            'income' => 300.0, 'expense' => 50.0, 'result' => 250.0,
        ]);

    expect(substr_count($page->html(), 'კლინიკა:'))->toBe(4)
        ->and(substr_count($page->html(), 'ისრაელი:'))->toBe(4);

    $page->set('source', 'clinic')
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['GEL']['income'] === 500.0
            && $totals['USD']['income'] === 0.0)
        ->set('source', 'partner')
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['USD'] === [
            'income' => 300.0, 'expense' => 50.0, 'result' => 250.0,
        ] && $totals['GEL']['income'] === 0.0)
        ->call('showHistory', 'payments')
        ->set('currency', 'USD')
        ->assertSee($partnerPatient->full_name)
        ->assertDontSee($clinicVisit->patient->full_name);
});

test('israeli usd exchange and employee expenses remain traceable without changing income', function () {
    $user = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->actingAs($user);
    $patient = Patient::create([
        'first_name' => 'USD', 'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 10000, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now(),
    ]);

    app(FinanceUsdUsageService::class)->record([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'usage_type' => 'exchange_and_spend', 'usd_amount' => 5000,
        'exchange_rate' => 2.7, 'received_gel_amount' => 13500, 'transacted_at' => now(),
        'notes' => 'Partial exchange',
        'expenses' => [
            ['category' => 'salary', 'recipient' => 'Employee A', 'amount' => 4000],
            ['category' => 'salary', 'recipient' => 'Employee B', 'amount' => 3500],
            ['category' => 'salary', 'recipient' => 'Employee C', 'amount' => 2000],
        ],
    ]);

    expect(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)->count())->toBe(1)
        ->and(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)->count())->toBe(3)
        ->and(PartnerFinanceTransaction::query()->where('created_by', $user->id)->count())->toBe(4)
        ->and(PartnerFinanceTransaction::query()->where('recipient', 'Employee A')->value('amount'))->toEqual(4000)
        ->and(app(FinanceUsdUsageService::class)->balances(PartnerFinanceTransaction::SOURCE_ISRAELI))->toBe([
            'GEL' => 4000.0, 'USD' => 5000.0,
        ]);

    Livewire::test(Finance::class)
        ->assertActionExists(TestAction::make('usdUsage'))
        ->set('source', 'partner')
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['USD']['income'] === 10000.0
            && $totals['GEL']['expense'] === 9500.0)
        ->assertViewHas('availableBalances', ['GEL' => 4000.0, 'USD' => 5000.0])
        ->call('showHistory', 'expenses')
        ->assertSee('Employee A')
        ->call('showHistory', 'cash_flow')
        ->assertSee('$5,000.00 → 13,500.00 ₾');
});

test('finance history search distinguishes usd exchange from its related expense rows', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create([
        'first_name' => 'Search', 'last_name' => 'Funds',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 1500, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now(),
    ]);

    app(FinanceUsdUsageService::class)->record([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'usage_type' => 'exchange_and_spend', 'usd_amount' => 1500,
        'exchange_rate' => 2.61, 'received_gel_amount' => 3915, 'transacted_at' => now(),
        'notes' => 'სავალუტო ოპერაცია',
        'expenses' => [
            ['category' => 'lab_salary', 'recipient' => 'გიორგი', 'amount' => 2000],
            ['category' => 'lab_salary', 'recipient' => 'ნიკა', 'amount' => 1000],
        ],
    ]);

    $exchangeId = PartnerFinanceTransaction::query()
        ->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)->value('id');
    $giorgiExpenseId = PartnerFinanceTransaction::query()
        ->where('recipient', 'გიორგი')->value('id');

    Livewire::test(Finance::class)
        ->call('showHistory', 'expenses')
        ->set('source', 'partner')
        ->set('currency', 'GEL')
        ->set('search', 'გიორგი')
        ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 2
            && $entries->first()['is_group_parent'] === true
            && $entries->last()['key'] === 'partner-expense-'.$giorgiExpenseId
            && $entries->last()['is_group_child'] === true)
        ->set('search', 'ხელფასი')
        ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 3
            && $entries->first()['is_group_parent'] === true
            && $entries->where('is_group_child', true)->count() === 2)
        ->call('showHistory', 'cash_flow')
        ->set('search', 'ვალუტის გაცვლა')
        ->assertViewHas('entries', fn ($entries): bool => $entries->pluck('key')->all() === [
            'movement-'.$exchangeId,
        ]);
});

test('cash flow history separates movements from expenses and exposes searchable transfers', function () {
    $user = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->actingAs($user);
    $patient = Patient::create([
        'first_name' => 'Grouped', 'last_name' => 'Finance',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 5000, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now(),
    ]);
    $transactedAt = now()->startOfSecond();
    $service = app(FinanceUsdUsageService::class);
    $service->record([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'usage_type' => 'exchange_and_spend', 'usd_amount' => 1500,
        'exchange_rate' => 2.61, 'received_gel_amount' => 3915, 'transacted_at' => $transactedAt,
        'expenses' => [
            ['category' => 'lab_salary', 'recipient' => 'Alex', 'amount' => 2000],
            ['category' => 'lab_salary', 'recipient' => 'Ilia', 'amount' => 1915],
        ],
    ]);
    $transfer = $service->transfer([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'currency' => 'USD', 'amount' => 500, 'category' => 'bank_deposit',
        'transacted_at' => $transactedAt->copy()->addMinute(), 'notes' => 'Deposit USD',
    ]);

    $page = Livewire::test(Finance::class)
        ->call('showHistory', 'expenses')
        ->set('source', 'partner')
        ->set('currency', 'GEL')
        ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 3
            && $entries->first()['is_group_parent'] === true
            && $entries->where('is_group_child', true)->count() === 2
            && $entries->where('is_group_child', true)->every(fn (array $entry): bool => $entry['type'] === 'expense'
                && $entry['category'] === 'ხელფასი'))
        ->call('showHistory', 'cash_flow')
        ->set('cashFlowCurrency', 'GEL')
        ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 1
            && $entries->first()['movement_kind'] === 'exchange')
        ->set('cashFlowCurrency', 'USD')
        ->set('search', 'ტრანსფერი')
        ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 1
            && $entries->first()['key'] === 'movement-'.$transfer->getKey()
            && $entries->first()['category'] === 'ტრანსფერი'
            && $entries->first()['source_secondary'] === 'ანგარიშზე შეტანა')
        ->set('search', 'ანგარიშზე შეტანა')
        ->assertSee('Deposit USD');

    expect($service->balances(PartnerFinanceTransaction::SOURCE_ISRAELI))->toBe(['GEL' => 0.0, 'USD' => 3000.0])
        ->and(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)->sum('amount'))->toEqual(3915)
        ->and($page->get('historyMode'))->toBe('cash_flow');
});

test('cash flow lists clinic and israeli gel usd transfers including israeli usd cash to bank', function () {
    $user = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->actingAs($user);
    foreach (['GEL', 'USD'] as $currency) {
        app(FinanceManager::class)->create([
            'type' => 'income', 'transaction_date' => now(), 'category' => 'other_income',
            'amount' => 1000, 'currency' => $currency, 'payment_method' => 'cash',
            'cash_source' => 'current_cashier',
        ]);
    }
    $patient = Patient::create([
        'first_name' => 'Transfer', 'last_name' => 'Patient',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    foreach (['GEL', 'USD'] as $currency) {
        $patient->partnerPayments()->create([
            'amount' => 1000, 'currency' => $currency, 'payment_method' => 'cash', 'paid_at' => now(),
        ]);
    }

    $service = app(FinanceUsdUsageService::class);
    foreach ([
        [PartnerFinanceTransaction::SOURCE_CLINIC, 'GEL', 100],
        [PartnerFinanceTransaction::SOURCE_CLINIC, 'USD', 150],
        [PartnerFinanceTransaction::SOURCE_ISRAELI, 'GEL', 200],
        [PartnerFinanceTransaction::SOURCE_ISRAELI, 'USD', 500],
    ] as [$source, $currency, $amount]) {
        $service->transfer([
            'source' => $source, 'currency' => $currency, 'amount' => $amount,
            'category' => 'bank_deposit', 'transacted_at' => now(),
            'notes' => $source.' '.$currency.' transfer',
        ]);
    }

    Livewire::test(Finance::class)
        ->call('showHistory', 'cash_flow')
        ->assertSet('cashFlowCurrency', '')
        ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 4
            && $entries->every(fn (array $entry): bool => $entry['movement_kind'] === 'transfer'))
        ->set('source', 'partner')
        ->set('cashFlowCurrency', 'USD')
        ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 1
            && $entries->first()['source'] === 'partner'
            && $entries->first()['from_display'] === 'ნაღდი · USD'
            && $entries->first()['to_display'] === 'ბანკი · USD'
            && $entries->first()['display_amount'] === '$500.00')
        ->set('search', 'ანგარიშზე შეტანა')
        ->assertSee('israeli USD transfer')
        ->set('search', 'ბანკი')
        ->assertSee('$500.00');

    expect(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_TRANSFER)->count())->toBe(4)
        ->and(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)->count())->toBe(0)
        ->and($service->balances(PartnerFinanceTransaction::SOURCE_ISRAELI))->toBe(['GEL' => 800.0, 'USD' => 500.0]);
});

test('exchange only moves clinic currencies without creating income or expense', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    app(FinanceManager::class)->create([
        'type' => 'income', 'transaction_date' => now(), 'category' => 'other_income',
        'amount' => 1000, 'currency' => 'USD', 'payment_method' => 'cash',
        'cash_source' => 'current_cashier',
    ]);

    app(FinanceUsdUsageService::class)->record([
        'source' => PartnerFinanceTransaction::SOURCE_CLINIC,
        'usage_type' => 'exchange_only', 'usd_amount' => 500,
        'exchange_rate' => 2.7, 'received_gel_amount' => 1350, 'transacted_at' => now(),
    ]);

    expect(FinanceTransaction::query()->where('type', 'expense')->count())->toBe(0)
        ->and(app(FinanceUsdUsageService::class)->balances(PartnerFinanceTransaction::SOURCE_CLINIC))->toBe([
            'GEL' => 1350.0, 'USD' => 500.0,
        ]);

    Livewire::test(Finance::class)->set('source', 'clinic')
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['USD']['income'] === 1000.0
            && $totals['GEL']['income'] === 0.0
            && $totals['GEL']['expense'] === 0.0);
});

test('direct israeli usd expense reduces usd and remains a real recipient expense', function () {
    $user = User::factory()->create(['role' => User::ROLE_OWNER]);
    $this->actingAs($user);
    $patient = Patient::create([
        'first_name' => 'Direct', 'last_name' => 'USD',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 1000, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now(),
    ]);

    app(FinanceUsdUsageService::class)->record([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'usage_type' => 'direct_usd_expense', 'usd_amount' => 250,
        'expense_category' => 'laboratory', 'recipient' => 'USD Supplier',
        'transacted_at' => now(), 'notes' => 'Direct supplier payment',
    ]);

    $expense = PartnerFinanceTransaction::query()->sole();
    expect($expense->type)->toBe(PartnerFinanceTransaction::TYPE_EXPENSE)
        ->and($expense->currency)->toBe('USD')
        ->and($expense->recipient)->toBe('USD Supplier')
        ->and($expense->created_by)->toBe($user->id)
        ->and(app(FinanceUsdUsageService::class)->balances(PartnerFinanceTransaction::SOURCE_ISRAELI))->toBe([
            'GEL' => 0.0, 'USD' => 750.0,
        ]);
});

test('usd usage calculates booth exchange while usd and gel transfers stay movements', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create([
        'first_name' => 'Booth', 'last_name' => 'Exchange',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 2500, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now(),
    ]);

    Livewire::test(Finance::class)
        ->mountAction(TestAction::make('usdUsage'))
        ->setActionData([
            'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
            'usage_type' => 'exchange_only',
            'usd_amount' => 2000,
            'exchange_rate' => 2.61,
        ])
        ->assertActionDataSet(['received_gel_amount' => 5220.0])
        ->setActionData([
            'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
            'usage_type' => 'exchange_only',
            'usd_amount' => 2000,
            'exchange_rate' => 2.61,
            'received_gel_amount' => 5220,
            'transacted_at' => now(),
        ])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    $service = app(FinanceUsdUsageService::class);
    $service->transfer([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'currency' => 'USD', 'amount' => 300, 'category' => 'bank_deposit',
        'transacted_at' => now(), 'notes' => 'USD to bank',
    ]);
    $service->transfer([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'currency' => 'GEL', 'amount' => 500, 'category' => 'other_transfer',
        'transacted_at' => now(), 'notes' => 'GEL transfer',
    ]);

    expect(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_EXCHANGE)->count())->toBe(1)
        ->and(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_TRANSFER)->count())->toBe(2)
        ->and(PartnerFinanceTransaction::query()->where('type', PartnerFinanceTransaction::TYPE_EXPENSE)->count())->toBe(0)
        ->and($service->balances(PartnerFinanceTransaction::SOURCE_ISRAELI))->toBe([
            'GEL' => 4720.0, 'USD' => 200.0,
        ]);

    Livewire::test(Finance::class)
        ->assertActionExists(TestAction::make('financeTransfer'))
        ->set('source', 'partner')
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['GEL']['expense'] === 0.0
            && $totals['USD']['expense'] === 0.0)
        ->assertViewHas('availableBalances', ['GEL' => 4720.0, 'USD' => 200.0]);
});

test('finance current balance is all time while income and expense remain period based', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create([
        'first_name' => 'Historical', 'last_name' => 'Balance',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 1000, 'currency' => 'USD', 'payment_method' => 'cash',
        'paid_at' => today()->subMonths(2),
    ]);

    Livewire::test(Finance::class)
        ->set('source', 'partner')
        ->assertViewHas('availableBalances', ['GEL' => 0.0, 'USD' => 1000.0])
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['GEL']['income'] === 0.0
            && $totals['USD']['income'] === 0.0
            && $totals['GEL']['expense'] === 0.0
            && $totals['USD']['expense'] === 0.0)
        ->assertSee('მიმდინარე ქეში')
        ->assertSee('შემოსავალი')
        ->assertSee('ხარჯი')
        ->assertDontSee('შედეგი');
});

test('finance current cash excludes card and bank amounts while preserving income reporting', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $visit = financeVisit(2000);
    Payment::createWithSplits([
        'visit_id' => $visit->getKey(), 'amount' => 1000, 'currency' => 'GEL', 'payment_date' => today(),
    ], [
        ['payment_method' => 'cash', 'amount' => 300, 'currency' => 'GEL'],
        ['payment_method' => 'card', 'amount' => 700, 'currency' => 'GEL'],
    ]);
    app(FinanceManager::class)->create([
        'type' => 'expense', 'transaction_date' => now(), 'category' => 'other',
        'amount' => 50, 'currency' => 'GEL', 'payment_method' => 'cash',
        'cash_source' => 'current_cashier',
    ]);
    $partner = Patient::create([
        'first_name' => 'Cash', 'last_name' => 'Only',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $partner->partnerPayments()->create([
        'amount' => 500, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now(),
    ]);
    $partner->partnerPayments()->create([
        'amount' => 400, 'currency' => 'USD', 'payment_method' => 'card', 'paid_at' => now(),
    ]);
    app(FinanceUsdUsageService::class)->transfer([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'currency' => 'USD', 'amount' => 100, 'category' => 'bank_deposit',
        'transacted_at' => now(),
    ]);

    Livewire::test(Finance::class)
        ->assertSee('მიმდინარე ქეში')
        ->assertViewHas('balancesBySource', fn (array $balances): bool => $balances['clinic'] === ['GEL' => 250.0, 'USD' => 0.0]
            && $balances['partner'] === ['GEL' => 0.0, 'USD' => 400.0])
        ->assertViewHas('availableBalances', ['GEL' => 250.0, 'USD' => 400.0])
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['GEL']['income'] === 1000.0
            && $totals['USD']['income'] === 900.0
            && $totals['GEL']['expense'] === 50.0);
});

test('card and bank income cannot fund physical cash transfers or exchanges', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $partner = Patient::create([
        'first_name' => 'Noncash', 'last_name' => 'Income',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    foreach (['card', 'bank_transfer'] as $method) {
        $partner->partnerPayments()->create([
            'amount' => 500, 'currency' => 'USD', 'payment_method' => $method, 'paid_at' => now(),
        ]);
    }
    $service = app(FinanceUsdUsageService::class);

    expect($service->cashBalances(PartnerFinanceTransaction::SOURCE_ISRAELI))->toBe(['GEL' => 0.0, 'USD' => 0.0]);

    try {
        $service->transfer([
            'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
            'currency' => 'USD', 'amount' => 100, 'category' => 'bank_deposit',
            'transacted_at' => now(),
        ]);
        $this->fail('A non-cash payment funded a physical cash transfer.');
    } catch (ValidationException) {
        expect(PartnerFinanceTransaction::query()->count())->toBe(0);
    }

    try {
        $service->record([
            'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
            'usage_type' => 'exchange_only', 'usd_amount' => 100,
            'exchange_rate' => 2.7, 'received_gel_amount' => 270,
            'transacted_at' => now(),
        ]);
        $this->fail('A non-cash payment funded a physical currency exchange.');
    } catch (ValidationException) {
        expect(PartnerFinanceTransaction::query()->count())->toBe(0);
    }

    Livewire::test(Finance::class)
        ->set('source', 'partner')
        ->assertViewHas('availableBalances', ['GEL' => 0.0, 'USD' => 0.0])
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['USD']['income'] === 1000.0)
        ->call('showHistory', 'payments')
        ->set('currency', 'USD')
        ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 2
            && $entries->pluck('methods')->flatten()->sort()->values()->all() === ['bank_transfer', 'card']);
});

test('finance overview separates real income expenses current cash and all cash out', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $patient = Patient::create([
        'first_name' => 'Linked', 'last_name' => 'Exchange',
        'patient_group_id' => PatientGroup::israelPartnerId(),
    ]);
    $patient->partnerPayments()->create([
        'amount' => 2000, 'currency' => 'USD', 'payment_method' => 'cash', 'paid_at' => now(),
    ]);
    $operationTime = now()->startOfSecond();
    $service = app(FinanceUsdUsageService::class);
    $service->recordIsraeliOperation([
        'transacted_at' => $operationTime,
        'operation_type' => 'lab_salary',
        'payment_mode' => 'exchange_usd_gel',
        'usd_amount' => 1500,
        'exchange_rate' => 2.61,
        'actual_amount' => 2000,
    ]);
    PartnerFinanceTransaction::create([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
        'transacted_at' => $operationTime,
        'category' => 'lab_salary', 'from_account' => 'cash',
        'recipient' => 'Ilia', 'amount' => 1500, 'currency' => 'GEL',
        'created_by' => auth()->id(),
    ]);
    $service->transfer([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'currency' => 'GEL', 'amount' => 100, 'category' => 'bank_deposit',
        'transacted_at' => $operationTime->copy()->addMinute(),
    ]);
    $service->recordIsraeliOperation([
        'transacted_at' => $operationTime->copy()->addMinutes(2),
        'operation_type' => 'owner_withdrawal',
        'payment_mode' => 'direct_usd',
        'actual_amount' => 100,
    ]);
    PartnerFinanceTransaction::create([
        'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
        'type' => PartnerFinanceTransaction::TYPE_EXPENSE,
        'transacted_at' => $operationTime->copy()->addMinutes(3),
        'category' => 'other_expense', 'from_account' => 'cash',
        'amount' => 50, 'currency' => 'GEL',
    ]);

    Livewire::test(Finance::class)
        ->set('source', 'partner')
        ->assertViewHas('availableBalances', ['GEL' => 265.0, 'USD' => 400.0])
        ->assertViewHas('totalsByCurrency', fn (array $totals): bool => $totals['USD']['income'] === 2000.0
            && $totals['USD']['expense'] === 0.0
            && $totals['GEL']['expense'] === 3550.0)
        ->assertViewHas('cashOutByCurrency', ['GEL' => 3650.0, 'USD' => 1600.0])
        ->assertDontSee('$1,500.00 → 3,915.00 ₾')
        ->set('historyMode', 'expenses')
        ->assertViewHas('entries', function ($entries): bool {
            $entries = collect($entries);
            $parent = $entries->firstWhere('is_group_parent', true);

            return $parent !== null
                && $parent['group_remaining'] === 415.0
                && $entries->where('is_group_child', true)->count() === 2
                && $entries->contains(fn (array $entry): bool => ! ($entry['is_group_child'] ?? false)
                    && ($entry['amount'] ?? null) === 50.0);
        })
        ->assertSee('დარჩენილი: 415.00 ₾');
});

test('finance keeps detailed money movement history without a separate summary block', function () {
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));

    foreach ([0, 1, 2, 3] as $daysAgo) {
        PartnerFinanceTransaction::create([
            'source' => PartnerFinanceTransaction::SOURCE_ISRAELI,
            'type' => PartnerFinanceTransaction::TYPE_TRANSFER,
            'transacted_at' => now()->subDays($daysAgo),
            'category' => 'bank_deposit',
            'from_account' => 'cash', 'to_account' => 'bank',
            'amount' => 100 + $daysAgo, 'currency' => 'GEL',
        ]);
    }

    Livewire::test(Finance::class)
        ->set('source', 'partner')
        ->assertViewHas('cashOutByCurrency', ['GEL' => 406.0, 'USD' => 0.0])
        ->call('showHistory', 'cash_flow')
        ->assertViewHas('entries', fn ($entries): bool => $entries->count() === 4
            && $entries->every(fn (array $entry): bool => $entry['movement_kind'] === 'transfer'));
});
