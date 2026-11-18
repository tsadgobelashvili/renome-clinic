<?php

/**
 * Standalone synthetic PostgreSQL concurrency regression; never loads .env or production credentials.
 * Uses the same guarded disposable cluster contract as israeli-payment-postgres.php (port 55448,
 * database renome_payment_concurrency). Set its ISRAELI_PAYMENT_* test environment flags first.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/IsraeliPaymentPostgresEnvironment.php';

use App\Models\IsraeliSalaryPayoutCarryEntry;
use App\Models\SalarySettlement;
use App\Services\IsraeliPatientPaymentLifecycle;
use App\Services\IsraeliSalaryPayoutCarry;
use App\Services\IsraeliSalaryPayoutService;
use App\Services\PartnerFinanceSummary;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function roundedPgWait(callable $predicate, string $label): mixed
{
    $until = microtime(true) + 12;
    do {
        if ($result = $predicate()) { return $result; }
        usleep(10000);
    } while (microtime(true) < $until);
    throw new RuntimeException('Timed out: '.$label);
}

function roundedPgOperation(array $job): void
{
    if ($job['operation'] === 'cancel') {
        app(IsraeliPatientPaymentLifecycle::class)->cancel(1, ['expected_version' => 0,
            'operation_key' => $job['key'], 'reason' => 'Synthetic concurrent cancellation']);
    } else {
        app(IsraeliSalaryPayoutService::class)->payRemaining($job['settlement'], [['source' => 'israeli',
            'currency' => $job['currency'], 'amount' => $job['amount'], 'exchange_rate' => $job['currency'] === 'USD' ? 2.5 : null]],
            $job['key'], auth()->user());
    }
}

if (($argv[1] ?? null) === 'worker') {
    $job = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    $events = israeliPostgresEnvironment($job['schema']);
    $pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    file_put_contents($job['pid_file'], (string) $pid);
    $hold = function () use ($job): void {
        file_put_contents($job['held_file'], 'held');
        roundedPgWait(fn () => file_exists($job['release_file']), 'release outer transaction');
    };
    if ($job['fail_carry'] ?? false) {
        $events->listen('eloquent.creating: '.IsraeliSalaryPayoutCarryEntry::class, function () use ($hold): void {
            $hold();
            throw new RuntimeException('Synthetic carry failure');
        });
    }
    try {
        DB::transaction(function () use ($job, $hold): void {
            roundedPgOperation($job);
            if ($job['hold'] && ! ($job['fail_carry'] ?? false)) { $hold(); }
        });
        $result = ['status' => 'committed', 'pid' => $pid];
    } catch (ValidationException $exception) {
        $result = ['status' => 'rejected', 'pid' => $pid, 'errors' => $exception->errors()];
    } catch (Throwable $exception) {
        $result = ['status' => $exception->getMessage() === 'Synthetic carry failure' ? 'rolled_back' : 'error',
            'pid' => $pid, 'class' => $exception::class, 'message' => $exception->getMessage()];
    }
    file_put_contents($job['result_file'], json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    exit($result['status'] === 'error' ? 1 : 0);
}

function roundedPgSalarySchema(): void
{
    Schema::create('doctors', function (Blueprint $table): void {
        $table->id(); $table->string('first_name'); $table->string('last_name'); $table->string('specialty')->nullable();
    });
    Schema::create('salary_settlements', function (Blueprint $table): void {
        $table->id(); $table->foreignId('doctor_id')->constrained(); $table->string('patient_group_slug');
        $table->string('currency', 3); $table->decimal('salary_total', 14, 2); $table->decimal('salary_advance_applied', 14, 2)->default(0);
        $table->boolean('uses_allocations')->default(true); $table->string('status'); $table->string('israeli_payment_method');
        $table->date('period_start'); $table->date('period_end'); $table->timestamp('settled_at'); $table->timestamps();
    });
    Schema::create('salary_settlement_items', function (Blueprint $table): void {
        $table->id(); $table->unsignedBigInteger('salary_settlement_id'); $table->unsignedBigInteger('manual_lab_case_id')->nullable();
    });
    Schema::create('salary_payouts', function (Blueprint $table): void {
        $table->id(); $table->foreignId('salary_settlement_id')->constrained()->restrictOnDelete();
        $table->uuid('request_key')->unique(); $table->string('request_hash', 64); $table->decimal('total_gel', 14, 2);
        $table->decimal('external_deduction_gel', 14, 2)->default(0); $table->json('composition_snapshot')->nullable();
        $table->foreignId('created_by')->nullable()->constrained('users'); $table->timestamps();
    });
    Schema::create('salary_payout_allocations', function (Blueprint $table): void {
        $table->id(); $table->foreignId('salary_payout_id')->constrained()->restrictOnDelete();
        $table->string('source'); $table->string('currency', 3); $table->decimal('amount', 14, 2);
        $table->decimal('exchange_rate', 14, 6)->nullable(); $table->decimal('gel_equivalent', 14, 2); $table->timestamps();
    });
    Schema::table('partner_finance_transactions', function (Blueprint $table): void {
        $table->foreignId('salary_payout_allocation_id')->nullable()->unique()->constrained()->restrictOnDelete();
        $table->unsignedBigInteger('doctor_id')->nullable();
    });
    foreach ([1, 2] as $id) { DB::table('doctors')->insert(['id' => $id, 'first_name' => 'Synthetic', 'last_name' => 'Doctor']); }
}

function roundedPgSettlement(int $doctor, float $gel, string $method): int
{
    return DB::table('salary_settlements')->insertGetId(['doctor_id' => $doctor, 'patient_group_slug' => 'israel-partner',
        'currency' => 'GEL', 'salary_total' => $gel, 'status' => 'confirmed', 'israeli_payment_method' => $method,
        'period_start' => '2025-01-01', 'period_end' => '2025-01-31', 'settled_at' => '2025-01-31',
        'created_at' => '2025-01-31', 'updated_at' => '2025-01-31']);
}

$output = getenv('ISRAELI_PAYMENT_PG_OUTPUT');
if (! $output || ! is_dir($output) || file_exists($output.'/salary-results.json')) { throw new RuntimeException('Requires a new test output directory'); }
$checks = 0;
$check = function (bool $condition, string $message) use (&$checks): void {
    if (! $condition) { throw new RuntimeException($message); }
    $checks++;
};
$results = [];
foreach (['cash shortfall', 'bank shortfall', 'same recipient credit', 'duplicate submission', 'carry rollback', 'patient cancellation', 'mixed currencies'] as $name) {
    $schema = 'isr_lock_'.bin2hex(random_bytes(6));
    israeliPostgresEnvironment($schema);
    $bank = $name === 'bank shortfall';
    $receipts = [['amount' => $name === 'same recipient credit' ? 7000 : 3000, 'payment_method' => $bank ? 'card' : 'cash']];
    if ($name === 'mixed currencies') { $receipts[] = ['amount' => 3000, 'currency' => 'GEL']; }
    israeliPostgresFixtures($schema, $receipts, []);
    roundedPgSalarySchema();
    // Verify migration preservation using an old-shaped payout before adding any new columns.
    $oldSettlement = roundedPgSettlement(2, 123.45, 'cash');
    $oldId = DB::table('salary_payouts')->insertGetId(['salary_settlement_id' => $oldSettlement,
        'request_key' => (string) Str::uuid(), 'request_hash' => str_repeat('a', 64), 'total_gel' => 123.45,
        'created_at' => '2025-01-31', 'updated_at' => '2025-01-31']);
    $oldColumns = Schema::getColumnListing('salary_payouts');
    $old = json_encode(DB::table('salary_payouts')->select($oldColumns)->find($oldId));
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_17_150000_add_rounded_israeli_salary_payout_carry.php';
    DB::transaction(fn () => $migration->up());
    $check(json_encode(DB::table('salary_payouts')->select($oldColumns)->find($oldId)) === $old, 'Migration changed historical payout');
    $check(DB::table('salary_payouts')->find($oldId)->rounding_snapshot === null, 'Historical payout was backfilled');
    $method = $bank ? 'bank_transfer' : 'cash';
    $firstId = roundedPgSettlement(1, 4995, $method);
    $secondId = roundedPgSettlement(2, 4995, $method);
    $a = ['operation' => 'payout', 'settlement' => $firstId, 'amount' => 2000, 'currency' => 'USD', 'key' => (string) Str::uuid()];
    $b = ['operation' => 'payout', 'settlement' => $secondId, 'amount' => 2000, 'currency' => 'USD', 'key' => (string) Str::uuid()];
    $expected = ['committed', 'rejected'];
    $expectedNewPayouts = 1;
    $expectedMovements = 1;
    if ($name === 'same recipient credit') {
        app(IsraeliSalaryPayoutService::class)->payRemaining($firstId, [['source' => 'israeli', 'currency' => 'USD', 'amount' => 2000, 'exchange_rate' => 2.5]], (string) Str::uuid(), auth()->user());
        $a['settlement'] = roundedPgSettlement(1, 3750, 'cash'); $a['amount'] = 1498;
        $b['settlement'] = roundedPgSettlement(1, 3750, 'cash'); $b['amount'] = 1498;
        $expected = ['committed', 'committed']; $expectedNewPayouts = 3; $expectedMovements = 3;
    } elseif ($name === 'duplicate submission') { $b = $a; $expected = ['committed', 'committed']; }
    elseif ($name === 'carry rollback') { $expected = ['rolled_back', 'committed']; }
    elseif ($name === 'patient cancellation') { $b = ['operation' => 'cancel', 'key' => (string) Str::uuid()]; }
    elseif ($name === 'mixed currencies') {
        $b['settlement'] = roundedPgSettlement(2, 1998, 'cash'); $b['currency'] = 'GEL';
        $expected = ['committed', 'committed']; $expectedNewPayouts = 2; $expectedMovements = 2;
    }
    $directory = $output.'/'.$schema;
    mkdir($directory);
    $jobs = $processes = [];
    foreach (['a' => $a, 'b' => $b] as $letter => $operation) {
        $job = [...$operation, 'schema' => $schema, 'hold' => $letter === 'a', 'fail_carry' => $letter === 'a' && $name === 'carry rollback',
            'held_file' => $directory.'/held', 'release_file' => $directory.'/release', 'pid_file' => $directory.'/'.$letter.'.pid',
            'result_file' => $directory.'/'.$letter.'.result.json'];
        $jobFile = $directory.'/'.$letter.'.job.json';
        file_put_contents($jobFile, json_encode($job, JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $jobFile], [0 => ['pipe', 'r'],
            1 => ['file', $directory.'/'.$letter.'.stdout', 'w'], 2 => ['file', $directory.'/'.$letter.'.stderr', 'w']], $pipes);
        if (! is_resource($process)) { throw new RuntimeException('Cannot launch independent PostgreSQL worker'); }
        fclose($pipes[0]); $jobs[$letter] = $job; $processes[$letter] = $process;
        if ($letter === 'a') {
            roundedPgWait(fn () => file_exists($directory.'/held') || file_exists($job['result_file']), 'first payout holds outer transaction');
            $check(file_exists($directory.'/held'), 'First worker failed before barrier: '.@file_get_contents($job['result_file']));
        }
    }
    try {
        $pidB = (int) roundedPgWait(fn () => file_exists($jobs['b']['pid_file']) ? file_get_contents($jobs['b']['pid_file']) : false, 'second session PID');
        $pidA = (int) file_get_contents($jobs['a']['pid_file']);
        $check($pidA !== $pidB, 'Workers share a PostgreSQL session');
        roundedPgWait(fn () => DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS blocked', [$pidA, $pidB])->blocked, 'shared balance lock wait');
        $check(! file_exists($jobs['b']['result_file']), 'Second worker bypassed balance lock');
    } finally { file_put_contents($directory.'/release', 'release'); }
    $pair = [];
    foreach (['a', 'b'] as $letter) {
        roundedPgWait(fn () => file_exists($jobs[$letter]['result_file']), 'worker completes');
        $pair[] = json_decode(file_get_contents($jobs[$letter]['result_file']), true, flags: JSON_THROW_ON_ERROR);
        $check(proc_close($processes[$letter]) === 0, json_encode(end($pair)));
    }
    $check(array_column($pair, 'status') === $expected, $name.' unexpected results: '.json_encode($pair));
    $check(DB::table('salary_payouts')->count() === $expectedNewPayouts + 1, 'Duplicate/partial payout');
    $check(DB::table('partner_finance_transactions')->count() === $expectedMovements, 'Duplicate/partial financial movement');
    $check(DB::table('salary_payout_allocations')->count() === $expectedMovements, 'Duplicate/partial allocation');
    $balances = app(PartnerFinanceSummary::class)->accountBalances();
    foreach ($balances as $currencies) { foreach ($currencies as $amount) { $check($amount >= 0, 'Concurrent payout produced a negative balance'); } }
    $check($balances[$bank ? 'bank' : 'cash']['USD'] === ($name === 'same recipient credit' ? 2004.0 : 1000.0), 'Israeli account was not reduced by the exact actual USD payouts');
    if ($name === 'mixed currencies') { $check($balances['cash']['GEL'] === 1000.0, 'Wrong GEL financial effect'); }
    if ($name === 'same recipient credit') {
        $check((float) DB::table('israeli_salary_payout_carry_entries')->where('kind', 'applied')->sum('amount_gel') === -5.0, 'Credit applied twice');
        $check(app(IsraeliSalaryPayoutCarry::class)->balances(1)['GEL'] === 0.0, 'Wrong remaining credit');
        $check(app(IsraeliSalaryPayoutService::class)->remaining(SalarySettlement::find($b['settlement'])) === 5.0, 'Stale credit preview erased outstanding salary');
    } else {
        $check(DB::table('israeli_salary_payout_carry_entries')->count() === $expectedNewPayouts, 'Duplicate/partial credit');
        $check((float) DB::table('israeli_salary_payout_carry_entries')->sum('amount_gel') === ($name === 'mixed currencies' ? 7.0 : 5.0), 'Incorrect credit amount');
    }
    $check(json_encode(DB::table('salary_payouts')->select($oldColumns)->find($oldId)) === $old, 'Historical payout changed');
    $migration->down();
    $check(Schema::hasTable('israeli_salary_payout_carry_entries'), 'Rollback discarded credit history');
    $results[] = ['case' => $name, 'passed' => true, 'independent_pids' => [$pidA, $pidB], 'lock_wait_verified' => true,
        'statuses' => array_column($pair, 'status'), 'balances' => $balances];
    echo 'PASS '.$name."\n";
}
file_put_contents($output.'/salary-results.json', json_encode(['cases' => count($results), 'checks' => $checks, 'passed' => true, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo count($results)." real PostgreSQL rounding cases passed; {$checks} checks.\n";
