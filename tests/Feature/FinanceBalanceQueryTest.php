<?php

use App\Models\Patient;
use App\Services\FinanceUsdUsageService;
use App\Support\CashboxManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('grouped balances preserve account legs currencies and query counts with populated history', function (int $copies) {
    $this->mock(CashboxManager::class, function ($mock) {
        $mock->shouldReceive('cashCutoverDate')->andReturn('2026-09-12');
        $mock->shouldReceive('physicalCashBalances')->andReturn(['GEL' => 1000.25, 'USD' => 1000.25]);
    });
    $patient = Patient::create(['first_name' => 'Balance', 'last_name' => 'Test']);
    foreach (['GEL', 'USD'] as $currency) {
        foreach (['cash' => 1000.25, 'card' => 400.10] as $method => $amount) {
            DB::table('partner_patient_payments')->insert([
                'patient_id' => $patient->id, 'currency' => $currency, 'amount' => $amount,
                'payment_method' => $method, 'paid_at' => '2026-09-14',
            ]);
        }
        foreach (['income' => [1000.25, 2.22], 'expense' => [10.11, 3.33]] as $type => [$amount, $funding]) {
            DB::table('finance_transactions')->insert([
                'type' => $type, 'transaction_date' => '2026-09-14', 'currency' => $currency,
                'amount' => $amount, 'israeli_cash_gel' => $funding, 'category' => 'other', 'payment_method' => 'bank_transfer',
            ]);
        }
    }
    $rows = [];
    foreach (['israeli', 'clinic'] as $source) {
        $base = [
            'source' => $source, 'type' => null, 'transacted_at' => '2026-09-14',
            'currency' => null, 'amount' => null, 'from_account' => null, 'to_account' => null,
            'from_currency' => null, 'to_currency' => null, 'from_amount' => null, 'to_amount' => null,
        ];
        for ($i = 0; $i < $copies; $i++) {
            foreach (['GEL', 'USD'] as $currency) {
                foreach ([
                    ['expense', 'cash', null, 10.11], ['expense', 'bank', null, 20.22],
                    ['transfer', 'cash', 'bank', 30.33], ['transfer', 'bank', 'cash', 40.44],
                    ['transfer', 'cash', 'cash', 5.55], ['transfer', 'bank', 'bank', 6.66],
                    ['owner_withdrawal', 'cash', null, 7.77], ['owner_withdrawal', 'bank', null, 8.88],
                ] as [$type, $from, $to, $amount]) {
                    $rows[] = [...$base, 'type' => $type, 'currency' => $currency, 'amount' => $amount,
                        'from_account' => $from, 'to_account' => $to];
                }
            }
            foreach ([
                ['cash', 'cash', 11.11, 30], ['bank', 'cash', 2.22, 6],
                ['cash', 'bank', 3.33, 9], ['bank', 'bank', 4.44, 12],
            ] as [$from, $to, $usd, $gel]) {
                $rows[] = [...$base, 'type' => 'currency_exchange', 'from_account' => $from, 'to_account' => $to,
                    'from_currency' => 'USD', 'to_currency' => 'GEL', 'from_amount' => $usd, 'to_amount' => $gel];
            }
            foreach ([['bank', 'cash', 9.99], ['cash', 'bank', 2.22], ['cash', 'cash', 1.11], ['bank', 'bank', 3.33]] as [$from, $to, $amount]) {
                $rows[] = [...$base, 'type' => 'salary_cash', 'currency' => 'GEL', 'amount' => $amount,
                    'from_account' => $from, 'to_account' => $to];
            }
        }
        // Only Clinic physical cash honors the cutover. General balances retain all history.
        $rows[] = [...$base, 'type' => 'owner_withdrawal', 'currency' => 'GEL', 'amount' => 5,
            'from_account' => 'cash', 'transacted_at' => '2026-09-10'];
    }
    foreach (array_chunk($rows, 200) as $chunk) {
        DB::table('partner_finance_transactions')->insert($chunk);
    }
    $expected = [
        ['balances', 'israeli', 2, [1395.35 - 65.19 * $copies, 1400.35 - 151.06 * $copies]],
        ['balances', 'clinic', 4, [987.36 - 42.63 * $copies, 990.14 - 120.73 * $copies]],
        ['cashBalances', 'israeli', 2, [995.25 + 36 * $copies, 1000.25 - 22.21 * $copies]],
        ['cashBalances', 'clinic', 1, [1000.25 + 38.34 * $copies, 1000.25 - 12.10 * $copies]],
    ];
    foreach ($expected as [$method, $source, $count, [$gel, $usd]]) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $balances = app(FinanceUsdUsageService::class)->{$method}($source);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
        }
        expect($balances)->toBe(['GEL' => round($gel, 2), 'USD' => round($usd, 2)])
            ->and(count($queries))->toBe($count);
        foreach ($queries as $query) {
            expect(strtolower($query['query']))->toContain('sum(')->toContain('group by');
        }
    }
})->with([1, 40]);

test('finance balance query budget is independent of currencies and transaction count', function (string $method, string $source, int $expected) {
    $this->mock(CashboxManager::class, function ($mock) {
        $mock->shouldReceive('cashCutoverDate')->andReturn(null);
        $mock->shouldReceive('physicalCashBalances')->andReturn(['GEL' => 0.0, 'USD' => 0.0]);
    });
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $balances = app(FinanceUsdUsageService::class)->{$method}($source);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }
    expect($balances)->toBe(['GEL' => 0.0, 'USD' => 0.0])
        ->and(count($queries))->toBe($expected);
})->with([
    'Israeli balance' => ['balances', 'israeli', 2],
    'Clinic balance' => ['balances', 'clinic', 4],
    'Israeli cash' => ['cashBalances', 'israeli', 2],
    'Clinic cash excluding CashboxManager internals' => ['cashBalances', 'clinic', 1],
]);
