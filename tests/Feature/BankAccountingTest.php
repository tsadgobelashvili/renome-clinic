<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Bank;
use App\Filament\Pages\BankCategories;
use App\Filament\Pages\BankRules;
use App\Filament\Pages\ProfitLoss;
use App\Models\BankCategorizationRule;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\Doctor;
use App\Models\ExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\PartnerFinanceTransaction;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\User;
use App\Models\Visit;
use App\Services\Bank\BankClassificationService;
use App\Services\Bank\BankIngestionService;
use App\Services\Bank\BankReport;
use App\Services\Bank\ProfitLossReport;
use App\Services\ExpenseDimensions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-11 12:00:00'));
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER]));
});

function accountingBankRow(array $overrides = []): BankTransaction
{
    $dto = new BankTransactionData(array_replace(['transaction_date' => '2026-09-11 00:00:00', 'currency' => 'GEL',
        'amount' => '15.00', 'direction' => 'outflow', 'operation_type' => 'PMD', 'operation_id' => (string) Str::uuid()], $overrides));
    app(BankIngestionService::class)->ingest([$dto], 'import');

    return BankTransaction::where('deduplication_key', $dto->deduplicationKey())->firstOrFail();
}

function accountingTotals(string $source = 'all'): object
{
    return app(ProfitLossReport::class)->totals('2026-09-11', '2026-09-11', $source, 'GEL')->first() ?? (object) ['revenue' => 0, 'expenses' => 0, 'profit' => 0];
}

test('fee breakdown uses gross minus credited net and a separate transfer commission', function () {
    $card = accountingBankRow(['direction' => 'inflow', 'amount' => '362.97', 'operation_type' => 'TRN',
        'account_identifier' => 'GE00FEES', 'reference' => 'acquiring-370', 'description' => 'POS settlement; Gross amount: GEL 370.00']);
    $transfer = accountingBankRow(['operation_type' => 'COM', 'amount' => '1.50', 'account_identifier' => 'GE00FEES', 'description' => 'BOG to TBC commission']);
    accountingBankRow(['amount' => '200.00', 'account_identifier' => 'GE00OTHER', 'description' => 'Ordinary transfer']);
    expect($card->gross_amount)->toBe('370.00')->and($card->bank_fee)->toBe('0.00');
    $filters = ['dateFrom' => '2026-09-11', 'dateUntil' => '2026-09-11', 'account' => 'GE00FEES', 'currency' => 'GEL'];
    DB::enableQueryLog();
    $totals = app(BankReport::class)->totals($filters)->sole();
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queryCount)->toBe(1)->and((float) $totals->card_fees)->toBe(7.03)
        ->and((float) $totals->transfer_fees)->toBe(1.5)->and(round((float) $totals->fees, 2))->toBe(8.53)
        ->and(round($totals->card_fees + $totals->transfer_fees, 2))->toBe(8.53)
        ->and(round((float) $totals->expenses, 2))->toBe(8.53)->and((float) $totals->inflow)->toBe(362.97);
    expect(app(BankReport::class)->totals([...$filters, 'currency' => 'USD']))->toBeEmpty()
        ->and(app(BankReport::class)->totals([...$filters, 'dateUntil' => '2026-09-10']))->toBeEmpty()
        ->and((float) app(BankReport::class)->totals([...$filters, 'search' => 'TBC'])->sole()->fees)->toBe(1.5)
        ->and((float) app(BankReport::class)->totals([...$filters, 'expenseCategory' => $transfer->expense_category_id])->sole()->card_fees)->toBe(0.0);
    app()->setLocale('ka');
    Livewire::test(Bank::class)->assertSee('ბანკის საკომისიო')->assertSee('ბარათის საკომისიო')->assertSee('გადარიცხვის საკომისიო')
        ->assertSee('8.53')->assertSeeHtml('aria-controls="bank-fee-breakdown"')->assertSeeHtml('x-show="feesOpen"');
    expect(FinanceTransaction::count())->toBe(0);
});

