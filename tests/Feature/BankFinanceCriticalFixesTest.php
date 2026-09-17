<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Bank;
use App\Models\BankCategory;
use App\Models\BankTransaction;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\Bank\BankExpenseAssignment;
use App\Services\Bank\BankIngestionService;
use App\Services\Bank\BankReport;
use App\Services\ExpenseDimensions;
use App\Services\Finance\AccountingLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-09-17 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'ka']));
});

function criticalBankRow(array $attributes = []): BankTransaction
{
    $dto = new BankTransactionData($attributes + ['operation_id' => (string) Str::uuid(), 'account_identifier' => 'GE00CRITICAL',
        'transaction_date' => '2026-09-17 10:00:00', 'direction' => 'outflow', 'operation_type' => 'PMD', 'amount' => '5000.00', 'currency' => 'GEL']);
    app(BankIngestionService::class)->ingest([$dto], 'api');

    return BankTransaction::where('deduplication_key', $dto->deduplicationKey())->sole();
}

function criticalSettlement(array $attributes = []): BankTransaction
{
    return criticalBankRow($attributes + ['direction' => 'inflow', 'operation_type' => 'TRN', 'amount' => '362.97',
        'gross_amount' => '370.00', 'bank_fee' => '0.00', 'description' => 'POS settlement']);
}

function criticalExpenses(): float
{
    return (float) (app(AccountingLedger::class)->pnlTotals('2026-09-17', '2026-09-17')->firstWhere('currency', 'GEL')?->expenses ?? 0);
}

function classifyCriticalExpense(BankTransaction $row): void
{
    $dimensions = app(ExpenseDimensions::class);
    app(BankExpenseAssignment::class)->assign($row->id, ['expense_direction_id' => $dimensions->id('direction', 'general'),
        'expense_type_id' => $dimensions->childForType($dimensions->id('direction', 'general'), $dimensions->id('type', 'salary'))], auth()->user());
}

test('Bank and Finance share gross net acquiring fees when the stored fee is zero', function () {
    $settlement = criticalSettlement();
    criticalBankRow(['operation_type' => 'COM', 'amount' => '1.50']);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $totals = app(BankReport::class)->totals(['currency' => 'GEL', 'account' => 'GE00CRITICAL', 'dateFrom' => '2026-09-17', 'dateUntil' => '2026-09-17'])->sole();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $fee = app(AccountingLedger::class)->pnl(null, null)->where('entry_key', 'bank-fee:'.$settlement->id)->sole();
    expect($settlement->bank_fee)->toBe('0.00')->and((float) $fee->amount)->toBe(7.03)
        ->and((float) $totals->card_fees)->toBe(7.03)->and((float) $totals->transfer_fees)->toBe(1.5)
        ->and(round((float) $totals->fees, 2))->toBe(8.53)->and(round(criticalExpenses(), 2))->toBe(8.53)
        ->and($queries)->toBe(1)->and(FinanceTransaction::count())->toBe(0);
});

test('uncategorized debit stays visible and affects Finance exactly once only after classification', function () {
    $row = criticalBankRow(['description' => 'Unreviewed supplier debit']);
    expect(criticalExpenses())->toBe(0.0);
    Livewire::test(Bank::class)->set('uncategorizedExpenses', true)->assertSee('Unreviewed supplier debit')
        ->assertViewHas('transactions', fn ($rows) => $rows->total() === 1);
    classifyCriticalExpense($row);
    classifyCriticalExpense($row);
    expect(criticalExpenses())->toBe(5000.0)->and(BankTransaction::count())->toBe(1)->and(FinanceTransaction::count())->toBe(0)
        ->and(app(BankReport::class)->query(['uncategorizedExpenses' => true])->count())->toBe(0);
    $dto = new BankTransactionData([...$row->fresh()->toArray(), 'transaction_date' => '2026-09-17 10:00:00']);
    expect(app(BankIngestionService::class)->ingest([$dto], 'api')['duplicate_rows'])->toBe(1)
        ->and(criticalExpenses())->toBe(5000.0);
});

test('explicit non-expense treatments and credits override retained expense dimensions', function ($treatment, $direction) {
    $row = criticalBankRow();
    classifyCriticalExpense($row);
    $row->refresh()->update(['direction' => $direction, 'bank_category_id' => BankCategory::where('code', $treatment)->value('id')]);
    expect(criticalExpenses())->toBe(0.0)->and((float) app(BankReport::class)->totals([])->sole()->expenses)->toBe(0.0);
})->with([['internal_transfer', 'outflow'], ['cash_deposit', 'outflow'], ['currency_exchange', 'outflow'], ['card_settlement', 'outflow'], ['business_income', 'inflow']]);

