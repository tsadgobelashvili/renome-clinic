<?php

use App\Filament\Pages\Finance;
use App\Models\Patient;
use App\Models\User;
use App\Services\BogBusinessApiService;
use App\Services\Finance\CashOutflowReport;
use App\Support\CashboxManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\DatabaseSafety;

beforeEach(function () {
    DatabaseSafety::assertInMemory(DB::connection()->getConfig());
    Http::preventStrayRequests();
    $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    $this->travelTo('2026-09-14 12:00:00');
    $this->day = DB::table('cashbox_days')->insertGetId(['date' => '2026-09-14']);
    $this->report = app(CashOutflowReport::class);
});

function cashDetailFinance(array $attributes = []): int
{
    return DB::table('finance_transactions')->insertGetId(array_replace([
        'type' => 'expense', 'transaction_date' => '2026-09-14 10:00:00', 'category' => 'other',
        'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'withdrawn_cash',
        'description' => 'Held cash expense',
    ], $attributes));
}

function cashDetailPartner(array $attributes = []): int
{
    return DB::table('partner_finance_transactions')->insertGetId(array_replace([
        'source' => 'clinic', 'type' => 'transfer', 'transacted_at' => '2026-09-14 10:00:00',
        'from_account' => 'cash', 'to_account' => 'bank', 'amount' => 50, 'currency' => 'GEL', 'notes' => 'Deposit from held cash',
    ], $attributes));
}

function cashDetailDrawer(int $day, array $attributes = []): int
{
    return DB::table('cashbox_transactions')->insertGetId(array_replace([
        'cashbox_day_id' => $day, 'type' => 'other_income', 'transaction_date' => '2026-09-14 10:00:00',
        'amount' => 200, 'currency' => 'GEL', 'payment_method' => 'cash', 'description' => 'Drawer receipt',
    ], $attributes));
}

test('cash details include accumulated expenses deposits withdrawals advances and salaries with one query', function () {
    cashDetailFinance();
    cashDetailFinance(['category' => 'salary', 'amount' => 300, 'description' => 'Held salary']);
    cashDetailPartner();
    cashDetailPartner(['type' => 'owner_withdrawal', 'to_account' => null, 'amount' => 25]);
    cashDetailPartner(['type' => 'employee_advance', 'to_account' => null, 'amount' => 40]);
    cashDetailDrawer($this->day, ['type' => 'expense', 'amount' => 10]);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $rows = $this->report->movements('2026-09-14', '2026-09-14', 'all', 'outflow')->get();
    expect(DB::getQueryLog())->toHaveCount(1);
    DB::disableQueryLog();
    expect($rows)->toHaveCount(6)
        ->and($rows->sum('amount'))->toEqual(525)
        ->and($rows->where('cash_source', 'accumulated_cash'))->toHaveCount(5)
        ->and($rows->firstWhere('group_key', 'bank_deposit')->origin)->toBe('transfer')
        ->and($rows->firstWhere('origin', 'salary_cash')->description)->toBe('Held salary');
    app()->setLocale('ka');
    $html = view('filament.pages.finance-cash-movement-entries', ['details' => $rows])->render();
    expect($html)->toContain('დაგროვილი ნაღდი', 'მიმდინარე სალარო', 'ბანკში შეტანა', 'Deposit from held cash', 'Held cash expense', '14.09.2026', 'GEL');
});

test('cash direction date source and native currency filters include receipts and reversals', function () {
    cashDetailDrawer($this->day);
    cashDetailDrawer($this->day, ['payment_method' => 'card', 'amount' => 999]);
    cashDetailFinance();
    cashDetailFinance(['type' => 'income', 'amount' => 30, 'description' => 'Held cash reversal']);
    cashDetailFinance(['transaction_date' => '2026-09-13 23:59:59']);
    cashDetailFinance(['transaction_date' => '2026-09-15 00:00:00']);
    cashDetailPartner(['source' => 'israeli', 'currency' => 'USD', 'amount' => 70]);
    cashDetailPartner(['source' => 'israeli', 'type' => 'salary_cash', 'from_account' => null, 'to_account' => 'cash', 'amount' => 15]);
    $patient = Patient::create(['first_name' => 'Cash', 'last_name' => 'Receipt']);
    foreach ([null, now()] as $deleted) {
        DB::table('partner_patient_payments')->insert(['patient_id' => $patient->id, 'amount' => 90, 'currency' => 'USD',
            'payment_method' => 'cash', 'paid_at' => '2026-09-14 23:59:59', 'notes' => 'Israeli receipt', 'deleted_at' => $deleted]);
    }
    expect($this->report->movements('2026-09-14', '2026-09-14')->get())->toHaveCount(6)
        ->and($this->report->movements('2026-09-14', '2026-09-14', 'clinic', 'inflow')->sum('amount'))->toEqual(230)
        ->and($this->report->movements('2026-09-14', '2026-09-14', 'clinic', 'outflow')->sum('amount'))->toEqual(100)
        ->and($this->report->movements('2026-09-14', '2026-09-14', 'israeli', 'outflow')->where('currency', 'USD')->sum('amount'))->toEqual(70)
        ->and($this->report->movements('2026-09-14', '2026-09-14', 'israeli', 'inflow')->where('currency', 'USD')->sole()->description)->toBe('Israeli receipt');
});

