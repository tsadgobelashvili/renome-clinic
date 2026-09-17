<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Bank;
use App\Filament\Pages\BogTransactions;
use App\Models\BankTransaction;
use App\Models\BogTransaction;
use App\Models\ExpenseCategory;
use App\Models\FinanceTransaction;
use App\Models\User;
use App\Services\Bank\BankExpenseAssignment;
use App\Services\Bank\BankIngestionService;
use App\Services\Bank\BankReport;
use App\Services\Bank\BogBankSyncService;
use App\Services\BogStatementSyncService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-09-16 12:00:00');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'en']));
    app()->setLocale('en');
    $this->app->instance('env', 'local');
    config(['services.bog' => ['client_id' => 'fake', 'client_secret' => 'fake-secret', 'account_number' => 'GE00TEST', 'account_currency' => 'GEL']]);
    Http::preventStrayRequests();
});

function bankApiRecords(): array
{
    return [
        ['entryId' => 'incoming', 'entryDate' => '2026-09-16', 'entryAmountCredit' => 120, 'entryAmountDebit' => 0,
            'documentProductGroup' => 'TRN', 'senderDetails' => ['name' => 'Card settlement company'], 'documentNomination' => 'POS settlement'],
        ['entryId' => 'outgoing', 'entryDate' => '2026-09-15', 'entryAmountCredit' => 0, 'entryAmountDebit' => 25,
            'documentProductGroup' => 'PMD', 'beneficiaryDetails' => ['name' => 'Supplier'], 'documentNomination' => 'Materials purchase', 'technicalMarker' => 'HiddenRawMarker'],
    ];
}

function fakeBankApi(?array $records = null): void
{
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'fake-token']),
        '*api/v2/statement/*' => Http::response(['records' => $records ?? bankApiRecords(), 'totalCount' => count($records ?? bankApiRecords())]),
        '*api/accounts/*' => Http::response(['CurrentBalance' => 4567.89, 'AvailableBalance' => 4500]),
    ]);
}

test('existing Bank owns sync includes today displays API movements and creates no finance records', function () {
    fakeBankApi();
    $page = Livewire::test(Bank::class);
    Http::assertNothingSent();
    $page->callAction('syncBog')->assertHasNoActionErrors()->assertNotified()
        ->assertSee('Card settlement company')->assertSee('Supplier')->assertSee('4,567.89')
        ->assertViewHas('transactions', fn ($rows) => $rows->total() === 2)
        ->assertDontSee('HiddenRawMarker')->assertDontSee('Operation type')->assertDontSee('Raw API payload')->assertDontSee('Unreviewed');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/GE00TEST/GEL/2026-09-10/2026-09-16/true/true/10000'));
    expect(BogTransaction::count())->toBe(2)->and(BankTransaction::count())->toBe(2)->and(FinanceTransaction::count())->toBe(0);
    expect(BogTransaction::where('entry_id', 'outgoing')->sole()->raw_payload)->toBe(bankApiRecords()[1]);
    $page->callAction('syncBog')->assertHasNoActionErrors();
    expect(BankTransaction::count())->toBe(2)->and(BogTransaction::count())->toBe(2)->and(FinanceTransaction::count())->toBe(0);
});

test('Excel and API use entryId and preserve manual classification on duplicate imports', function () {
    fakeBankApi();
    app(BogStatementSyncService::class)->import(bankApiRecords(), 'GE00TEST', 'GEL');
    $adapter = app(BogBankSyncService::class);
    $data = $adapter->toBankData(BogTransaction::where('entry_id', 'outgoing')->sole());
    app(BankIngestionService::class)->ingest([$data], 'import', filename: 'original.xlsx');
    $category = ExpenseCategory::create(['name' => 'Materials test', 'active' => true]);
    $row = BankTransaction::sole();
    Livewire::test(Bank::class)->call('showTransaction', $row->id)->set('expenseCategoryId', $category->id)
        ->call('saveExpenseClassification')->assertHasNoErrors();
    $result = $adapter->sync();
    expect($result['inserted'])->toBe(1)->and($result['duplicates'])->toBe(1)
        ->and($row->fresh()->expense_category_id)->toBe($category->id)->and($row->fresh()->source)->toBe('import');
    expect(app(BankIngestionService::class)->ingest([$data], 'import')['duplicate_rows'])->toBe(1);
    expect(BankTransaction::count())->toBe(2)->and(FinanceTransaction::count())->toBe(0);
});

test('API uses remembered company rules and publishes older staging imports', function () {
    fakeBankApi([]);
    app(BogStatementSyncService::class)->import(bankApiRecords(), 'GE00TEST', 'GEL');
    $category = ExpenseCategory::create(['name' => 'Supplier materials', 'active' => true]);
    app(BankExpenseAssignment::class)->saveRule(['counterparty' => 'Supplier', 'expense_category_id' => $category->id,
        'active' => true, 'confirm_company_default' => true], auth()->user());
    $result = app(BogBankSyncService::class)->sync();
    expect($result['fetched'])->toBe(0)->and($result['inserted'])->toBe(2)
        ->and(BankTransaction::where('operation_id', 'outgoing')->sole()->expense_category_id)->toBe($category->id);
});

