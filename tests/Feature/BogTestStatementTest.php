<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->app->instance('env', 'local');
    config(['services.bog' => [
        'client_id' => 'fake-client', 'client_secret' => 'fake-secret',
        'account_number' => 'GE00TEST', 'account_currency' => 'GEL',
    ]]);
    Http::preventStrayRequests();
});

test('BOG local command authenticates and prints the last two calendar days without database queries', function () {
    $this->travelTo(now()->setDate(2026, 9, 16)->setTime(12, 0));
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'fake-access-token']),
        '*api/v2/statement/*' => Http::response(['Records' => [
            ['Date' => '2026-09-15', 'Credit' => 147.90, 'Description' => 'Test inflow'],
            ['Date' => '2026-09-16', 'Debit' => 103, 'Description' => 'Test outflow'],
        ]]),
    ]);
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    expect(Artisan::call('bog:test-statement'))->toBe(0);
    $output = Artisan::output();
    expect($output)->toContain('HTTP status: 200', 'Record count: 2', 'Test inflow', 'Test outflow')
        ->not->toContain('fake-secret', 'fake-access-token')
        ->and($queries)->toBeEmpty();
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://account.bog.ge/auth/realms/bog/protocol/openid-connect/token'
        && $request->hasHeader('Authorization', 'Basic '.base64_encode('fake-client:fake-secret'))
        && $request['grant_type'] === 'client_credentials');
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://api.businessonline.ge/api/v2/statement/GE00TEST/GEL/2026-09-15/2026-09-16'
        && $request->hasHeader('Authorization', 'Bearer fake-access-token'));
    Http::assertSentCount(2);
});

test('BOG explicit dates work and even echoed credentials are redacted', function () {
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'fake-access-token']),
        '*api/v2/statement/*' => Http::response([['description' => 'fake-secret fake-access-token', 'access_token' => 'another-token']]),
    ]);
    expect(Artisan::call('bog:test-statement', ['startDate' => '2026-09-04', 'endDate' => '2026-09-11']))->toBe(0);
    expect(Artisan::output())->toContain('Record count: 1', '[redacted]')->not->toContain('fake-secret', 'fake-access-token', 'another-token');
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/2026-09-04/2026-09-11'));
});

test('BOG command rejects invalid dates without requests', function ($start, $end) {
    expect(Artisan::call('bog:test-statement', ['startDate' => $start, 'endDate' => $end]))->toBe(1);
    Http::assertNothingSent();
})->with([['2026-02-30', '2026-09-16'], ['2026-09-17', '2026-09-16'], ['15.09.2026', '2026-09-16']]);

test('BOG command refuses nonlocal environments and missing credentials', function () {
    $this->app->instance('env', 'production');
    expect(Artisan::call('bog:test-statement'))->toBe(1);
    expect(Artisan::output())->toContain('APP_ENV=local');
    $this->app->instance('env', 'local');
    config(['services.bog.client_secret' => '']);
    expect(Artisan::call('bog:test-statement'))->toBe(1);
    expect(Artisan::output())->toContain('BOG_CLIENT_SECRET');
    Http::assertNothingSent();
});

test('BOG errors show status without exposing response bodies', function ($tokenStatus, $statementStatus) {
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'fake-access-token', 'error' => 'fake-secret'], $tokenStatus),
        '*api/v2/statement/*' => Http::response(['error' => 'fake-access-token fake-secret'], $statementStatus),
    ]);
    expect(Artisan::call('bog:test-statement'))->toBe(1);
    expect(Artisan::output())->toContain('HTTP '.($tokenStatus === 200 ? $statementStatus : $tokenStatus))
        ->not->toContain('fake-secret', 'fake-access-token');
    Http::assertSentCount($tokenStatus === 200 ? 2 : 1);
})->with([[401, 200], [200, 403], [200, 500]]);

test('BOG handles network failures without printing transport details', function () {
    Http::fake(['*' => Http::failedConnection('fake-secret fake-access-token')]);
    expect(Artisan::call('bog:test-statement'))->toBe(1);
    expect(Artisan::output())->toContain('Could not connect to BOG')->not->toContain('fake-secret', 'fake-access-token');
});

test('BOG empty statement succeeds but an unexpected response does not masquerade as zero records', function () {
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'fake-access-token']),
        '*api/v2/statement/*' => Http::sequence()->push(['Records' => []])->push(['unexpected' => 'fake-secret']),
    ]);
    expect(Artisan::call('bog:test-statement'))->toBe(0);
    expect(Artisan::output())->toContain('Record count: 0');
    expect(Artisan::call('bog:test-statement'))->toBe(1);
    expect(Artisan::output())->toContain('unexpected statement format')->not->toContain('fake-secret');
});
