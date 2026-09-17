<?php

use App\Data\BankTransactionData;
use App\Filament\Pages\Finance;
use App\Models\BankTransaction;
use App\Models\User;
use App\Services\Bank\BankIngestionService;
use App\Services\Finance\BankBalances;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo('2026-09-17 11:30:15');
    $this->actingAs(User::factory()->create(['role' => User::ROLE_OWNER, 'locale' => 'en']));
    app()->setLocale('en');
    $this->app->instance('env', 'local');
    config(['services.bog' => ['client_id' => 'test', 'client_secret' => 'test-secret', 'account_number' => 'GE00BG0000000000000000', 'account_currency' => 'GEL']]);
    Http::preventStrayRequests();
    $this->mock(BankBalances::class)->shouldNotReceive('current');
});

test('Finance summary and account detail share one live BOG balance and ignore legacy aliases', function () {
    foreach (['GE00BG0000000000000000GEL(123456789)', 'GE00BG0000000000000000', 'invalid'] as $index => $account) {
        app(BankIngestionService::class)->ingest([new BankTransactionData(['account_identifier' => $account,
            'operation_id' => 'legacy-'.$index, 'transaction_date' => '2026-09-10 00:00:00', 'currency' => 'GEL',
            'direction' => 'inflow', 'amount' => '10.00'])], 'import');
    }
    DB::table('bank_import_batches')->insert(['bank' => 'BOG', 'account_identifier' => 'GE00BG0000000000000000GEL(123456789)',
        'currency' => 'GEL', 'accounts' => '[]', 'currencies' => '["GEL"]', 'source_file' => 'old.xlsx', 'file_hash' => str_repeat('a', 64),
        'imported_at' => now()->subDays(7), 'reported_balance' => 1234.56, 'balance_as_of' => '2026-09-10 00:00:00', 'balance_origin' => 'closing_balance']);
    DB::table('bog_sync_states')->insert(['account_number' => config('services.bog.account_number'), 'currency' => 'GEL', 'last_successful_sync_at' => '2026-09-16 09:00:00']);
    Http::fake(['*openid-connect/token' => Http::response(['access_token' => 'test-token']),
        '*api/accounts/*' => Http::response(['CurrentBalance' => 50169.10])]);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $page = Livewire::test(Finance::class)->assertSet('bogBalance', null)->assertDontSee('1,234.56');
    Http::assertNothingSent();
    $page->call('refreshBogBalance')->call('selectOverviewCard', 'bank')
        ->assertSee('50,169.10')->assertSee('Balance fetched: 17.09.2026 11:30:15')->assertSee('Transactions synced: 16.09.2026 09:00')
        ->assertViewHas('figures', fn ($f) => $f['GEL']['bank'] === 50169.10 && $f['USD']['bank'] === null)
        ->assertViewHas('liquidity', fn ($data) => $data['accounts']->count() === 1 && (float) $data['accounts']->sole()->reported_balance === $data['totals']['GEL']['bank'])
        ->assertDontSee('old.xlsx')->assertDontSee('1,234.56')
        ->set('businessSource', 'clinic')->set('dateFrom', '2020-01-01')->set('dateUntil', '2020-01-07')
        ->assertViewHas('figures', fn ($f) => $f['GEL']['bank'] === 50169.10)
        ->set('overviewCurrency', 'USD')->assertSee('Data unavailable')
        ->assertViewHas('figures', fn ($f) => $f['USD']['bank'] === null);
    Http::assertSentCount(2);
    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'bank_import_batches')))->toBeEmpty();
    expect(DB::table('bank_import_batches')->value('reported_balance'))->toEqual(1234.56)->and(BankTransaction::count())->toBe(3);
});

test('API zero is valid but failed refresh clears all live balance values without legacy fallback', function () {
    Http::fake(['*openid-connect/token' => Http::response(['access_token' => 'test-token']),
        '*api/accounts/*' => Http::sequence()->push(['CurrentBalance' => 0])->push([], 503)]);
    $page = Livewire::test(Finance::class)->call('refreshBogBalance')
        ->assertViewHas('figures', fn ($f) => $f['GEL']['bank'] === 0.0 && $f['USD']['bank'] === null);
    $page->call('refreshBogBalance')->assertSet('bogBalance', null)->assertSee('Data unavailable')
        ->assertViewHas('figures', fn ($f) => $f['GEL']['bank'] === null && $f['USD']['bank'] === null)
        ->assertViewHas('liquidity', fn ($data) => $data['accounts']->isEmpty());
});
