# Treatment Plan production investigation — 2026-09-23

## Confirmed findings

Local source revision: `d19832a` (`Update treatment plan modal and multilingual exports`).
No production application code, configuration, caches, or files were changed during this investigation.
Production SSH/console access and the production Laravel exception have not been supplied.

Public production checks against `https://erp.renome.ge/login` returned HTTP 200:

| Check | Production | Local |
| --- | --- | --- |
| Theme referenced by HTML / local manifest | `theme-CQUfnJpb.css` | `theme-BNAo2dx5.css` |
| `renome-plan-document` styles | Missing | Present |
| `renome-plan-document__table` styles | Missing | Present |
| `renome-visit-badges` styles | Missing | Present |
| `renome-visit-plan-chip` styles | Missing | Present |

Production CSS returned `Last-Modified: Sat, 19 Sep 2026 07:28:19 GMT` and
`Cache-Control: public, max-age=31536000, immutable`.
This proves production is referencing a stylesheet without the current modal/chip rules.
It does not prove which Blade/PHP revision is deployed or whether its manifest, cached HTML,
release symlink, or asset publication step is responsible for selecting that old stylesheet.
A browser hard refresh alone cannot add rules missing from the actual served CSS file.

`public/build` is Git-ignored. Deploying source with `git pull` alone does not publish
the local Vite build. Publish the complete matching build directory and manifest together.
Keep older hashed assets during the normal release transition so existing pages still load.

## Local environment baseline

- PHP CLI 8.4.24, Windows; memory limit 128M. The installed Composer platform guard requires
  PHP >=8.4.1 (including the local development dependency set). Production must satisfy
  the exact locked **non-development** dependencies, checked with `composer check-platform-reqs --no-dev`.
  `composer.json` alone declares PHP ^8.3; do not use that alone to infer the actual platform minimum.
- Laravel v13.26.1, Filament v5.7.6, laravel-dompdf v3.1.2, dompdf v3.1.6,
  php-font-lib 1.0.2, PHPWord 1.4.0; installed versions match the lockfile locally.
- Required locked extensions are present locally, including dom, mbstring, xml, xmlreader,
  simplexml, zip, gd, iconv, intl, fileinfo, and the Laravel core requirements.
- Node 24.18.0. Installed Vite requires `^20.19.0 || >=22.12.0`.
  Vite and Tailwind are development dependencies needed on the **build host**, not by PHP-FPM.
- `storage`, `storage/fonts`, `storage/framework/views`, `bootstrap/cache`, and the PHP temporary
  directory are writable locally. Dompdf uses `storage/fonts` for font files/cache;
  DOCX uses `tempnam(sys_get_temp_dir(), 'estimate-')` and ZIP output.
- Both formats call `TreatmentEstimateExportService::unicodeExportFont()` before producing output.
  Local selection is Windows Segoe UI regular/bold. Production Linux must have readable fonts
  at the configured paths or accepted Linux fallback paths, with Georgian, Latin, Cyrillic,
  and U+20BE (lari) glyphs. Word currently checks the font too, so this prerequisite applies
  to **both** formats. No LibreOffice or wkhtmltopdf binary is required by this implementation.
- Local config/routes are not cached; no `public/hot` file is present.

Potential export causes remain **unconfirmed**. In particular, custom `EXPORT_UNICODE_*`
settings are currently read through `env()` in the service. If they exist only in `.env`,
cached configuration can affect their availability. Do not change the exporter based on
this possibility without checking the production exception and configuration state.

## Collect production evidence before fixing exports

From the actual deployed application directory (documentation suggests `/var/www/renome-clinic`;
confirm the active release and PHP-FPM document root first):

```bash
git rev-parse HEAD
php -v
php --ini
php -m
composer check-platform-reqs --no-dev
node --version
php scripts/diagnose-treatment-plan.php
php artisan route:list --path=treatment-estimates
```

The added diagnostic script is CLI-only and read-only. It checks package versions, relevant
source/build hashes, extension availability, directory permissions, cached-config/routes status,
and invokes the existing font resolver without accessing patient records or exporting a plan.
Copy this script into the existing release if investigating before deploying newer application code.
If dependencies prevent Laravel bootstrapping, capture that exception and check the PHP-FPM error log.

Run the permission/font checks as the actual PHP-FPM worker user, not only root/deploy user.
Also verify the **PHP-FPM** version, loaded extensions, ini files, `open_basedir`, temporary
directory, and memory limit; CLI output does not establish the web runtime configuration.
Compare Git revisions first: byte hashes may also differ because of line-ending conversions.

Reproduce one PDF and one Word request and record their timestamps. Inspect the corresponding
Laravel log channel (`storage/logs/laravel.log`, daily logs, or the configured service log),
including the exception class, message, application file/line, and first stack frames.
The local Laravel log contains Windows/local errors and is not a production trace.
Share only the relevant exception, omitting patient data and secrets.

The exact export 500 cause is **not yet identified**; do not apply a guessed font, permission,
dependency, or exporter rewrite. Use the observed exception to choose the smallest correction.
Avoid broad `chmod 777`, blanket filesystem ownership changes, or `composer update`.

## Asset remediation after deploying matching source

Run on the release/build host with a compatible Node version:

```bash
npm ci --include=dev
npm run build
```

If building elsewhere, publish the resulting `public/build` directory with the matching release.
If publishing on the production host, run in the intended release directory. Ensure the live
document root points at that release and is serving its `public/build/manifest.json` assets.
Then, as the normal application deployment user:

```bash
php artisan view:clear
```

If deploying changed dependencies, use the locked install, not an update:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
composer check-platform-reqs --no-dev
```

If inspection confirms stale configuration/routes, clear only those caches after recording
the diagnostic state (`php artisan config:clear`, `php artisan route:clear`). Rebuild them
according to the normal deployment policy only after exports are verified under the intended
cached configuration. Reload the identified PHP-FPM service if OPcache retains old PHP/Blade
code; do not guess its service name or restart unrelated services.

Verify the fresh login/Dashboard HTML references the new hashed CSS and that the served file
contains the three selector groups above. Preserve immutable caching for hashed files;
if a proxy/CDN caches old dynamic HTML, invalidate that HTML rather than replacing bytes under
an old immutable asset filename. Finally verify the authenticated modal, PDF, and DOCX using
the same plan. No UI redesign, calculation change, or database migration is called for by the
confirmed asset mismatch.

## Files changed in this investigation

- `scripts/diagnose-treatment-plan.php` — read-only comparison tool, successfully run locally.
- This report — evidence, outstanding production checks, and conditional deployment commands.

Application UI and export logic are unchanged. Production remediation and the export root-cause
analysis remain pending production access/logs.
