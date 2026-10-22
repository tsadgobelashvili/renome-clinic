# RS checkpoints on the application database

Production's confirmed error was `RS import checkpoints require the PDO SQLite
extension.` Both the 240 KB and 960 KB files were below the 2 MB upload limit.
The previous SQLite workspace was introduced to spool full-year rows and bounded
occurrence/reconciliation indexes onto disk rather than retaining the whole file
in PHP memory. Requiring a second PDO driver on a PostgreSQL application was an
unnecessary deployment dependency.

## Storage and ownership

`RsImportCheckpoint` now uses the configured Laravel database connection
(PostgreSQL in production and the local PostgreSQL integration test).
`rs_import_checkpoints` holds UUID, Owner ID, original filename, private paths,
SHA-256 source hash, phase/status, row cursor, JSONL byte offset, totals/counters,
bounded summary metadata, report path and lifecycle timestamps.
`rs_import_checkpoint_entries` holds only operational occurrence counters,
touched-document IDs and reconciliation error/alias indexes, with cascading
cleanup. No CSV contents or staged source rows are stored in these tables.

The original source and streamed staged JSONL stay on private `local` storage.
The source is retained while the run is resumable. Windows and Ubuntu use the
same Laravel/query-builder/filesystem implementation, with no temporary database
files, PDO SQLite guards, SQLite SQL or alternate checkpoint backend.

## Upload and resume

1. Filament validates the one CSV/XLSX upload and copies it privately.
2. Before Filament deletes the Livewire temporary file, the server creates the
   database checkpoint, persists a private source copy, records its hash and
   commits the initial `preparing` state. If this fails, the original temporary
   upload survives, the failure is logged and a Georgian notification appears.
3. The modal closes, progress appears and polling begins. Preparation streams the
   existing parser output into private JSONL and publishes it atomically.
4. Each step seeks the persisted byte offset and reads at most 500 staged entries.
   Existing 100-row Purchase transactions/savepoints remain separate from the
   final checkpoint metadata transaction. A failed later batch cannot roll back
   previously committed batches; cursor replay uses unchanged duplicate identities.
5. Complete-source document reconciliation still runs in bounded document groups.
   Quantity precision, cancellation/return rules, Product Catalog mapping and all
   financial boundaries are unchanged. Completed rows are timestamped.

Every status/step checks Purchase-create authorization and exact Owner ID. Reload
prefers that Owner's active database run, even with no session ID, then falls back
to a valid retained session run. Reports and revalidation check checkpoint
ownership too. A per-run filesystem lock serializes processing against the same
private files. Multi-node deployments must share that private storage/lock domain.

Legacy SQLite files are ignored, not migrated or deleted. Stale session IDs do not
block a fresh import. This does not inspect, repair, clear or migrate any Purchase
or Finance row. Re-uploading an unchanged full file uses the existing deduplication
rules and can continue after partial imports.

## Deployment and retention

Additive migration: `2026_10_22_120000_create_rs_import_checkpoints.php`.
It creates only the two operational tables above. After code deployment, apply
through the normal release process:

```sh
php artisan migrate --force
php artisan optimize:clear
```

These commands were **not** run against the existing application database. No
SQLite package/extension installation is required. Existing PostgreSQL access,
private storage permissions, PHP upload limits and the scheduler remain necessary.
Changing upload-size settings is not a remedy for the confirmed production error.

`php artisan rs:imports-prune` is scheduled daily at 04:30. Completed runs retain
their source, staged rows, report and indexes for 30 days; abandoned temporary
metadata expires after one day. Cleanup rechecks eligible status under the run
lock and cascades only checkpoint indexes. Preparing/importing/finalizing runs
are never automatically pruned, including their source and report. Unreferenced
UUID-named upload/source/report artifacts older than 30 days are cleaned too;
legacy `.sqlite` files are explicitly excluded. No active source is age-deleted.

## Verification

The standalone `tests/Support/rs-postgres-checkpoint.php` refuses cached config,
non-loopback/wrong-port connections, non-dedicated database names, an existing
schema, or a PHP process with either SQLite extension loaded. It was run against
a new disposable PostgreSQL 18 cluster on localhost:55439, with PHP `-n` and only
the needed extensions, including `pdo_pgsql`. It verified an exact 245,760-byte
CSV through the actual Livewire action: checkpoint creation, modal closure,
progress, reload without session, 1,200 accepted lines, one rejected row, then
1,200 duplicates on full-file retry and byte-identical Finance table snapshots.
The disposable cluster was stopped and removed afterwards. The real application
database and the user's production files were never used.

Normal Pest tests retain their strict SQLite `:memory:` safety guard as a test
harness only; the checkpoint implementation always uses the application's
connection and the same private-file architecture. Persistence tests cover Owner
isolation, legacy-state handling, checkpoint creation failure and retry, source
retention, and cleanup that preserves active sources and reports.

Final local results (2026-10-22):

- Focused checkpoint/modal/upload/flow tests: **37 passed, 1 optional sample test
  skipped**, 351 assertions. Includes database creation retry with preserved temp
  upload, database-index failure recovery, report-write failure recovery, Owner
  isolation, orphan cleanup and active-source/report retention.
- Complete RS/parser/Purchase run: **258 passed, 2 optional sample tests skipped**;
  the unchanged direction-selector assertion still expects 7 entries instead of 8.
  `PurchaseProductGroupMigrationTest` also hits the existing `DatabaseTruncation`
  versus `RefreshDatabase` ordering issue (`no such table: suppliers`); it passes
  alone (4 assertions), and the failure reproduces after the Finance-only
  `FinanceExpenseEntryTest` without executing checkpoint code.
- Selected Finance/Cash/Advances/Partner/Israeli Finance suite: **226 passed,
  14 assertion failures and 9 errors**. Exact failing test names match the same
  suite run with unchanged HEAD versions of all modified import classes:
  **23 baseline issues, 23 current issues, zero new failing cases**. Diagnostic
  JSON is in `storage/logs/rs-checkpoint-finance-{baseline,current}.json`.
- Full-year regression retains 5,000 inputs, existing 970 lines, 4,021 newly
  imported lines, 974 duplicates and 5 rejected rows, unchanged catalog mapping,
  and identical nonempty financial snapshots/P&L.
- PHP syntax, Pint and `git diff --check` passed. No package installation,
  production import, application-data mutation, commit, push or deployment.
