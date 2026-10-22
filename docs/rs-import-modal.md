# RS upload and startup diagnostics

The previous action validated/dehydrated the Filament upload, then called
`RsHistoricalImport::start()`. That method parsed every source row into SQLite;
the action then processed the first 500 purchase rows before returning. Filament
could only close the modal after that request completed. Startup exceptions were
not converted into notifications. In particular, service validation used `file`
instead of the mounted action field path `mountedActions.0.data.file`.

On 2026-10-22, production logs confirmed the actual blocker: the old checkpoint
architecture required PDO SQLite. Both supplied file sizes were below the upload
limit. This dependency has been removed; see [checkpoint storage](rs-import-checkpoints.md).

The updated action retains generated private filenames and locked upload-path
authorization. It validates, creates an Owner-bound checkpoint, streams a private
copy of the source into the checkpoint directory, saves the run ID in the session,
and returns. Filament closes the modal and renders progress with phase `preparing`.
The next poll stages source rows through the existing streaming CSV/XLSX reader,
then imports at most 500 rows, using the existing 100-row database transactions.
Subsequent polls resume the checkpoint. Preparation still requires a complete
streaming pass; it now happens after visible progress, and failures pause the run
with a resume notification. It is not a background queue job. Existing staged
Laravel checkpoints are recovered by Owner from the database, independently of
the session. Legacy SQLite checkpoints are ignored. Source copies remain private
until completed-run retention cleanup; active sources are never age-pruned.

Validation/dehydration/startup failures now produce persistent Georgian error
notifications. FilePond errors (including failures before an action request reaches
PHP) also produce a notification. Startup logs include user ID, available original
filename/extension/MIME/size, temporary-file availability, failure stage, and
exception class/message. Missing metadata is recorded as null; file contents are
never logged by this diagnostic. HTTP requests rejected by nginx cannot generate
application logs: inspect the nginx error log and browser network response too.

## Upload limits and production checks

The application/parser ceiling remains 10 MiB (10,240 KiB). Livewire's default
temporary-upload ceiling is 12 MiB. The form enforces the minimum of 10 MiB,
any lower configured Livewire `max` rule, the running PHP SAPI's
`upload_max_filesize`, and `post_max_size` less 64 KiB
for multipart overhead, and displays that effective value. A reverse proxy's
limit cannot be introspected by PHP; its rejection is reported as an upload error.

Local Herd PHP 8.4 CLI inspection on 2026-10-21 returned:

```
upload_max_filesize = 2M
post_max_size = 8M
```

That gives a local form ceiling of 2 MiB. This is not evidence of production's
PHP-FPM settings. Inspect the web SAPI/FPM pool overrides and active nginx server
block, not only `php -i` from the CLI. Also check cached Livewire upload rules or
a nonlocal temporary upload disk override and storage permissions.

To support the full 10 MiB upload, configure the active production PHP-FPM ini:

```ini
upload_max_filesize = 10M
post_max_size = 12M
```

And the applicable nginx server/location:

```nginx
client_max_body_size 12m;
```

After reviewing the actual service names, an Ubuntu PHP 8.4 example is:

```sh
sudo nginx -t
sudo systemctl reload nginx
sudo systemctl reload php8.4-fpm
php artisan optimize:clear
```

These commands were not run. Substitute the installed FPM version. Rebuild
deployment caches using the normal release process. Apply the additive checkpoint
migration described in [checkpoint storage](rs-import-checkpoints.md), and check the app user can
write `storage/app/private/purchase-imports`, `purchase-import-workspaces`, the
Livewire temporary upload directory, and the private error-report directory.
No reset/reseed or historical repair is required.

## Previous local verification (2026-10-21)

For the PostgreSQL backend and no-SQLite verification on 2026-10-22, see
[checkpoint storage](rs-import-checkpoints.md).

- RS modal/upload/security/full-year tests: 42 passed, 1 optional private-file test
  skipped (488 assertions). Includes CSV/XLSX callbacks, modal closure, copied
  source/checkpoint, progress, expired temporary files, rejected formats/sizes,
  source-copy failure, preflight failure, reload/resume and 5,000-row retries.
- Other RS/parser/Purchase regressions: 194 passed, 1 optional private-file test
  skipped, 1 unrelated existing failure in `RsDirectionUiTest`: its direction
  selector assertion expects 7 entries while the unchanged catalog returns 8.
- PHP syntax, Pint and `git diff --check`: passed.
- Tests used SQLite in memory and fake private storage. The combined suite needed
  a test-process-only 512 MiB memory limit rather than the local CLI's 128 MiB
  default. No application database, production import, deployment or server
  configuration was changed.
