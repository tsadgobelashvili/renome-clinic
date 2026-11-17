<?php

/**
 * Real multi-process/connection regression, separate from the SQLite-only PHPUnit environment.
 * Run only against a NEW disposable PostgreSQL cluster on 127.0.0.1:55448, database
 * renome_payment_concurrency, role codex_payment_test. No .env or production credentials are loaded.
 * Set ISRAELI_PAYMENT_DISPOSABLE_PG=yes, ISRAELI_PAYMENT_PG_DATA_DIRECTORY to that cluster's
 * data directory, and ISRAELI_PAYMENT_PG_OUTPUT to a new workspace verification directory.
 */
require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/IsraeliPaymentPostgresEnvironment.php';

use App\Models\PartnerPaymentAudit;
use App\Services\FinanceUsdUsageService;
use App\Services\IsraeliPatientPaymentLifecycle;
use App\Services\PartnerFinanceSummary;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function pgPaymentWait(callable $condition, string $label): mixed
{
    $deadline = microtime(true) + 12;
    do {
        if ($result = $condition()) { return $result; }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out: '.$label);
}

function pgPaymentOperation(array $job): void
{
    $operation = $job['operation'];
    if (in_array($operation, ['update', 'cancel', 'restore'], true)) {
        app(IsraeliPatientPaymentLifecycle::class)->{$operation}($job['id'], $job['data']);
    } elseif ($operation === 'expense') {
        app(FinanceUsdUsageService::class)->recordIsraeliCashExpense($job['data']);
    } elseif ($operation === 'transfer') {
        app(FinanceUsdUsageService::class)->transfer($job['data']);
    } elseif ($operation === 'exchange') {
        app(FinanceUsdUsageService::class)->record($job['data']);
    } else {
        throw new RuntimeException('Unknown operation');
    }
}

if (($argv[1] ?? null) === 'worker') {
    $job = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    $events = israeliPostgresEnvironment($job['schema']);
    $pid = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    file_put_contents($job['pid_file'], (string) $pid);
    $hold = function () use ($job): void {
        file_put_contents($job['held_file'], 'held');
        pgPaymentWait(fn () => file_exists($job['release_file']), 'controller releases transaction');
    };
    if ($job['hold'] ?? false) {
        if ($job['audit_failure'] ?? false) {
            $events->listen('eloquent.creating: '.PartnerPaymentAudit::class, function () use ($hold): void {
                $hold();
                throw new RuntimeException('Synthetic audit failure');
            });
        } elseif (! ($job['hold_after_nested'] ?? false)) {
            $events->listen(QueryExecuted::class, function (QueryExecuted $query) use ($hold): void {
                if (str_starts_with($query->sql, 'insert into "partner_payment_audits"')) { $hold(); }
            });
        }
    }
    try {
        // Outer transaction proves the shared lock survives nested lifecycle/savepoint completion.
        DB::transaction(function () use ($job, $hold): void {
            if ($job['repeatable_read'] ?? false) { DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); }
            pgPaymentOperation($job);
            if ($job['hold_after_nested'] ?? false) { $hold(); }
        });
        $result = ['status' => 'committed', 'pid' => $pid];
    } catch (ValidationException $exception) {
        $result = ['status' => 'rejected', 'pid' => $pid, 'errors' => $exception->errors()];
    } catch (Throwable $exception) {
        $result = ['status' => ($job['audit_failure'] ?? false) && $exception->getMessage() === 'Synthetic audit failure'
            ? 'rolled_back' : 'error', 'pid' => $pid, 'class' => $exception::class, 'message' => $exception->getMessage()];
    }
    file_put_contents($job['result_file'], json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    exit($result['status'] === 'error' ? 1 : 0);
}

$output = getenv('ISRAELI_PAYMENT_PG_OUTPUT');
if (! $output || ! is_dir($output) || file_exists($output.'/results.json')) {
    throw new RuntimeException('Requires a new existing output directory; refuses to overwrite results.');
}
$assertions = 0;
$check = function (bool $condition, string $message) use (&$assertions): void {
    if (! $condition) { throw new RuntimeException($message); }
    $assertions++;
};
$receipt = fn (array $extra = []) => array_replace(['amount' => 100], $extra);
$mutation = fn (string $operation, int $id, array $extra = [], int $version = 0) => ['operation' => $operation, 'id' => $id,
    'data' => array_replace(['expected_version' => $version, 'operation_key' => (string) Str::uuid(), 'reason' => 'Synthetic correction'], $extra)];
$cases = [];
$add = function (string $name, array $receipts, array $expenses, array $a, array $b, array $expected, array $options = []) use (&$cases): void {
    $cases[] = compact('name', 'receipts', 'expenses', 'a', 'b', 'expected', 'options');
};
$cashExpense = [['amount' => 50]];
$add('two different cancellations', [$receipt(), $receipt()], $cashExpense,
    $mutation('cancel', 1), $mutation('cancel', 2), ['statuses' => ['committed', 'rejected'], 'cash_usd' => 50, 'audits' => 1], ['hold_after_nested' => true]);
$add('edit then cancellation', [$receipt(), $receipt()], $cashExpense,
    $mutation('update', 1, ['amount' => 10, 'patient_id' => 54]), $mutation('cancel', 2), ['statuses' => ['committed', 'rejected'], 'cash_usd' => 60, 'audits' => 1]);
$add('cancellation then edit', [$receipt(), $receipt()], $cashExpense,
    $mutation('cancel', 1), $mutation('update', 2, ['amount' => 10]), ['statuses' => ['committed', 'rejected'], 'cash_usd' => 50, 'audits' => 1]);
$add('restoration then cancellation', [$receipt(['cancelled' => true]), $receipt()], $cashExpense,
    $mutation('restore', 1, [], 1), $mutation('cancel', 2), ['statuses' => ['committed', 'committed'], 'cash_usd' => 50, 'audits' => 3]);
$add('cancellation then restoration', [$receipt(), $receipt(['cancelled' => true])], [],
    $mutation('cancel', 1), $mutation('restore', 2, [], 1), ['statuses' => ['committed', 'committed'], 'cash_usd' => 100, 'audits' => 3]);
$add('USD to GEL then cancellation', [$receipt(), $receipt()], $cashExpense,
    $mutation('update', 1, ['currency' => 'GEL']), $mutation('cancel', 2), ['statuses' => ['committed', 'rejected'], 'cash_usd' => 50, 'cash_gel' => 100, 'audits' => 1]);
$add('GEL to USD then cancellation', [$receipt(['currency' => 'GEL']), $receipt(['currency' => 'GEL'])], [['currency' => 'GEL', 'amount' => 50]],
    $mutation('update', 1, ['currency' => 'USD']), $mutation('cancel', 2), ['statuses' => ['committed', 'rejected'], 'cash_gel' => 50, 'cash_usd' => 100, 'audits' => 1]);
$add('currency changes cannot both spend USD', [$receipt(), $receipt()], $cashExpense,
    $mutation('update', 1, ['currency' => 'GEL']), $mutation('update', 2, ['currency' => 'GEL']), ['statuses' => ['committed', 'rejected'], 'cash_usd' => 50, 'cash_gel' => 100, 'audits' => 1]);
$add('different accounts', [$receipt(), $receipt(['payment_method' => 'card'])], [],
    $mutation('cancel', 1), $mutation('cancel', 2), ['statuses' => ['committed', 'committed'], 'cash_usd' => 0, 'bank_usd' => 0, 'audits' => 2]);
$add('opposite account changes', [$receipt(), $receipt(['payment_method' => 'card'])], [],
    $mutation('update', 1, ['payment_method' => 'card', 'account' => 'bank']), $mutation('update', 2, ['payment_method' => 'cash', 'account' => 'cash']),
    ['statuses' => ['committed', 'committed'], 'cash_usd' => 100, 'bank_usd' => 100, 'audits' => 2]);
$add('account change then cancellation', [$receipt(), $receipt()], $cashExpense,
    $mutation('update', 1, ['payment_method' => 'bank_transfer', 'account' => 'bank']), $mutation('cancel', 2),
    ['statuses' => ['committed', 'rejected'], 'cash_usd' => 50, 'bank_usd' => 100, 'audits' => 1]);
$retry = $mutation('cancel', 1);
$add('duplicate cancellation submission', [$receipt(), $receipt()], $cashExpense, $retry, $retry,
    ['statuses' => ['committed', 'committed'], 'cash_usd' => 50, 'audits' => 1]);
$add('stale editors on one receipt', [$receipt()], [], $mutation('update', 1, ['amount' => 80]), $mutation('update', 1, ['amount' => 60]),
    ['statuses' => ['committed', 'rejected'], 'cash_usd' => 80, 'audits' => 1]);
$add('audit failure releases lock and rolls back everything', [$receipt(), $receipt()], $cashExpense,
    $mutation('cancel', 1), $mutation('cancel', 2), ['statuses' => ['rolled_back', 'committed'], 'cash_usd' => 50, 'audits' => 1], ['audit_failure' => true]);
$add('cash expense shares the balance lock', [$receipt(), $receipt()], $cashExpense, $mutation('cancel', 1),
    ['operation' => 'expense', 'data' => ['amount' => 75, 'currency' => 'USD', 'category' => 'other_expense', 'transaction_date' => '2025-01-01']],
    ['statuses' => ['committed', 'rejected'], 'cash_usd' => 50, 'audits' => 1]);
$add('transfer shares the balance lock', [$receipt(), $receipt()], $cashExpense, $mutation('cancel', 1),
    ['operation' => 'transfer', 'data' => ['source' => 'israeli', 'amount' => 75, 'currency' => 'USD', 'category' => 'bank_deposit', 'transacted_at' => '2025-01-01']],
    ['statuses' => ['committed', 'rejected'], 'cash_usd' => 50, 'bank_usd' => 0, 'audits' => 1]);
foreach ([75 => 'rejected', 40 => 'committed'] as $amount => $status) {
    $add('exchange shares lock '.$amount, [$receipt(), $receipt()], $cashExpense, $mutation('cancel', 1),
        ['operation' => 'exchange', 'data' => ['source' => 'israeli', 'usage_type' => 'exchange_only', 'usd_amount' => $amount,
            'received_gel_amount' => $amount * 2.7, 'exchange_rate' => 2.7, 'transacted_at' => '2025-01-01']],
        ['statuses' => ['committed', $status], 'cash_usd' => $status === 'committed' ? 10 : 50,
            'cash_gel' => $status === 'committed' ? 108 : 0, 'audits' => 1, 'extra_movements' => $status === 'committed' ? 1 : 0]);
}

$results = [];
foreach ($cases as $case) {
    $schema = 'isr_lock_'.bin2hex(random_bytes(6));
    israeliPostgresEnvironment($schema);
    israeliPostgresFixtures($schema, $case['receipts'], $case['expenses']);
    $original = DB::table('partner_patient_payments')->orderBy('id')->get()->keyBy('id');
    $priorAudits = DB::table('partner_payment_audits')->orderBy('id')->get()->toJson();
    $seedAuditCount = DB::table('partner_payment_audits')->count();
    $directory = $output.'/'.$schema;
    mkdir($directory);
    $jobs = $processes = [];
    $release = $directory.'/release';
    $launch = function (string $letter, array $operation) use ($directory, $schema, $release, $case, &$jobs, &$processes): void {
        $job = [...$operation, 'schema' => $schema, 'hold' => $letter === 'a',
            'audit_failure' => $letter === 'a' && ($case['options']['audit_failure'] ?? false),
            'hold_after_nested' => $letter === 'a' && ($case['options']['hold_after_nested'] ?? false),
            'held_file' => $directory.'/held', 'release_file' => $release, 'pid_file' => $directory.'/'.$letter.'.pid',
            'result_file' => $directory.'/'.$letter.'.result.json'];
        $file = $directory.'/'.$letter.'.job.json';
        file_put_contents($file, json_encode($job, JSON_THROW_ON_ERROR));
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $file],
            [0 => ['pipe', 'r'], 1 => ['file', $directory.'/'.$letter.'.stdout', 'w'], 2 => ['file', $directory.'/'.$letter.'.stderr', 'w']], $pipes);
        if (! is_resource($process)) { throw new RuntimeException('Could not start PostgreSQL worker'); }
        fclose($pipes[0]);
        $jobs[$letter] = $job;
        $processes[$letter] = $process;
    };
    try {
        $launch('a', $case['a']);
        pgPaymentWait(fn () => file_exists($directory.'/held') || file_exists($jobs['a']['result_file']), 'first worker holds transaction');
        $check(file_exists($directory.'/held'), $case['name'].': first worker failed before concurrency barrier');
        $launch('b', $case['b']);
        $pidB = (int) pgPaymentWait(fn () => file_exists($jobs['b']['pid_file']) ? file_get_contents($jobs['b']['pid_file']) : false, 'second backend PID');
        $pidA = (int) file_get_contents($jobs['a']['pid_file']);
        $check($pidA !== $pidB, 'Workers must have independent PostgreSQL sessions');
        pgPaymentWait(fn () => DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS blocked', [$pidA, $pidB])->blocked,
            'second session waits for the shared row lock');
        $check(! file_exists($jobs['b']['result_file']), 'Second operation bypassed the shared lock');
    } finally {
        file_put_contents($release, 'release');
    }
    $pair = [];
    foreach (['a', 'b'] as $letter) {
        pgPaymentWait(fn () => file_exists($jobs[$letter]['result_file']), 'worker '.$letter.' completes');
        $pair[$letter] = json_decode(file_get_contents($jobs[$letter]['result_file']), true, flags: JSON_THROW_ON_ERROR);
        $check(proc_close($processes[$letter]) === 0, $case['name'].': worker error '.json_encode($pair[$letter]));
    }
    $check(array_column($pair, 'status') === $case['expected']['statuses'], $case['name'].': unexpected statuses '.json_encode($pair));
    $balances = app(PartnerFinanceSummary::class)->accountBalances();
    $cash = app(FinanceUsdUsageService::class)->cashBalances('israeli');
    foreach ($balances as $currencies) { foreach ($currencies as $amount) { $check($amount >= 0, $case['name'].': negative account balance'); } }
    foreach ($cash as $amount) { $check($amount >= 0, $case['name'].': negative available cash'); }
    foreach (['cash_usd' => ['cash', 'USD'], 'cash_gel' => ['cash', 'GEL'], 'bank_usd' => ['bank', 'USD']] as $key => [$account, $currency]) {
        if (array_key_exists($key, $case['expected'])) { $check($balances[$account][$currency] === (float) $case['expected'][$key], $case['name'].': wrong '.$key); }
    }
    $check(DB::table('partner_payment_audits')->count() === $case['expected']['audits'], $case['name'].': wrong audit count');
    $check(DB::table('partner_payment_audits')->select('operation_key')->distinct()->count() === $case['expected']['audits'], 'Duplicate audit keys');
    $check(DB::table('partner_finance_transactions')->count() === count($case['expenses']) + ($case['expected']['extra_movements'] ?? 0), 'Duplicate/unexpected financial movements');
    $check(DB::table('partner_patient_payments')->count() === count($case['receipts']), 'A receipt was inserted or hard-deleted');
    foreach (['a', 'b'] as $letter) {
        if (isset($jobs[$letter]['id']) && in_array($pair[$letter]['status'], ['rejected', 'rolled_back'], true)) {
            $id = $jobs[$letter]['id'];
            // A stale editor may target the same receipt legitimately committed by its peer.
            if ($id !== ($jobs[$letter === 'a' ? 'b' : 'a']['id'] ?? null)) {
                $check(json_encode(DB::table('partner_patient_payments')->find($id)) === json_encode($original[$id]), 'Failed mutation left partial receipt/version/cancellation changes');
            }
        }
    }
    foreach (DB::table('partner_patient_payments')->get() as $row) {
        $check($row->created_at === $original[$row->id]->created_at, 'Creation history changed');
        $audits = DB::table('partner_payment_audits')->where('partner_patient_payment_id', $row->id)->orderBy('id')->get();
        if ($audits->count() && $audits->last()->payload_hash !== str_repeat('0', 64)) {
            $latest = json_decode($audits->last()->new_values, true, flags: JSON_THROW_ON_ERROR);
            $check((int) $latest['lock_version'] === (int) $row->lock_version, 'Audit and committed version differ');
            $check((string) $latest['amount'] === number_format((float) $row->amount, 2, '.', ''), 'Audit and committed amount differ');
            $check(($latest['deleted_at'] !== null) === ($row->deleted_at !== null), 'Audit and cancellation state differ');
            foreach (['currency', 'payment_method', 'patient_id', 'notes'] as $field) {
                $check((string) $latest[$field] === (string) $row->{$field}, 'Audit and committed '.$field.' differ');
            }
        }
    }
    $check(DB::table('partner_payment_audits')->orderBy('id')->limit($seedAuditCount)->get()->toJson() === $priorAudits, 'Historical audit changed');
    $results[] = ['case' => $case['name'], 'passed' => true, 'independent_backend_pids' => [$pidA, $pidB], 'verified_lock_wait' => true,
        'statuses' => array_column($pair, 'status'), 'balances' => $balances];
    echo 'PASS '.$case['name']."\n";
}
$schema = 'isr_lock_'.bin2hex(random_bytes(6));
israeliPostgresEnvironment($schema);
israeliPostgresFixtures($schema, [$receipt()], []);
$original = DB::table('partner_patient_payments')->get()->toJson();
try {
    DB::transaction(function () use ($mutation): void {
        DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        pgPaymentOperation($mutation('cancel', 1));
    });
    throw new RuntimeException('Unsupported isolation was accepted');
} catch (LogicException $exception) {
    $check(str_contains($exception->getMessage(), 'READ COMMITTED'), 'Wrong isolation failure');
}
$check(DB::table('partner_patient_payments')->get()->toJson() === $original, 'Unsupported isolation changed a receipt');
$check(DB::table('partner_payment_audits')->count() === 0, 'Unsupported isolation left an audit');
$results[] = ['case' => 'unsupported isolation fails closed without mutations', 'passed' => true];
file_put_contents($output.'/results.json', json_encode(['cases' => count($results), 'assertions' => $assertions, 'passed' => true, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo count($results)." PostgreSQL concurrency cases passed; {$assertions} assertions.\n";
