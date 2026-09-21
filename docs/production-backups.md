# Production backups and recovery

Backups use `spatie/laravel-backup`, the existing `pgsql` connection and existing
`s3` filesystem disk. No Finance/BOG/RS import or reset code is involved.

## Configure and verify (Linux production host)

1. Deploy the committed Composer lockfile with the normal deployment procedure
   (`composer install --no-dev --optimize-autoloader`). Run `composer check-platform-reqs --no-dev`.
   PHP ZIP and PostgreSQL client tools are required. `pg_dump --version` must be
   compatible with the server (prefer the same major version). The package does
   not support Windows production servers; local tests do not prove a live backup.
2. Reuse the existing `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`,
   `AWS_DEFAULT_REGION` (Spaces region, e.g. `fra1`), `AWS_BUCKET`, and
   `AWS_ENDPOINT` (`https://fra1.digitaloceanspaces.com`, **not** a CDN/bucket URL).
   Leave `AWS_USE_PATH_STYLE_ENDPOINT=false`. Do not change `FILESYSTEM_DISK`.
   Use a private Space and a key scoped to it with list/read/write/delete rights
   (delete is needed for retention). Do not enable public bucket access/CDN for backups.
3. Set a strong `BACKUP_ARCHIVE_PASSWORD` and keep it in an off-server password
   manager. Archives use AES-256 when this is set; **an empty password means no
   archive encryption**. Also retain `APP_KEY`, any `APP_PREVIOUS_KEYS`, and
   `PERSONAL_ID_HASH_KEY` separately: without the original encryption keys some
   patient data cannot be recovered. `.env` is deliberately not in the archive.
4. Set `BACKUP_MAIL_TO` and working existing `MAIL_*` settings for success/failure
   notifications. With no recipient, mail is disabled; with the log mailer,
   notifications only reach the log. Arrange external monitoring of cron/backup
   freshness too; the server cannot alert when it is completely offline.
5. Optional `BACKUP_PG_DUMP_PATH` is the directory containing `pg_dump`, not the
   executable itself. `BACKUP_NAME` defaults to `renome-{APP_ENV}`: keep a stable,
   unique prefix per environment/application. Never point a test cleanup at the
   production prefix. Do not share this prefix with unrelated ZIP files.
6. Keep `BACKUP_ENABLED=false` until manual verification succeeds. Then set it to
   `true` in production and run `php artisan config:cache`. Do not print/dump the
   configuration or secrets. Use `php artisan schedule:list` to inspect timings;
   listing events does not execute them or prove that their enable guard passes.

Included: **all PostgreSQL tables**, `storage/app/private`, `storage/app/public`.
Excluded: Livewire temporary uploads, `storage/framework`, logs, backup staging,
code, `.env`, vendor, node_modules and build outputs. Private retained import files
are included. Public uploads must actually live in `storage/app/public`, not a
separate upload location. Remote S3 application files are not copied by this local
file selection. Revisit the include list if upload storage changes. Shared storage
deployments must resolve to these paths; child symlinks are not followed.

The cron user needs read access to uploads and write access to
`storage/app/backup-temp`, `storage/framework` and `bootstrap/cache`. Restrict these
directories to the application/deployment users: temporary database dumps are
unencrypted until the archive is created. Allow sufficient local disk space for
both the dump and ZIP. Do not place staging under the public web root.
For remote PostgreSQL, configure libpq `PGSSLMODE` (and `PGSSLROOTCERT` where used)
in the scheduler/manual command environment to match the DB TLS policy; the
package's dumper does not inherit Laravel's `sslmode` automatically.

## Run, inspect, download

Run as the normal application/cron user, from the project directory:

```sh
php artisan backup:run --isolated=1
php artisan backup:list
php artisan backup:monitor
```

These use real credentials: `backup:run` reads the database/uploads and writes a
ZIP to Spaces. Inspect the exit code, notification and object size/time. Do a
download/test restore before calling the setup verified. `backup:list` reports
destination health/counts, not a complete list of individual object names.

In DigitalOcean: **Spaces Object Storage → your Space → `renome-production/`**
(or configured prefix) → select the timestamped ZIP → **Download**. Keep it private;
do not create a public sharing URL. Alternatively, with an independently configured
AWS CLI profile (never pass credentials in command arguments):

```sh
aws --profile renome-backup --endpoint-url https://fra1.digitaloceanspaces.com s3 ls s3://YOUR_PRIVATE_SPACE/renome-production/
aws --profile renome-backup --endpoint-url https://fra1.digitaloceanspaces.com s3 cp s3://YOUR_PRIVATE_SPACE/renome-production/EXACT_BACKUP.zip ./backup.zip
```