test('matching standalone acquiring commission replaces embedded cost without changing its fee type', function () {
    accountingBankRow(['direction' => 'inflow', 'amount' => '362.97', 'gross_amount' => '370.00',
        'operation_type' => 'TRN', 'reference' => 'same-acquiring', 'account_identifier' => 'GE00MATCH', 'description' => 'POS settlement']);
    accountingBankRow(['operation_type' => 'COM', 'amount' => '7.03', 'reference' => 'same-acquiring', 'account_identifier' => 'GE00MATCH']);
    accountingBankRow(['operation_type' => 'COM', 'amount' => '1.50', 'reference' => 'separate-transfer']);
    $totals = app(BankReport::class)->totals([])->sole();
    expect(round((float) $totals->fees, 2))->toBe(8.53)->and((float) $totals->card_fees)->toBe(7.03)
        ->and((float) $totals->transfer_fees)->toBe(1.5)->and(round((float) accountingTotals()->expenses, 2))->toBe(8.53);
});

test('COM defaults to Bank fee and contributes its debit exactly once to fees and P&L', function () {
    $row = accountingBankRow(['operation_type' => 'COM', 'bank_fee' => '15.00']);
    expect($row->category->code)->toBe('bank_fee')->and($row->category->accounting_treatment)->toBe('expense')
        ->and($row->direction)->toBe('outflow')->and($row->classification_source)->toBe('default');
    expect((float) app(BankReport::class)->totals([])->sole()->fees)->toBe(15.0)
        ->and((float) accountingTotals()->expenses)->toBe(15.0)->and((float) accountingTotals()->profit)->toBe(-15.0);
});

test('uncategorized expense toggle includes only unassigned debits and composes with existing filters', function () {
    $missing = accountingBankRow(['description' => 'Unassigned supplier alpha', 'account_identifier' => 'GE00FILTER']);
    $missing->update(['bank_category_id' => null, 'expense_category_id' => null]);
    $uncategorized = accountingBankRow(['description' => 'Unassigned supplier beta']);
    $uncategorized->update(['bank_category_id' => BankCategory::where('code', 'uncategorized')->value('id'), 'expense_category_id' => null]);
    $shared = accountingBankRow(['description' => 'Shared category supplier']);
    $shared->update(['expense_category_id' => ExpenseCategory::where('reporting_code', 'materials')->value('id')]);
    $assigned = accountingBankRow(['operation_type' => 'COM', 'description' => 'Assigned bank commission']);
    $credit = accountingBankRow(['direction' => 'inflow', 'description' => 'Unassigned incoming payment']);
    $filters = ['uncategorizedExpenses' => true, 'dateFrom' => '2026-09-11', 'dateUntil' => '2026-09-11'];
    $report = app(BankReport::class);
    expect($report->query($filters)->pluck('id')->all())->toBe([$missing->id, $uncategorized->id])
        ->and($report->query([...$filters, 'currency' => 'USD'])->count())->toBe(0)
        ->and($report->query([...$filters, 'search' => 'alpha', 'account' => 'GE00FILTER'])->pluck('id')->all())->toBe([$missing->id])
        ->and($report->query([...$filters, 'dateFrom' => '2026-09-12'])->count())->toBe(0)
        ->and($report->query([...$filters, 'direction' => 'inflow'])->count())->toBe(0);
    $page = Livewire::test(Bank::class)->assertSee($assigned->description)->assertSee($credit->description)
        ->set('uncategorizedExpenses', true)->assertSee($missing->description)->assertSee($uncategorized->description)
        ->assertDontSee($assigned->description)->assertDontSee($shared->description)->assertDontSee($credit->description)
        ->assertViewHas('transactions', fn ($rows) => $rows->total() === 2)
        ->set('search', 'alpha')->assertViewHas('transactions', fn ($rows) => $rows->total() === 1)
        ->set('search', '')->set('uncategorizedExpenses', false)
        ->assertSee($assigned->description)->assertSee($credit->description)->assertViewHas('transactions', fn ($rows) => $rows->total() === 5);
});

