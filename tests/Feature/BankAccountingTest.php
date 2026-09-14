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

test('COM defaults to Bank fee and contributes its debit exactly once to fees and P&L', function () {
    $row = accountingBankRow(['operation_type' => 'COM', 'bank_fee' => '15.00']);
    expect($row->category->code)->toBe('bank_fee')->and($row->category->accounting_treatment)->toBe('expense')
        ->and($row->direction)->toBe('outflow')->and($row->classification_source)->toBe('default');
    expect((float) app(BankReport::class)->totals([])->sole()->fees)->toBe(15.0)
        ->and((float) accountingTotals()->expenses)->toBe(15.0)->and((float) accountingTotals()->profit)->toBe(-15.0);
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
    $shared = BankCategory::where('code', 'utilities')->value('expense_category_id');
    Livewire::test(BankRules::class)->call('edit')->set('counterparty', 'JSC TELASI')->set('confirmCompanyDefault', true)
        ->set('categoryId', $shared)->call('save')->assertHasNoErrors();
    $future = accountingBankRow(['counterparty_name' => 'JSC TELASI']);
    expect($future->expense_category_id)->toBe($shared)->and($old->fresh()->expense_category_id)->toBeNull();
    Livewire::test(BankRules::class)->call('mountAction', 'applyRules')->callMountedAction();
    expect($old->fresh()->expense_category_id)->toBe($shared)->and($manual->fresh()->bank_category_id)->toBeNull()
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
    $settlement = accountingBankRow(['direction' => 'inflow', 'amount' => '490.00', 'gross_amount' => '500.00', 'bank_fee' => '10.00', 'description' => 'POS settlement', 'operation_type' => 'TRN', 'reference' => 'card-fee-1']);
    expect((float) accountingTotals()->expenses)->toBe(10.0)->and((float) accountingTotals()->revenue)->toBe(0.0)
        ->and((float) app(BankReport::class)->totals([])->sole()->fees)->toBe(10.0);
    Livewire::test(Bank::class)->call('showTransaction', $settlement->id)->assertSee('Commission verified from gross and credited amounts');
    accountingBankRow(['operation_type' => 'COM', 'amount' => '10.00', 'reference' => 'card-fee-1']);
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
    $page = Livewire::test(Bank::class)->assertSet('viewMode', 'relevant')->assertDontSee('POS settlement - noisy row')->assertSee('Visible Bank fee')
        ->assertViewHas('transactions', fn ($rows) => $rows->total() === 1)->assertSee('Bank expenses');
    $page->set('viewMode', 'all')->assertSee('POS settlement - noisy row')->assertViewHas('transactions', fn ($rows) => $rows->total() === 2)
        ->assertViewHas('totals', fn ($totals) => (float) $totals->sole()->expenses === 15.0);
    $page->set('viewMode', 'relevant')->assertViewHas('totals', fn ($totals) => (float) $totals->sole()->inflow === 490.0 && (float) $totals->sole()->expenses === 15.0);
    Livewire::test(BankCategories::class)->call('edit')->assertDontSee('wire:model="treatment"', false)->set('name', 'Simple expense')->call('save');
    expect(ExpenseCategory::where('name', 'Simple expense')->exists())->toBeTrue();
});