Use an AES-capable archiver, e.g. `7z t backup.zip` then `7z x backup.zip -orecovery`.
Enter the password at the prompt, never as a shell argument. The archive contains
`db-dumps/pgsql-*.dump` (PostgreSQL custom format) and `app/private`, `app/public`.
The built-in ZIP verification is an integrity sanity check, **not** a restore test.

## Daily schedule and retention

Use the existing Laravel scheduler in `routes/console.php`. On **one** production
host, ensure the application user's cron has this single entry (adapt path):

```cron
* * * * * cd /path/to/renome-clinic && umask 077 && php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

Do not add a second cron if one already exists. Rotate the scheduler log. Schedules
use `APP_TIMEZONE` (normally Asia/Tbilisi): cleanup 02:30, full backup 03:00, health
check 04:00. They require production **and** `BACKUP_ENABLED=true`, skip maintenance
mode, and use non-overlap locks. Use a persistent lock-capable existing cache store
(not `array`). Manual backups use `--isolated=1` too. Do not run manual cleanup
concurrently with a backup. Long/stuck jobs and lock expiration require operator
investigation; do not clear locks until confirming no process is still running.

Retention uses Spatie's sequential age windows: all for 7 days, daily for the next
16 days, weekly for the next 8 weeks, monthly for the next 6 months, yearly for the
next year. The newest backup is preserved. No size cap shortens these windows.
`php artisan backup:clean` applies this policy to the configured prefix only and
**deletes old backup objects**, not application records. Check `backup:list` and
the prefix first. No additional Spaces lifecycle deletion policy is required.

## Restore into a separate test database first

**Restoring the whole database rolls data back to the backup timestamp. All newer
payments, payroll, patients, BOG imports, RS changes and other data would be lost
from the restored database.** A daily dump is not continuous point-in-time recovery.
Uploads and a live database are not an atomic snapshot; verify their consistency.

Use an isolated PostgreSQL server/test role with no production access. Use a
protected `.pgpass`/libpq service or password prompt; no passwords in commands.
Replace the explicit placeholders below and choose a **new empty** database:

```sh
createdb --host=TEST_HOST --username=RESTORE_ROLE --template=template0 renome_restore_check
pg_restore --list recovery/db-dumps/EXACT_PGSQL_FILE.dump
pg_restore --host=TEST_HOST --username=RESTORE_ROLE --dbname=renome_restore_check --no-owner --no-privileges --exit-on-error --single-transaction recovery/db-dumps/EXACT_PGSQL_FILE.dump
```

Do not use `--create`, `--clean`, or the production database name. Check table counts,
latest timestamps, patient identifiers, Finance totals, finalized salary records,
BOG entry IDs, RS payment links and representative uploads. Use a separate app
checkout configured against this test database, restored uploads and original
encryption keys. Disable backups, mail delivery, BOG credentials/sync, scheduled
jobs and all outbound integrations in the recovery app. Do not run seed/reset
commands. Run application migrations only after confirming code/schema compatibility.

## Production recovery, only after verification and approval

1. Agree on the recovery timestamp and treatment of transactions created since it.
   Take and verify a fresh safety backup of the current database/uploads first.
2. Enter maintenance mode (`php artisan down`). Pause cron, workers, integrations
   and other writers; maintenance mode alone does not stop arbitrary CLI jobs.
3. Prefer restoring the verified archive into a **new empty production recovery
   database** using the same `createdb`/`pg_restore` procedure with explicit reviewed
   host/name and the application DB owner. Keep the old database intact for rollback.
   Have the DBA review any required roles/extensions/grants. Do not overwrite/drop
   the existing production database as an automatic step.
4. Stage the matching `app/private` and `app/public` files separately, verify
   permissions/content, then switch the storage paths and production `DB_DATABASE`
   (or database in `DB_URL`) to the verified replacement. Preserve encryption keys.
   Run `php artisan config:cache`; restart long-lived application processes and clear
   stale application caches/sessions according to the deployment procedure.
5. Smoke-test login, patient reads, financial totals and upload access while isolated.
   Re-enable cron/integrations and run `php artisan up` only when approved. Keep the
   original database/storage and safety backup until recovery is accepted.

## Recover only selected records

Restore into `renome_restore_check`, compare with current production, and export only
reviewed rows and their required related records. Prepare an explicit, reviewed
transaction/import with column lists, ID/foreign-key handling and duplicate checks;
test it on another current-data copy first. Never replay an entire table dump into
production for selective recovery. Financial entries may need the existing reversal
or reconciliation workflow rather than raw row replacement. This preserves newer
production records instead of rolling back the whole system.

References: [Spatie setup](https://spatie.be/docs/laravel-backup/v10/installation-and-setup),
[Spaces permissions](https://docs.digitalocean.com/products/spaces/how-to/set-file-permissions/),
[PostgreSQL pg_restore](https://www.postgresql.org/docs/current/app-pgrestore.html).