test('safe BOG non-P&L defaults do not turn movements into revenue', function ($type, $description, $code) {
    $row = accountingBankRow(['operation_type' => $type, 'direction' => 'inflow', 'amount' => '985.00', 'description' => $description]);
    expect($row->category->code)->toBe($code)->and($row->operation_type)->toBe($type)
        ->and((float) accountingTotals()->revenue)->toBe(0.0)->and((float) accountingTotals()->expenses)->toBe(0.0)
        ->and((float) app(BankReport::class)->totals([])->sole()->inflow)->toBe(985.0);
})->with([
    ['PBS', 'Cash deposit', 'cash_deposit'], ['TRN', 'POS settlement terminal 123', 'card_settlement'],
    ['TRN', 'CARD SETTLEMENT', 'card_settlement'], ['TRN', 'Ordinary incoming transfer', 'uncategorized'],
    ['TRN', 'გადახდა - თანხა:GEL 150; საკომისიო: GEL 2.1; ბარათი: MC, 000000***0000; ტერმინალის ID: POS3TEST; დეპოზ:POS3TEST;', 'card_settlement'],
    ['TRN', 'გადახდა - ბარათი: AMEXD, 000000***0000; ტერმინალის ID: POS3TEST;', 'card_settlement'],
    ['TRN', 'ტერმინალის ID: POS3TEST; ordinary refund', 'uncategorized'],
    ['PMD', 'Payment', 'uncategorized'],
]);

test('manual supplier classification and ERP exclusion persist without changing Bank movement totals', function () {
    $row = accountingBankRow(['amount' => '103.00']);
    $rawBefore = $row->only(['amount', 'direction', 'operation_type', 'raw_data']);
    $supplier = BankCategory::where('code', 'supplier')->sole();
    $page = Livewire::test(Bank::class)->call('assignCategory', $row->id, (string) $supplier->id)->assertHasNoErrors();
    expect((float) accountingTotals()->expenses)->toBe(103.0)->and($row->fresh()->classification_source)->toBe('manual');
    $page->call('showTransaction', $row->id)->call('markAlreadyRecorded', $row->id, true)->assertSee('Already recorded in ERP');
    expect((float) accountingTotals()->expenses)->toBe(0.0)->and($row->fresh()->exclude_from_pnl)->toBeTrue()
        ->and((float) app(BankReport::class)->totals([])->sole()->outflow)->toBe(103.0)
        ->and($row->fresh()->only(array_keys($rawBefore)))->toBe($rawBefore);
    $page->call('markAlreadyRecorded', $row->id, false);
    expect((float) accountingTotals()->expenses)->toBe(103.0);
});

test('transfer and currency exchange are excluded regardless of direction', function ($code, $direction) {
    $row = accountingBankRow(['direction' => $direction]);
    $row->update(['bank_category_id' => BankCategory::where('code', $code)->value('id')]);
    expect((float) accountingTotals()->revenue)->toBe(0.0)->and((float) accountingTotals()->expenses)->toBe(0.0);
})->with([['internal_transfer', 'inflow'], ['internal_transfer', 'outflow'], ['currency_exchange', 'outflow'], ['cash_deposit', 'inflow']]);

test('Bank income classification does not replace canonical ERP revenue and duplicate import preserves it', function () {
    $row = accountingBankRow(['direction' => 'inflow', 'amount' => '200.00']);
    Livewire::test(Bank::class)->call('assignCategory', $row->id, (string) BankCategory::where('code', 'business_income')->value('id'));
    $dto = new BankTransactionData(array_replace($row->toArray(), ['transaction_date' => '2026-09-11 00:00:00']));
    $counts = app(BankIngestionService::class)->ingest([$dto], 'import');
    expect($counts['duplicate_rows'])->toBe(1)->and((float) accountingTotals()->revenue)->toBe(0.0);
    $row->refresh()->update(['exclude_from_pnl' => true]);
    expect((float) accountingTotals()->revenue)->toBe(0.0)->and((float) app(BankReport::class)->totals([])->sole()->inflow)->toBe(200.0);
});