test('conflicting Excel accounting facts are still rejected atomically', function () {
    fakeBankApi();
    app(BankIngestionService::class)->ingest([new BankTransactionData([
        'account_identifier' => 'GE00TEST', 'operation_id' => 'outgoing', 'transaction_date' => '2026-09-15 00:00:00',
        'direction' => 'outflow', 'amount' => '99.00', 'currency' => 'GEL',
    ])], 'import');
    expect(fn () => app(BogBankSyncService::class)->sync())->toThrow(DomainException::class);
    expect(BankTransaction::count())->toBe(1)->and(BogTransaction::count())->toBe(0);
});

test('live balance uses CurrentBalance and does not reload for filter changes', function () {
    fakeBankApi();
    $page = Livewire::test(Bank::class)->call('refreshBogBalance')->assertSee('4,567.89')->assertSee('BOG live balance');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/accounts/GE00TEST/GEL/false'));
    $page->set('dateFrom', '2020-01-01')->set('search', 'missing')->assertSee('4,567.89');
    Http::assertSentCount(2);
    Http::swap(new Factory);
    Http::preventStrayRequests();
    Http::fake(['*openid-connect/token' => Http::response([], 503)]);
    $page->call('refreshBogBalance')->assertSet('bogBalance', null)->assertDontSee('4,567.89')->assertSee('Balance unavailable');
});

test('Bank never loads imported balances and shows unavailable on API failure', function () {
    $this->mock(BankReport::class, function ($mock) {
        $mock->shouldReceive('query')->andReturnUsing(fn () => BankTransaction::query());
        $mock->shouldReceive('totals')->andReturn(collect());
        $mock->shouldNotReceive('balances');
    });
    Http::fake(['*openid-connect/token' => Http::response([], 503)]);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $page = Livewire::test(Bank::class)->assertSet('bogBalance', null)->assertSee('Refreshing live balance');
    Http::assertNothingSent();
    $page->call('refreshBogBalance')->assertSee('Balance unavailable')->assertSet('bogBalance', null)
        ->set('dateFrom', '2020-01-01')->set('search', 'anything')->assertSee('Balance unavailable');
    Http::assertSentCount(1);
    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'bank_import_batches') || str_contains($sql, 'opening_balances')))->toBeEmpty();
});

