<?php

// Standalone synthetic transaction test. Never boots Laravel or reads .env.
require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/IsraeliPaymentPostgresEnvironment.php';

use App\Models\Visit;
use App\Services\VisitPaymentSubmission;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function ctWait(callable $predicate): mixed
{
    $deadline = microtime(true) + 15;
    do {
        if ($result = $predicate()) {
            return $result;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Timed out waiting for synthetic PostgreSQL worker');
}

if (($argv[1] ?? '') === 'worker') {
    $job = json_decode(file_get_contents($argv[2]), true, flags: JSON_THROW_ON_ERROR);
    israeliPostgresEnvironment($job['schema']);
    file_put_contents($job['pid'], (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
    $hold = function () use ($job): void {
        file_put_contents($job['held'], 'held');
        ctWait(fn () => file_exists($job['release']));
    };
    try {
        DB::transaction(function () use ($job, $hold): void {
            app(VisitPaymentSubmission::class)->run($job['patient'],
                ['payment_request_key' => $job['key'], 'amount' => $job['amount']], function ($submission) use ($job, $hold) {
                    DB::table('submission_effects')->insert(['submission_id' => $submission, 'amount' => $job['amount']]);
                    if ($job['fail']) {
                        $hold();
                        throw new RuntimeException('Synthetic rollback');
                    }

                    return Visit::findOrFail($job['visit']);
                });
            if ($job['hold']) {
                $hold();
            }
        });
        $status = 'committed';
    } catch (ValidationException) {
        $status = 'rejected';
    } catch (Throwable $exception) {
        $status = $exception->getMessage() === 'Synthetic rollback' ? 'rolled_back' : $exception::class.': '.$exception->getMessage();
    }
    file_put_contents($job['result'], json_encode(['status' => $status], JSON_THROW_ON_ERROR));
    exit(in_array($status, ['committed', 'rejected', 'rolled_back'], true) ? 0 : 1);
}

$output = getenv('CT_PAYMENT_PG_OUTPUT');
if (! $output || ! is_dir($output) || file_exists($output.'/results.json')) {
    throw new RuntimeException('Requires a new synthetic output directory');
}
$results = [];
$checks = 0;
$check = function (bool $valid, string $message) use (&$checks): void {
    if (! $valid) {
        throw new RuntimeException($message);
    }
    $checks++;
};
foreach (['replay', 'changed payload', 'separate partials', 'cross patient key', 'rollback then retry'] as $case) {
    $schema = 'isr_lock_'.bin2hex(random_bytes(6));
    israeliPostgresEnvironment($schema);
    israeliPostgresFixtures($schema, [], []);
    Schema::create('visits', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('patient_id');
        $table->timestamp('cancelled_at')->nullable();
    });
    Schema::create('payments', fn (Blueprint $table) => $table->id());
    Schema::create('submission_effects', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('submission_id');
        $table->decimal('amount', 14, 2);
    });
    $migration = require dirname(__DIR__, 2).'/database/migrations/2026_11_23_120000_create_visit_payment_submissions.php';
    DB::transaction(fn () => $migration->up());
    DB::table('visits')->insert([['id' => 1, 'patient_id' => 53], ['id' => 2, 'patient_id' => 54]]);
    $directory = $output.'/'.$schema;
    mkdir($directory);
    $key = (string) Str::uuid();
    $jobs = $processes = [];
    try {
        foreach (['a', 'b'] as $letter) {
            $job = ['schema' => $schema, 'patient' => $case === 'cross patient key' && $letter === 'b' ? 54 : 53,
                'visit' => $case === 'cross patient key' && $letter === 'b' ? 2 : 1,
                'key' => $case === 'separate partials' && $letter === 'b' ? (string) Str::uuid() : $key,
                'amount' => in_array($case, ['changed payload', 'separate partials'], true) && $letter === 'b' ? 20 : 30,
                'hold' => $letter === 'a', 'fail' => $case === 'rollback then retry' && $letter === 'a',
                'held' => $directory.'/held', 'release' => $directory.'/release',
                'pid' => $directory.'/'.$letter.'.pid', 'result' => $directory.'/'.$letter.'.result.json'];
            $jobPath = $directory.'/'.$letter.'.job.json';
            file_put_contents($jobPath, json_encode($job, JSON_THROW_ON_ERROR));
            $process = proc_open([PHP_BINARY, __FILE__, 'worker', $jobPath], [0 => ['pipe', 'r'],
                1 => ['file', $directory.'/'.$letter.'.stdout', 'w'], 2 => ['file', $directory.'/'.$letter.'.stderr', 'w']], $pipes);
            if (! is_resource($process)) {
                throw new RuntimeException('Cannot launch independent PostgreSQL connection');
            }
            fclose($pipes[0]);
            $jobs[$letter] = $job;
            $processes[$letter] = $process;
            if ($letter === 'a') {
                ctWait(fn () => file_exists($job['held']) || file_exists($job['result']));
                if (file_exists($job['result'])) {
                    throw new RuntimeException(file_get_contents($job['result']));
                }
            }
        }
        ctWait(fn () => file_exists($jobs['b']['pid']));
        $aPid = (int) file_get_contents($jobs['a']['pid']);
        $bPid = (int) file_get_contents($jobs['b']['pid']);
        $check($aPid !== $bPid, 'Workers did not use independent connections');
        ctWait(fn () => DB::selectOne('SELECT ?::integer = ANY(pg_blocking_pids(?::integer)) AS blocked', [$aPid, $bPid])->blocked);
        $check(true, 'Actual row lock wait verified');
    } finally {
        file_put_contents($directory.'/release', 'release');
    }
    foreach ($processes as $process) {
        $check(proc_close($process) === 0, 'Worker errored');
    }
    $statuses = array_map(fn ($job) => json_decode(file_get_contents($job['result']), true)['status'], $jobs);
    $expected = match ($case) {
        'changed payload', 'cross patient key' => ['committed', 'rejected'],
        'rollback then retry' => ['rolled_back', 'committed'],
        default => ['committed', 'committed'],
    };
    $check(array_values($statuses) === $expected, 'Incorrect concurrent outcome: '.json_encode($statuses));
    $count = $case === 'separate partials' ? 2 : 1;
    $check(DB::table('visit_payment_submissions')->count() === $count, 'Duplicate request claims');
    $check(DB::table('submission_effects')->count() === $count, 'Duplicate or partially committed effects');
    $check((float) DB::table('submission_effects')->sum('amount') === ($case === 'separate partials' ? 50.0 : 30.0), 'Wrong committed total');
    $check(DB::table('visit_payment_submissions')->whereNull('visit_id')->count() === 0, 'Partial request claim committed');
    $migration->down();
    $check(DB::table('visit_payment_submissions')->count() === $count, 'Rollback migration discarded identity history');
    $results[] = ['case' => $case, 'passed' => true, 'actual_lock_wait' => true, 'independent_pids' => [$aPid, $bPid], 'statuses' => array_values($statuses)];
    echo 'PASS '.$case."\n";
}
file_put_contents($output.'/results.json', json_encode(['passed' => true, 'cases' => count($results), 'checks' => $checks, 'results' => $results], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'PASS '.count($results).' PostgreSQL transaction cases, '.$checks.' checks'."\n";