test('patient card payment plus settlement counts revenue once and standalone fee once', function () {
    $patient = Patient::create(['first_name' => 'Accounting', 'last_name' => 'Test']);
    $doctor = Doctor::create(['first_name' => 'Test', 'last_name' => 'Doctor', 'is_active' => true]);
    $visit = Visit::create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'visit_date' => today(), 'total_price' => 1000, 'currency' => 'GEL']);
    $payment = Payment::createWithSplits(['visit_id' => $visit->id, 'amount' => 1000, 'currency' => 'GEL', 'payment_date' => today()], [['payment_method' => 'card', 'amount' => 1000]]);
    accountingBankRow(['direction' => 'inflow', 'amount' => '985.00', 'bank_fee' => '15.00', 'operation_type' => 'TRN', 'description' => 'POS settlement']);
    accountingBankRow(['operation_type' => 'COM']);
    expect((float) accountingTotals()->revenue)->toBe(1000.0)->and((float) accountingTotals()->expenses)->toBe(15.0)
        ->and((float) accountingTotals('cash')->revenue)->toBe(1000.0)->and((float) accountingTotals('bank')->revenue)->toBe(0.0)
        ->and((float) app(BankReport::class)->totals([])->sole()->fees)->toBe(15.0);
    $payment->delete();
    expect((float) accountingTotals()->revenue)->toBe(0.0);
});

test('withheld fee annotations require explicit confirmation and respect ERP exclusion', function () {
    $row = accountingBankRow(['direction' => 'inflow', 'amount' => '985.00', 'bank_fee' => '15.00', 'operation_type' => 'TRN', 'description' => 'POS settlement']);
    expect((float) accountingTotals()->expenses)->toBe(0.0);
    $page = Livewire::test(Bank::class)->call('includeEmbeddedFee', $row->id, true)->assertHasNoErrors();
    expect((float) accountingTotals()->expenses)->toBe(15.0)->and((float) accountingTotals()->revenue)->toBe(0.0);
    $page->call('markAlreadyRecorded', $row->id, true);
    expect((float) accountingTotals()->expenses)->toBe(0.0);
    $other = accountingBankRow();
    $page->call('includeEmbeddedFee', $other->id, true)->assertHasErrors('embedded_fee');
});

test('rules classify future PMD imports and backfill only unclassified rows', function () {
    $old = accountingBankRow(['counterparty_name' => 'JSC TELASI']);
    $manual = accountingBankRow(['counterparty_name' => 'JSC TELASI']);
    Livewire::test(Bank::class)->call('assignCategory', $manual->id, '');
    $general = app(ExpenseDimensions::class)->id('direction', 'general');
    $shared = app(ExpenseDimensions::class)->id('type', 'utilities', $general);
    Livewire::test(BankRules::class)->call('edit')->set('counterparty', 'JSC TELASI')->set('confirmCompanyDefault', true)
        ->set('directionId', $general)->set('typeId', $shared)->call('save')->assertHasNoErrors();
    $future = accountingBankRow(['counterparty_name' => 'JSC TELASI']);
    expect($future->expense_type_id)->toBe($shared)->and($old->fresh()->expense_type_id)->toBeNull();
    Livewire::test(BankRules::class)->call('mountAction', 'applyRules')->callMountedAction();
    expect($old->fresh()->expense_type_id)->toBe($shared)->and($manual->fresh()->bank_category_id)->toBeNull()
        ->and(app(BankClassificationService::class)->applyToUncategorized())->toBe(0);
    BankCategorizationRule::query()->update(['active' => false]);
    expect(accountingBankRow(['counterparty_name' => 'JSC TELASI'])->category->code)->toBe('uncategorized');
});

