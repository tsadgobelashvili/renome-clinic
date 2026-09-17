<?php

use App\Models\BogTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->instance('env', 'local');
    config(['services.bog' => [
        'client_id' => 'fake-client', 'client_secret' => 'fake-secret',
        'account_number' => 'GE00TEST', 'account_currency' => 'GEL',
    ]]);
    Http::preventStrayRequests();
});

function fakeBogSyncRecords(array $records): void
{
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'fake-access-token']),
        '*api/v2/statement/*' => Http::response(['Records' => $records]),
    ]);
}

test('BOG sync imports an unreviewed transaction and its full raw record without finance writes', function () {
    $record = [
        'EntryId' => '124773207693', 'OperationDate' => '2026-09-15T12:30:00', 'ValueDate' => '2026-09-16',
        'Debit' => null, 'Credit' => 147.9, 'Description' => 'Card settlement',
        'CounterpartyName' => 'Test business', 'CounterpartyAccount' => 'GE00COUNTERPARTY',
        'CounterpartyBank' => 'BOG', 'OperationType' => 'TRN', 'ExtraMetadata' => ['AuthorizationCode' => 'original-reference'],
    ];
    fakeBogSyncRecords([$record]);
    $writes = [];
    DB::listen(function ($query) use (&$writes) {
        if (preg_match('/^\s*(insert|update|delete)/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });
    expect(Artisan::call('bog:sync', ['startDate' => '2026-09-01', 'endDate' => '2026-09-16']))->toBe(0);
    expect(Artisan::output())->toContain('Fetched: 1', 'Inserted: 1', 'Skipped duplicates: 0', 'Errors: 0')
        ->not->toContain('fake-secret', 'fake-access-token');
    $transaction = BogTransaction::sole();
    expect($transaction->entry_id)->toBe('124773207693')
        ->and($transaction->account_number)->toBe('GE00TEST')->and($transaction->currency)->toBe('GEL')
        ->and($transaction->operation_date->toDateString())->toBe('2026-09-15')
        ->and($transaction->value_date->toDateString())->toBe('2026-09-16')
        ->and($transaction->description)->toBe('Card settlement')
        ->and($transaction->counterparty_name)->toBe('Test business')
        ->and($transaction->counterparty_account)->toBe('GE00COUNTERPARTY')
        ->and($transaction->counterparty_bank)->toBe('BOG')
        ->and($transaction->operation_type)->toBe('TRN')
        ->and($transaction->status)->toBe('unreviewed')->and($transaction->raw_payload)->toBe($record)
        ->and($writes)->toHaveCount(1)->and($writes[0])->toContain('bog_transactions');
    Http::assertSentCount(2);
});

test('BOG sync skips existing entry ids and the unique database constraint prevents duplicates', function () {
    $record = ['EntryId' => 'entry-1', 'OperationDate' => '2026-09-15', 'Debit' => 103, 'Credit' => null];
    Http::fake([
        '*openid-connect/token' => Http::response(['access_token' => 'fake-access-token']),
        '*api/v2/statement/*' => Http::sequence()
            ->push(['Records' => [$record, $record]])
            ->push(['Records' => [array_replace($record, ['Debit' => 999])]]),
    ]);
    expect(Artisan::call('bog:sync'))->toBe(0);
    expect(Artisan::output())->toContain('Fetched: 2', 'Inserted: 1', 'Skipped duplicates: 1');
    $original = BogTransaction::sole();
    $original->update(['status' => 'reviewed']);
    expect(Artisan::call('bog:sync'))->toBe(0);
    expect(Artisan::output())->toContain('Inserted: 0', 'Skipped duplicates: 1');
    expect(BogTransaction::count())->toBe(1)->and($original->fresh()->debit)->toBe('103.00')
        ->and($original->fresh()->status)->toBe('reviewed')->and($original->fresh()->raw_payload)->toBe($record);
    expect(fn () => BogTransaction::create($original->getAttributes()))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('BOG sync preserves debit-only and credit-only amounts without reusing another amount column', function () {
    fakeBogSyncRecords([
        ['EntryId' => 'credit', 'OperationDate' => '2026-09-14', 'Debit' => null, 'Credit' => '147.90', 'Amount' => -999],
        ['EntryId' => 'debit', 'OperationDate' => '2026-09-15', 'Debit' => 103, 'Credit' => '', 'Amount' => 999],
        ['entry_id' => 'fee', 'operation_date' => '2026-09-16', 'debit' => '1.00', 'credit' => null],
    ]);
    expect(Artisan::call('bog:sync'))->toBe(0);
    $rows = BogTransaction::all()->keyBy('entry_id');
    expect($rows['credit']->debit)->toBe('0.00')->and($rows['credit']->credit)->toBe('147.90')
        ->and($rows['debit']->debit)->toBe('103.00')->and($rows['debit']->credit)->toBe('0.00')
        ->and($rows['fee']->debit)->toBe('1.00')->and($rows['fee']->credit)->toBe('0.00')
        ->and($rows['fee']->value_date)->toBeNull();
});

test('actual BOG statement fixture imports all 11 records with eight credits and three debits', function () {
    $records = json_decode(file_get_contents(base_path('tests/Fixtures/bog-api-statement.json')), true, flags: JSON_THROW_ON_ERROR);
    fakeBogSyncRecords($records);
    expect(Artisan::call('bog:sync', ['startDate' => '2026-09-15', 'endDate' => '2026-09-16']))->toBe(0);
    expect(Artisan::output())->toContain('Fetched: 11', 'Inserted: 11', 'Skipped duplicates: 0', 'Errors: 0');
    expect(BogTransaction::where('credit', '>', 0)->count())->toBe(8)
        ->and(BogTransaction::where('debit', '>', 0)->count())->toBe(3)
        ->and((float) BogTransaction::sum('debit'))->toBe(23.0)
        ->and(round((float) BogTransaction::sum('credit'), 2))->toBe(3489.05);
    foreach ($records as $record) {
        $row = BogTransaction::where('entry_id', (string) $record['entryId'])->sole();
        expect($row->raw_payload)->toBe($record)
            ->and((float) $row->debit)->toBe((float) $record['entryAmountDebit'])
            ->and((float) $row->credit)->toBe((float) $record['entryAmountCredit'])
            ->and($row->operation_type)->toBe($record['documentProductGroup'])
            ->and($row->description)->toBe($record['documentNomination']);
    }
    expect(Artisan::call('bog:sync'))->toBe(0);
    expect(Artisan::output())->toContain('Inserted: 0', 'Skipped duplicates: 11');
});

test('signed BOG entryAmount supplies direction when separate amounts are absent', function () {
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/bog-api-statement.json')), true, flags: JSON_THROW_ON_ERROR);
    $records = [$fixture[0], $fixture[3]];
    foreach ($records as &$record) {
        unset($record['entryAmountDebit'], $record['entryAmountCredit']);
        $record['senderDetails']['name'] = 'Incoming sender';
        $record['beneficiaryDetails']['name'] = 'Outgoing beneficiary';
        // These are conversion/base amounts, not account-currency transaction amounts.
        $record['entryAmountBase'] = 99999;
        $record['documentSourceAmount'] = 88888;
    }
    unset($record);
    fakeBogSyncRecords($records);
    expect(Artisan::call('bog:sync'))->toBe(0);
    $incoming = BogTransaction::where('entry_id', $records[0]['entryId'])->sole();
    $outgoing = BogTransaction::where('entry_id', $records[1]['entryId'])->sole();
    expect($incoming->credit)->toBe('171.67')->and($incoming->debit)->toBe('0.00')
        ->and($incoming->counterparty_name)->toBe('Incoming sender')->and($incoming->raw_payload)->toBe($records[0])
        ->and($outgoing->debit)->toBe('2.00')->and($outgoing->credit)->toBe('0.00')
        ->and($outgoing->counterparty_name)->toBe('Outgoing beneficiary')->and($outgoing->raw_payload)->toBe($records[1]);
});

test('invalid actual BOG amount fields are diagnosed without exposing tokens or arbitrary strings', function () {
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/bog-api-statement.json')), true, flags: JSON_THROW_ON_ERROR);
    $invalid = $fixture[0];
    $invalid['entryAmountCredit'] = 'fake-access-token';
    $invalid['access_token'] = 'hidden-token';
    $ambiguous = $fixture[3];
    $ambiguous['entryAmountCredit'] = 5;
    fakeBogSyncRecords([$invalid, $ambiguous]);
    expect(Artisan::call('bog:sync'))->toBe(1);
    expect(Artisan::output())->toContain('Inserted: 0', 'Errors: 2', 'entryAmountCredit', '[invalid string]', 'entryAmountDebit', 'documentProductGroup', 'COM')
        ->not->toContain('fake-access-token', 'hidden-token', 'fake-secret');
    expect(BogTransaction::count())->toBe(0);
});