test('partial BOG responses are rejected before writing anything', function () {
    Http::fake(['*openid-connect/token' => Http::response(['access_token' => 'fake']),
        '*api/v2/statement/*' => Http::response(['records' => bankApiRecords(), 'totalCount' => 3])]);
    expect(fn () => app(BogBankSyncService::class)->sync())->toThrow(RuntimeException::class, 'incomplete statement');
    expect(BogTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(0);
});

test('old BOG bookmark redirects to Bank and is absent from navigation', function () {
    expect(BogTransactions::shouldRegisterNavigation())->toBeFalse();
    $this->get(BogTransactions::getUrl())->assertRedirect(Bank::getUrl());
});

test('Bank sync and old bookmark remain owner only', function (string $role) {
    $this->actingAs(User::factory()->create(['role' => $role]));
    $this->get(BogTransactions::getUrl())->assertForbidden();
    Livewire::test(Bank::class)->assertForbidden();
})->with([User::ROLE_ADMINISTRATOR, User::ROLE_LAB_TECHNICIAN]);

test('decorated historical Excel account deduplicates against plain API IBAN without rewriting history', function () {
    $iban = 'GE00BG0000000000000000';
    config(['services.bog.account_number' => $iban]);
    fakeBankApi();
    $data = new BankTransactionData(['account_identifier' => $iban, 'operation_id' => 'outgoing',
        'transaction_date' => '2026-09-15 00:00:00', 'direction' => 'outflow', 'amount' => '25.00', 'currency' => 'GEL']);
    app(BankIngestionService::class)->ingest([$data], 'import');
    $legacy = BankTransaction::sole();
    $decorated = $iban.'GEL(123456789)';
    $legacyKey = hash('sha256', json_encode(['operation', 'BOG', $decorated, 'GEL', 'outgoing'], JSON_THROW_ON_ERROR));
    $legacy->update(['account_identifier' => $decorated, 'deduplication_key' => $legacyKey]);
    $result = app(BogBankSyncService::class)->sync();
    expect($result['inserted'])->toBe(1)->and($result['duplicates'])->toBe(1)
        ->and(BankTransaction::count())->toBe(2)->and($legacy->fresh()->account_identifier)->toBe($decorated)
        ->and($legacy->fresh()->deduplication_key)->toBe($legacyKey);
    // API first, then original decorated Excel: same key and strict accounting comparison.
    $incoming = new BankTransactionData(['account_identifier' => $decorated, 'operation_id' => 'incoming',
        'transaction_date' => '2026-09-16 00:00:00', 'direction' => 'inflow', 'amount' => '120.00', 'currency' => 'GEL']);
    expect(app(BankIngestionService::class)->ingest([$incoming], 'import')['duplicate_rows'])->toBe(1);
    $other = new BankTransactionData([...$incoming->attributes, 'account_identifier' => 'GE00BG0000000000000001']);
    expect(app(BankIngestionService::class)->ingest([$other], 'api')['imported_rows'])->toBe(1);
});
test('fresh API conflicts cannot be hidden by an existing staging duplicate', function () {
    fakeBankApi();
    app(BogBankSyncService::class)->sync();
    $changed = bankApiRecords();
    $changed[1]['entryAmountDebit'] = 99;
    Http::swap(new Factory);
    Http::preventStrayRequests();
    fakeBankApi($changed);
    expect(fn () => app(BogBankSyncService::class)->sync())->toThrow(DomainException::class);
    expect(BankTransaction::where('operation_id', 'outgoing')->sole()->amount)->toBe('25.00')
        ->and(BogTransaction::where('entry_id', 'outgoing')->sole()->debit)->toBe('25.00')
        ->and(BankTransaction::count())->toBe(2);
});

test('first manual sync covers seven local calendar days and updates compact last sync text', function () {
    fakeBankApi();
    $page = Livewire::test(Bank::class)->assertSee('Last sync:')->assertSet('lastBogSyncAt', null)
        ->callAction('syncBog')->assertHasNoActionErrors()->assertSet('lastBogSyncAt', '16.09.2026 12:00');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/2026-09-10/2026-09-16/true/true/10000'));
    expect(app(BogBankSyncService::class)->lastSuccessfulSync()->toDateTimeString())->toBe('2026-09-16 12:00:00');
    // Duplicate-only syncs are successful and advance the checkpoint.
    $this->travelTo('2026-09-16 13:00:00');
    $page->callAction('syncBog')->assertSet('lastBogSyncAt', '16.09.2026 13:00');
    expect(BogTransaction::count())->toBe(2)->and(BankTransaction::count())->toBe(2)
        ->and(app(BogBankSyncService::class)->lastSuccessfulSync()->toDateTimeString())->toBe('2026-09-16 13:00:00');
});

test('manual sync catches missed days with a calendar day overlap in the app timezone', function () {
    DB::table('bog_sync_states')->insert(['account_number' => 'GE00TEST', 'currency' => 'GEL', 'last_successful_sync_at' => '2026-09-16 18:45:00']);
    $this->travelTo(CarbonImmutable::parse('2026-09-20 00:30:00', config('app.timezone')));
    fakeBankApi([]);
    app(BogBankSyncService::class)->sync();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/2026-09-15/2026-09-20/true/true/10000'));
    expect(app(BogBankSyncService::class)->lastSuccessfulSync()->toDateTimeString())->toBe('2026-09-20 00:30:00');
});

test('failed authentication or statement does not advance the checkpoint', function (string $failure) {
    DB::table('bog_sync_states')->insert(['account_number' => 'GE00TEST', 'currency' => 'GEL', 'last_successful_sync_at' => '2026-09-12 10:00:00']);
    Http::fake([
        '*openid-connect/token' => $failure === 'auth' ? Http::response([], 401) : Http::response(['access_token' => 'fake']),
        '*api/v2/statement/*' => Http::response([], 503),
    ]);
    expect(fn () => app(BogBankSyncService::class)->sync())->toThrow(RuntimeException::class);
    expect(app(BogBankSyncService::class)->lastSuccessfulSync()->toDateTimeString())->toBe('2026-09-12 10:00:00')
        ->and(BogTransaction::count())->toBe(0)->and(BankTransaction::count())->toBe(0);
})->with(['auth', 'statement']);

test('row errors and aborted ledger imports do not mark sync successful', function () {
    fakeBankApi([['entryId' => 'invalid', 'entryDate' => '2026-09-16', 'entryAmountCredit' => 0, 'entryAmountDebit' => 0]]);
    $result = app(BogBankSyncService::class)->sync();
    expect($result['errors'])->not->toBeEmpty()->and(app(BogBankSyncService::class)->lastSuccessfulSync())->toBeNull();
    Http::swap(new Factory);
    Http::preventStrayRequests();
    fakeBankApi();
    $this->mock(BankIngestionService::class)->shouldReceive('ingest')->once()->andThrow(new RuntimeException('Interrupted import'));
    expect(fn () => app(BogBankSyncService::class)->sync())->toThrow(RuntimeException::class, 'Interrupted import');
    expect(app(BogBankSyncService::class)->lastSuccessfulSync())->toBeNull()->and(BogTransaction::count())->toBe(0);
});