test('rules use literal phrases and safe COM defaults take precedence', function () {
    $category = BankCategory::where('code', 'utilities')->sole();
    BankCategorizationRule::create(['field' => 'either', 'phrase' => 'TELASI', 'bank_category_id' => $category->id]);
    expect(accountingBankRow(['description' => 'TELASI electricity'])->expense_category_id)->toBe($category->expense_category_id)
        ->and(accountingBankRow(['operation_type' => 'COM', 'description' => 'TELASI commission'])->category->code)->toBe('bank_fee');
    ExpenseCategory::findOrFail($category->expense_category_id)->update(['active' => false]);
    expect(accountingBankRow(['description' => 'TELASI electricity'])->category->code)->toBe('uncategorized');
});

test('canonical finance expenses and reversals do not create artificial revenue', function () {
    $expense = FinanceTransaction::create(['type' => 'expense', 'category' => 'salary', 'transaction_date' => today(), 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    expect((float) accountingTotals()->expenses)->toBe(100.0);
    FinanceTransaction::create(['type' => 'income', 'category' => 'salary', 'transaction_date' => today(), 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier', 'reversal_of_finance_transaction_id' => $expense->id]);
    expect((float) accountingTotals()->revenue)->toBe(0.0)->and((float) accountingTotals()->expenses)->toBe(0.0);
});

test('independent partner expenses count once while linked salary cash allocations are excluded', function () {
    $expense = FinanceTransaction::create(['type' => 'expense', 'category' => 'salary', 'transaction_date' => today(), 'amount' => 100, 'currency' => 'GEL', 'payment_method' => 'cash', 'cash_source' => 'current_cashier']);
    // Stored allocation fixture: the 40 GEL movement is part of the 100 GEL expense, not an extra cost.
    DB::table('partner_finance_transactions')->insert(['finance_transaction_id' => $expense->id, 'type' => 'salary_cash', 'transacted_at' => now(), 'amount' => 40, 'currency' => 'GEL', 'source' => 'israeli', 'from_account' => 'cash']);
    PartnerFinanceTransaction::create(['type' => 'expense', 'category' => 'utilities', 'transacted_at' => now(), 'amount' => 20, 'currency' => 'GEL', 'source' => 'israeli', 'from_account' => 'cash']);
    expect((float) accountingTotals('cash')->expenses)->toBe(120.0)->and((float) accountingTotals('cash')->revenue)->toBe(0.0);
});

test('P&L uses one SQL aggregate across currencies and date boundaries', function () {
    accountingBankRow(['operation_type' => 'COM']);
    accountingBankRow(['operation_type' => 'COM', 'currency' => 'USD', 'amount' => '3.00', 'transaction_date' => '2026-09-11 23:59:59']);
    accountingBankRow(['operation_type' => 'COM', 'amount' => '99.00', 'transaction_date' => '2026-09-12 00:00:00']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $totals = app(ProfitLossReport::class)->totals('2026-09-11', '2026-09-11')->keyBy('currency');
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1)->and((float) $totals['GEL']->expenses)->toBe(15.0)->and((float) $totals['USD']->expenses)->toBe(3.0);
    Livewire::test(ProfitLoss::class)->assertSee('Profit &amp; Loss', false)->set('period', 'all')->assertSet('dateFrom', '')->assertSet('dateUntil', '')
        ->set('dateFrom', '2026-09-11')->assertSet('period', 'custom')->set('dateUntil', '2026-09-11')->set('currency', 'USD')->assertSee('3.00')
        ->set('moneySource', 'cash')->assertSee('0.00');
});

test('Owner creates expense categories without treatment enums and other roles cannot manage them', function () {
    Livewire::test(BankCategories::class)->call('edit')->set('name', 'Test expense')->call('save')->assertHasNoErrors();
    expect(ExpenseCategory::where('name', 'Test expense')->exists())->toBeTrue()->and(BankCategory::where('name', 'Test expense')->exists())->toBeFalse();
    $this->actingAs(User::factory()->create(['role' => User::ROLE_ADMINISTRATOR]));
    Livewire::test(BankRules::class)->assertForbidden();
    Livewire::test(ProfitLoss::class)->assertForbidden();
});

test('proven gross net commission becomes an expense and later standalone COM takes precedence', function () {
    $settlement = accountingBankRow(['direction' => 'inflow', 'amount' => '490.00', 'gross_amount' => '500.00', 'bank_fee' => '10.00', 'description' => 'POS settlement', 'operation_type' => 'TRN', 'reference' => 'card-fee-1', 'account_identifier' => 'GE00MATCH']);
    expect((float) accountingTotals()->expenses)->toBe(10.0)->and((float) accountingTotals()->revenue)->toBe(0.0)
        ->and((float) app(BankReport::class)->totals([])->sole()->fees)->toBe(10.0);
    Livewire::test(Bank::class)->call('showTransaction', $settlement->id)->assertDontSee('Commission verified from gross and credited amounts');
    accountingBankRow(['operation_type' => 'COM', 'amount' => '10.00', 'reference' => 'card-fee-1', 'account_identifier' => 'GE00MATCH']);
    expect((float) accountingTotals()->expenses)->toBe(10.0)->and((float) app(BankReport::class)->totals([])->sole()->fees)->toBe(10.0);
    $settlement->update(['is_legacy' => true]);
    expect((float) accountingTotals()->expenses)->toBe(10.0);
});

test('BOG labelled same-currency commission metadata is parsed and refresh never changes movement identity', function () {
    $row = accountingBankRow(['direction' => 'inflow', 'amount' => '490.00', 'operation_type' => 'TRN',
        'description' => 'გადახდა - თანხა:GEL 500; საკომისიო: GEL 10; ბარათი: MC, 000000; ტერმინალის ID: POS3TEST;']);
    expect($row->bank_fee)->toBe('10.00')->and($row->gross_amount)->toBe('500.00')->and((float) accountingTotals()->expenses)->toBe(10.0);
    $row->update(['bank_fee' => 0, 'gross_amount' => null]);
    $facts = $row->only(['amount', 'direction', 'deduplication_key', 'raw_data', 'bank_category_id']);
    $this->artisan('bank:refresh-commission-metadata', ['--dry-run' => true])->assertSuccessful();
    expect($row->fresh()->bank_fee)->toBe('0.00');
    $this->artisan('bank:refresh-commission-metadata')->assertSuccessful();
    expect($row->fresh()->bank_fee)->toBe('10.00')->and($row->fresh()->only(array_keys($facts)))->toBe($facts);
    $other = accountingBankRow(['direction' => 'inflow', 'amount' => '490.00', 'currency' => 'USD', 'description' => $row->description]);
    expect($other->bank_fee)->toBe('0.00');
});

test('Relevant hides settlements without changing Bank totals and All restores their rows', function () {
    accountingBankRow(['operation_type' => 'TRN', 'direction' => 'inflow', 'amount' => '490.00', 'description' => 'POS settlement - noisy row']);
    accountingBankRow(['operation_type' => 'COM', 'description' => 'Visible Bank fee']);
    $page = Livewire::test(Bank::class)->assertSet('viewMode', 'all')->set('viewMode', 'relevant')->assertDontSee('POS settlement - noisy row')->assertSee('Visible Bank fee')
        ->assertViewHas('transactions', fn ($rows) => $rows->total() === 1)->assertSee('Bank expenses');
    $page->set('viewMode', 'all')->assertSee('POS settlement - noisy row')->assertViewHas('transactions', fn ($rows) => $rows->total() === 2)
        ->assertViewHas('totals', fn ($totals) => (float) $totals->sole()->expenses === 15.0);
    $page->set('viewMode', 'relevant')->assertViewHas('totals', fn ($totals) => (float) $totals->sole()->inflow === 490.0 && (float) $totals->sole()->expenses === 15.0);
    Livewire::test(BankCategories::class)->call('edit')->assertDontSee('wire:model="treatment"', false)->set('name', 'Simple expense')->call('save');
    expect(ExpenseCategory::where('name', 'Simple expense')->exists())->toBeTrue();
});
