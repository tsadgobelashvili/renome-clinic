# Performance Phase 2 — local measurements and production checklist

This pass changes no financial formulas, BOG classification/reconciliation, RS allocations,
payroll, authorization, or infrastructure. No migration/index was added or applied.
Production timings, query plans and server configuration have not been measured from this workstation.

## Local before/after

Same in-memory SQLite fixtures, mocked HTTP, query logging enabled only around the operation.
Times are one local sample, not a production latency prediction. Database transaction/savepoint
commands are not included in Laravel's query log counts.

| Operation | Before | After |
| --- | ---: | ---: |
| BOG: 300 published historical rows + 1 pending CLI row, rows materialized for ingestion | 301 | 1 |
| Same BOG operation, SQL queries | 11 | 9 |
| Next no-new-record sync, historical rows materialized | 301 | 0 |
| Same no-op sync, SQL queries | 9 | 6 |
| Fresh response normalization passes | 2 | 1 |
| Statement only, HTTP calls | 2 | 2 |
| Statement + balance in the same request, HTTP calls | 4 | 3 |
| RS: 60 items, 5 repeated products, 1 supplier, 3 documents, SQL queries | 729 | 265 |
| Same RS file reimport, SQL queries | 240 | 67 |
| RS document total recalculations | 60 | 3 |
| RS import time, first measured sample (ms) | 265.15 | 130.52 |
| Statistics Finance: income, partner/USD fixture, SQL queries | 31 | 23 |
| Statistics Finance: expense, partner/USD fixture, SQL queries | 30 | 23 |
| Statistics Finance: cash out, partner/USD fixture, SQL queries | 36 | 26 |
| Main Finance Overview, SQL queries | 9 | 9 |

The baseline HTTP count follows the original two authentication calls; the after count is
asserted with mocked HTTP. Token reuse requires an API-provided usable `expires_in`.
Separate Livewire requests each authenticate independently. No token is stored persistently.

BOG "rows" means PHP materialization/normalization/publication work, **not PostgreSQL rows
examined internally**. An anti-existence query uses the already-persisted Bank ledger as
publication evidence. PostgreSQL still examines staging candidates; verify its production
plan before considering an index or separate publication marker. Missing or conflicting
ledger facts remain pending. Fresh API rows always receive the existing conflict checks.
Deleted/rolled-back ledger entries become pending again automatically. Legacy decorated
account identifiers conservatively continue through ingestion rather than being falsely skipped.

RS caches are confined to a transaction of at most 100 consecutive rows from one supplier.
Each affected document is refreshed once **per batch**, before commit. A document spanning
three batches is refreshed three times, not once for the entire file: this bounds lock time
and avoids committing stale totals. Row savepoints, supplier/document locks, cash-paid guards,
source hashes, model events and unique constraints remain in place. Deadlocks propagate to
Laravel's bounded transaction retry. Failed-row caches are cleared to avoid rolled-back IDs.
Individual inserts and duplicate checks are retained because their side effects and safety
matter more than eliminating every write/query.

Statistics reuses one grouped FinanceTransaction query for manual income, Clinic expenses,
cash expenses and Israeli salary contributions. Descriptions are requested through the
small `აღწერა +` control. The main Overview already lazily loads detail and had no duplicate
aggregate to remove in this fixture; its nine-query path is unchanged. No financial result
is cached between renders/requests. Salary detail and direction-pair calculations remain intact.

BOG/RS loops contained no redundant successful-item log calls to remove. Errors/warnings remain.

## Query plans — read-only, bounded, manual

From the application directory, place the following diagnostic in a private temporary file
outside the web root, e.g. `/tmp/renome-phase2-explain.php`. It uses the actual current query
builders, including fee pairing and allocation SQL. It makes no API requests or sync/import
calls. Replace the example filters/document ID with a representative selection.