test('linked finance and partner mirrors are not repeated and mixed salary shares remain separate', function () {
    $drawerExpense = cashDetailFinance(['cash_source' => 'current_cashier']);
    cashDetailDrawer($this->day, ['type' => 'expense', 'amount' => 100, 'finance_transaction_id' => $drawerExpense]);
    $israeliExpense = cashDetailFinance(['cash_source' => 'israeli', 'amount' => 80]);
    cashDetailPartner(['source' => 'israeli', 'type' => 'expense', 'to_account' => null, 'amount' => 80, 'finance_transaction_id' => $israeliExpense]);
    $salary = cashDetailFinance(['category' => 'salary', 'amount' => 500, 'clinic_cash_gel' => 200, 'israeli_cash_gel' => 300]);
    cashDetailPartner(['source' => 'israeli', 'type' => 'salary_cash', 'to_account' => null, 'amount' => 300, 'finance_transaction_id' => $salary]);
    $rows = $this->report->movements('2026-09-14', '2026-09-14', 'all', 'outflow')->get();
    expect($rows)->toHaveCount(4)->and($rows->sum('amount'))->toEqual(680)
        ->and($rows->where('business_source', 'clinic')->sum('amount'))->toEqual(300)
        ->and($rows->where('business_source', 'israeli')->sum('amount'))->toEqual(380);
});

test('internal cash transfers appear once and do not inflate either direction', function () {
    $previous = DB::table('cashbox_days')->insertGetId(['date' => '2026-09-13', 'status' => 'closed']);
    $transfer = DB::table('cash_transfers')->insertGetId(['source_cashbox_day_id' => $previous, 'destination_cashbox_day_id' => $this->day,
        'amount' => 500, 'currency' => 'GEL', 'transferred_at' => now(), 'idempotency_key' => (string) Str::uuid()]);
    cashDetailDrawer($previous, ['type' => 'cash_transfer_out', 'cash_transfer_id' => $transfer, 'amount' => 500]);
    cashDetailDrawer($this->day, ['type' => 'cash_transfer_in', 'cash_transfer_id' => $transfer, 'amount' => 500]);
    cashDetailDrawer($this->day, ['type' => 'cash_withdrawal', 'amount' => 200, 'description' => CashboxManager::CLOSING_HANDOVER_DESCRIPTION]);
    cashDetailDrawer($this->day, ['type' => 'cash_withdrawal', 'amount' => 20, 'description' => 'Actual external withdrawal']);
    cashDetailDrawer($this->day, ['amount' => 100]);
    $rows = $this->report->movements('2026-09-14', '2026-09-14')->get();
    expect($rows)->toHaveCount(4)->and($rows->where('metric', 'internal_transfer'))->toHaveCount(2)
        ->and($rows->firstWhere('cash_source', 'accumulated_to_current')->metric)->toBe('internal_transfer')
        ->and($this->report->movements('2026-09-14', '2026-09-14', 'all', 'inflow')->sum('amount'))->toEqual(100)
        ->and($this->report->movements('2026-09-14', '2026-09-14', 'all', 'outflow')->sum('amount'))->toEqual(20);
});

test('cash exchange uses its existing native amounts without converting or removing its cash legs', function () {
    cashDetailPartner(['type' => 'currency_exchange', 'to_account' => 'cash', 'amount' => null, 'currency' => null,
        'from_amount' => 100, 'from_currency' => 'USD', 'to_amount' => 270, 'to_currency' => 'GEL', 'exchange_rate' => 2.7]);
    $rows = $this->report->movements('2026-09-14', '2026-09-14')->get();
    expect($rows)->toHaveCount(2)
        ->and((float) $rows->firstWhere('metric', 'outflow')->amount)->toBe(100.0)
        ->and($rows->firstWhere('metric', 'outflow')->currency)->toBe('USD')
        ->and((float) $rows->firstWhere('metric', 'inflow')->amount)->toBe(270.0)
        ->and($rows->firstWhere('metric', 'inflow')->currency)->toBe('GEL');
});

test('cash detail UI defaults to all and filters only the listing while retaining the finance cards', function () {
    app()->setLocale('ka');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
    $this->mock(BogBusinessApiService::class)->shouldReceive('currentBalance')->andReturn('9000.00');
    cashDetailDrawer($this->day);
    cashDetailFinance();
    $page = Livewire::test(Finance::class)->call('selectOverviewCard', 'cash')
        ->assertSet('cashDirection', 'all')->assertSee('ყველა')->assertSee('შემოსავალი')->assertSee('გასავალი')
        ->assertSee('Drawer receipt')->assertSee('Held cash expense');
    $figures = $page->viewData('figures');
    $page->set('cashDirection', 'outflow')->assertSee('Held cash expense')->assertDontSee('Drawer receipt')
        ->assertViewHas('figures', $figures)
        ->set('cashDirection', 'inflow')->assertSee('Drawer receipt')->assertDontSee('Held cash expense')
        ->assertViewHas('figures', $figures)
        ->call('selectOverviewCard', 'cash')->call('selectOverviewCard', 'cash')->assertSet('cashDirection', 'all');
});