test('the compact ERP exclusion control prevents a second salary expense and survives reimport', function () {
    FinanceTransaction::create(['type' => 'expense', 'category' => 'salary', 'amount' => 1000, 'currency' => 'GEL',
        'transaction_date' => now(), 'payment_method' => 'bank_transfer']);
    $row = criticalBankRow(['amount' => '1000.00', 'description' => 'Salary bank movement']);
    classifyCriticalExpense($row);
    expect(criticalExpenses())->toBe(2000.0);
    app()->setLocale('ka');
    $page = Livewire::test(Bank::class)->call('toggleTransaction', $row->id)
        ->assertSee('უკვე აღრიცხულია ERP-ში')->assertSeeHtml('wire:change="markAlreadyRecorded('.$row->id.', $event.target.checked)"')
        ->call('markAlreadyRecorded', $row->id, true)->assertHasNoErrors()->assertSee('Salary bank movement');
    expect(criticalExpenses())->toBe(1000.0)->and($row->fresh()->exclude_from_pnl)->toBeTrue()
        ->and((float) app(BankReport::class)->totals([])->sole()->outflow)->toBe(1000.0);
    $dto = new BankTransactionData([...$row->fresh()->toArray(), 'transaction_date' => '2026-09-17 10:00:00']);
    app(BankIngestionService::class)->ingest([$dto], 'api');
    expect(criticalExpenses())->toBe(1000.0)->and(FinanceTransaction::count())->toBe(1)->and(BankTransaction::count())->toBe(1);
    $page->call('markAlreadyRecorded', $row->id, false)->assertHasNoErrors();
    expect(criticalExpenses())->toBe(2000.0);
});

test('same date and amount alone never collapse unrelated acquiring and transfer fees', function () {
    criticalSettlement(['raw_data' => ['entryId' => 'settlement-entry', 'documentKey' => 123]]);
    criticalBankRow(['operation_type' => 'COM', 'amount' => '7.03', 'raw_data' => ['entryId' => 'commission-entry', 'documentKey' => 123]]);
    $total = app(BankReport::class)->totals([])->sole();
    expect((float) $total->card_fees)->toBe(7.03)->and((float) $total->transfer_fees)->toBe(7.03)
        ->and(round((float) $total->fees, 2))->toBe(14.06)->and(round(criticalExpenses(), 2))->toBe(14.06);
});

test('a unique same account reference and amount pair counts once and keeps the acquiring subtype', function () {
    criticalSettlement(['reference' => 'acquiring-reference']);
    criticalBankRow(['operation_type' => 'COM', 'amount' => '7.03', 'reference' => 'acquiring-reference']);
    $total = app(BankReport::class)->totals([])->sole();
    expect((float) $total->fees)->toBe(7.03)->and((float) $total->card_fees)->toBe(7.03)
        ->and((float) $total->transfer_fees)->toBe(0.0)->and(criticalExpenses())->toBe(7.03);
});

test('excluding the authoritative paired commission does not resurrect its embedded duplicate', function () {
    criticalSettlement(['reference' => 'recorded-reference']);
    $fee = criticalBankRow(['operation_type' => 'COM', 'amount' => '7.03', 'reference' => 'recorded-reference']);
    Livewire::test(Bank::class)->call('markAlreadyRecorded', $fee->id, true)->assertHasNoErrors();
    expect(criticalExpenses())->toBe(0.0)->and((float) app(BankReport::class)->totals([])->sole()->fees)->toBe(7.03);
});

test('a direction alone is not an expense assignment but an explicit expense type qualifies', function () {
    $row = criticalBankRow();
    $dimensions = app(ExpenseDimensions::class);
    $row->update(['expense_direction_id' => $dimensions->id('direction', 'general')]);
    expect(criticalExpenses())->toBe(0.0)->and(app(BankReport::class)->query(['uncategorizedExpenses' => true])->count())->toBe(1);
    $row->update(['expense_type_id' => $dimensions->id('type', 'materials')]);
    expect(criticalExpenses())->toBe(5000.0);
});

test('one standalone fee cannot suppress multiple settlements sharing a reference', function () {
    criticalSettlement(['reference' => 'ambiguous-reference']);
    criticalSettlement(['reference' => 'ambiguous-reference']);
    criticalBankRow(['operation_type' => 'COM', 'amount' => '7.03', 'reference' => 'ambiguous-reference']);
    $total = app(BankReport::class)->totals([])->sole();
    expect(round((float) $total->card_fees, 2))->toBe(14.06)->and((float) $total->transfer_fees)->toBe(7.03)
        ->and(round((float) $total->fees, 2))->toBe(21.09)->and(round(criticalExpenses(), 2))->toBe(21.09);
});

test('multiple standalone candidates are not arbitrarily paired with one settlement', function () {
    criticalSettlement(['reference' => 'ambiguous-reference']);
    criticalBankRow(['operation_type' => 'COM', 'amount' => '7.03', 'reference' => 'ambiguous-reference']);
    criticalBankRow(['operation_type' => 'COM', 'amount' => '7.03', 'reference' => 'ambiguous-reference']);
    $total = app(BankReport::class)->totals([])->sole();
    expect((float) $total->card_fees)->toBe(7.03)->and((float) $total->transfer_fees)->toBe(14.06)
        ->and(round(criticalExpenses(), 2))->toBe(21.09);
});

test('shared reference does not match a different amount account currency or excluded treatment', function ($overrides) {
    criticalSettlement(['reference' => 'scoped-reference']);
    $fee = criticalBankRow($overrides + ['operation_type' => 'COM', 'amount' => '7.03', 'reference' => 'scoped-reference']);
    if (isset($overrides['transfer'])) {
        $fee->update(['bank_category_id' => BankCategory::where('code', 'internal_transfer')->value('id')]);
    }
    $acquiring = app(AccountingLedger::class)->pnl(null, null)->where('origin', 'withheld_fee')->sole();
    expect((float) $acquiring->amount)->toBe(7.03);
})->with([[['amount' => '1.50']], [['account_identifier' => 'GE00OTHER']], [['currency' => 'USD']], [['transfer' => true]], [['account_identifier' => null]], [['reference' => null]], [['reference' => 'different-reference']]]);