```php
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use App\Services\Bank\BankReport;
use App\Services\Bank\BankAccounting;
use App\Services\Bank\BankPurchaseMatching;
use App\Services\Finance\AccountingLedger;
use App\Filament\Pages\FinanceReports;

if (DB::getDriverName() !== 'pgsql') {
    throw new RuntimeException('This diagnostic is for PostgreSQL.');
}
$from = '2026-09-12';
$until = '2026-09-18';
$documentId = 1; // Existing representative RS document.
$filters = ['dateFrom' => $from, 'dateUntil' => $until, 'currency' => 'GEL'];
$bank = app(BankReport::class);
$statistics = new FinanceReports;
$statistics->dateFrom = $from;
$statistics->dateUntil = $until;
$statistics->source = 'all';
$statistics->currency = 'GEL';
$statistics->reportTab = 'expense';
$operations = [
    'Bank list' => fn () => $bank->query($filters)->orderByDesc('transaction_date')->limit(25)->get(),
    'Bank summary including commission pairing' => fn () => $bank->totals($filters),
    'RS document item lookup' => fn () => DB::table('purchase_items')->where('purchase_id', $documentId)->get(),
    'RS document total' => fn () => DB::table('purchase_items')->where('purchase_id', $documentId)->sum('line_total'),
    'Finance aggregate' => fn () => app(AccountingLedger::class)->pnlTotals($from, $until, 'all', 'GEL', 'all'),
    'Statistics dimensions' => fn () => (new ReflectionMethod($statistics, 'expenseDimensionReport'))->invoke($statistics),
    'Bank fee pairing' => fn () => $bank->query($filters)
        ->leftJoin('bank_categories as bc', 'bc.id', '=', 'bank_transactions.bank_category_id')
        ->selectRaw('SUM('.BankAccounting::cardFeeSql().') AS card_fees')->get(),
    'Bank purchase allocation summary' => fn () => app(BankPurchaseMatching::class)->summaryQuery()->get(),
];
$analyze = in_array('--analyze', $argv, true);
DB::beginTransaction();
try {
    DB::statement('SET TRANSACTION READ ONLY');
    DB::statement("SET LOCAL statement_timeout = '15s'");
    DB::statement("SET LOCAL lock_timeout = '2s'");
    foreach ($operations as $name => $operation) {
        // Pretend captures generated SQL without retrieving financial records.
        foreach (DB::pretend($operation) as $query) {
            if (! preg_match('/^\s*select\b/i', $query['query'])) {
                throw new RuntimeException('Refusing a non-SELECT plan.');
            }
            echo "\n=== {$name} ===\n";
            $prefix = $analyze ? 'EXPLAIN (ANALYZE, BUFFERS, TIMING OFF, FORMAT TEXT) ' : 'EXPLAIN (COSTS, FORMAT TEXT) ';
            foreach (DB::select($prefix.$query['query'], $query['bindings']) as $line) {
                echo $line->{'QUERY PLAN'}."\n";
            }
        }
    }
} finally {
    DB::rollBack();
}
```

```bash
php /tmp/renome-phase2-explain.php
# Optional, off peak: executes SELECTs to measure actual rows/buffers/time.
php /tmp/renome-phase2-explain.php --analyze
```

Repeat narrow/wide ranges, each relevant source/currency and a large RS document. Record rows
estimated/actual, loops, buffer reads/hits, sort spills, scans and execution time. EXPLAIN ANALYZE
is read-only here but consumes resources; a timeout aborts the diagnostic. Do not add indexes
from estimates alone. Existing `operation_id`, unique `entry_id`/`deduplication_key`, RS identity/
source-row keys and the Phase 1 purchase-leading index should be checked against actual plans.

## Server/FPM/OPcache diagnostics

Run manually on the server. Do not dump `.env`, HTTP headers, tokens, or unfiltered `php -i`
into shared logs. These commands change no settings or service state.

```bash
php -v
php --ini
php -i | grep -E '^(opcache\.(enable|enable_cli|memory_consumption|max_accelerated_files|validate_timestamps|revalidate_freq)|Loaded Configuration File|Scan this dir)'
systemctl list-units --type=service --all 'php*-fpm.service'
pgrep -a -f 'php-fpm: master process'
# Use the version/binary discovered above; php-fpm8.3 is an example.
sudo php-fpm8.3 -i | grep -E '^(opcache\.(enable|memory_consumption|max_accelerated_files|validate_timestamps|revalidate_freq)|Loaded Configuration File|Scan this dir)'
sudo php-fpm8.3 -tt 2>&1 | grep -E '(\[[^]]+\]|pm =|pm\.(max_children|start_servers|min_spare_servers|max_spare_servers|max_requests)|php_(admin_)?(value|flag)\[opcache\.)'
free -h
uptime
df -h
ps -eo pid,ppid,rss,pcpu,etime,args | grep '[p]hp-fpm'
pgrep -fc 'php-fpm: pool'
```

CLI OPcache is not evidence of the web pool's OPcache. Compare FPM binary/ini output with
pool overrides and the running master executable. Occupancy/hit rate/restarts and queued
requests require an **existing private** FPM status/diagnostic endpoint, if configured.
Do not expose phpinfo or add a public diagnostic route. No FPM/OPcache tuning recommendation
is justified until memory, worker counts, pool values and actual saturation are collected.

Use the existing PostgreSQL login/service; replace the database name, never put a password
in the command line:

```bash
psql -X -d YOUR_DATABASE <<'SQL'
BEGIN READ ONLY;
SET LOCAL statement_timeout = '5s';
SELECT pid, application_name, state, wait_event_type, wait_event,
       now() - query_start AS query_age,
       now() - xact_start AS transaction_age,
       pg_blocking_pids(pid) AS blocking_pids
FROM pg_stat_activity
WHERE datname = current_database() AND pid <> pg_backend_pid()
ORDER BY query_start NULLS LAST;
SELECT tablename, indexname, indexdef FROM pg_indexes
WHERE schemaname = current_schema()
  AND tablename IN ('bog_transactions','bank_transactions','purchases','purchase_items',
                    'purchase_products','bank_purchase_matches','finance_transactions');
SELECT extname FROM pg_extension WHERE extname = 'pg_stat_statements';
ROLLBACK;
SQL
```

If pg_stat_statements is already enabled, use its existing statistics; do not install/reset it
as part of this pass. The activity snapshot deliberately omits raw query text/patient data.

## Authenticated production timings

Use an authorized Owner session and browser Network tools. Keep the same role, filter values,
date range, data volume and cache setting for comparisons. Record one cold and five warm
loads, median and slowest result; do not compare an unauthenticated redirect with a real page.

| Page | Initial request | Livewire interaction to measure |
| --- | --- | --- |
| Dashboard | Document TTFB | Open New Visit; async patient search |
| Patients | Document TTFB | Search and pagination |
| Visits | Document TTFB | Date/doctor filter and pagination |
| Doctors | Document TTFB | Search; open/edit form without saving |
| Employees | Document TTFB | Search; open/edit form without saving |
| Bank | Document TTFB separately from balance refresh | Filters; balance refresh separately from sync |
| RS purchases | Document TTFB | Date/supplier filters and pagination |
| RS document edit | TTFB for small and large documents | Product search and selection without saving |
| Finance | Document TTFB | Source/date/currency; open expense group |
| Statistics | Document TTFB | Finance tabs, grouping and description expansion |

For every row record: document TTFB (Network Timing → waiting), Livewire POST duration,
query count, SQL milliseconds and external API wait. Separate balance loading from initial
HTML and sync from read-only navigation. Do not trigger production sync/import merely to
measure page navigation; use the next authorized operational run.

Browser timing alone cannot supply SQL counts/time or isolate upstream API wait. Use existing
APM/request traces if available. Otherwise capture the same request on an isolated staging copy
with a temporary, scoped `DB::listen` collector (counts and summed elapsed time only) and HTTP
RequestSending/ResponseReceived timestamps (service label, duration/status only). Remove the
collector afterward; no Debugbar/Telescope package, global query log, payload/binding logging,
APP_DEBUG change, or permanent production instrumentation. For production SQL corroboration,
compare existing pg_stat_statements call/time deltas during a quiet window; concurrent requests
make these approximate rather than per-request counts. Existing Nginx upstream timing/FPM slow
logs can distinguish worker wait from app time but cannot alone identify BOG network latency.

## Files and verification

Changed files:

- `app/Services/Bank/BogBankSyncService.php`
- `app/Services/BogStatementSyncService.php`
- `app/Services/BogBusinessApiService.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/PurchaseImportService.php`
- `app/Models/PurchaseItem.php`
- `app/Filament/Pages/Finance.php`
- `app/Filament/Pages/FinanceReports.php`
- `resources/views/filament/pages/finance-reports.blade.php`
- `tests/Feature/PerformancePhaseTwoTest.php`
- `docs/performance-phase-2.md`

No server setting, financial data, migrations, indexes, or deployment behavior was changed.

Focused tests use `APP_CONFIG_CACHE`/`APP_ROUTES_CACHE` paths that do not exist,
`APP_ENV=testing`, `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:` and mocked external HTTP,
so cached local configuration cannot redirect RefreshDatabase to the real database.
Final focused run: **262 tests, 261 passed, 1 pre-existing failure, 2,160 assertions**
(19 relevant test files, not the full suite). The new Phase 2 file passes all **10 tests / 226
assertions**. Pint, diagnostic PHP syntax validation, and `git diff --check` pass.

The existing failure is `tests/Feature/FinanceTest.php:438`,
`cash flow history separates movements from expenses and exposes searchable transfers`.
Its first entries assertion expects salary child labels `ხელფასი`; the fixture currently
renders `Needs review Needs review`. The failure was reproduced with the original committed
Finance class loaded separately, without reverting workspace changes. This classification/
label issue was left unchanged because it is outside the performance pass. No failing monetary
total assertion was observed. Production PostgreSQL plans/lock-contention timings remain
unverified; SQLite tests do not prove concurrent PostgreSQL behavior.
