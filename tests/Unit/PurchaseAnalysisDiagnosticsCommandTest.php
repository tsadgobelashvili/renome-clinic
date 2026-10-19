<?php

use App\Services\PurchaseAnalysisDiagnostics;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    // Tiny disposable probe outside an outer transaction; no application migrations.
    DB::statement('CREATE TABLE diagnostic_probe (value INTEGER)');
    DB::table('diagnostic_probe')->insert(['value' => 1]);
});

test('diagnostic command runs read only and restores its connection without altering data', function () {
    $this->travelTo('2026-10-19 12:00:00');
    $before = DB::table('diagnostic_probe')->count();
    $this->mock(PurchaseAnalysisDiagnostics::class)->shouldReceive('snapshot')->once()->andReturnUsing(function ($from, $until): array {
        expect(DB::transactionLevel())->toBe(1)->and((int) DB::selectOne('PRAGMA query_only')->query_only)->toBe(1);

        return compact('from', 'until') + ['all_lines_in_period' => 0, 'included_documents' => 0];
    });
    expect(Artisan::call('purchases:analysis-diagnose', ['--from' => '2026-09-20', '--until' => '2026-10-19']))->toBe(0);
    $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($result['from'])->toBe('2026-09-20')->and($result['until'])->toBe('2026-10-19')
        ->and($result['all_lines_in_period'])->toBe(0)->and($result['included_documents'])->toBe(0)
        ->and(DB::table('diagnostic_probe')->count())->toBe($before)->and(DB::transactionLevel())->toBe(0)
        ->and((int) DB::selectOne('PRAGMA query_only')->query_only)->toBe(0);
});

test('diagnostic transaction rejects accidental database writes', function () {
    $this->mock(PurchaseAnalysisDiagnostics::class)->shouldReceive('snapshot')->once()->andReturnUsing(function (): array {
        try {
            DB::table('diagnostic_probe')->insert(['value' => 2]);
            test()->fail('Read-only transaction allowed a write');
        } catch (QueryException $exception) {
            expect(strtolower($exception->getMessage()))->toContain('readonly');
        }

        return ['write_blocked' => true];
    });
    expect(Artisan::call('purchases:analysis-diagnose'))->toBe(0)
        ->and(DB::table('diagnostic_probe')->count())->toBe(1)
        ->and((int) DB::selectOne('PRAGMA query_only')->query_only)->toBe(0);
});
