# Application security hardening — local implementation, review before deployment

No MFA, user migration, password reset, IP-allowlist change or production operation
is included. Existing role definitions and business permissions remain unchanged.
No existing application/database records were inspected or changed during implementation.
Verification uses synthetic users in guarded SQLite `:memory:` databases.

## Authentication compatibility

`App\Filament\Auth\Login` subclasses the existing Filament login page and delegates
authentication to the parent. Its form, credential verification, panel eligibility,
remember-me, session regeneration and redirect workflow are retained. No password
validation rule is added to login, and there is no additional authentication step.

The existing five-attempts-per-minute IP limit remains. Ten failed authentications
for the same normalized email, across IPs, block further attempts until the end
of a five-minute window measured from its first failure. Successful authentication
clears the account counter. Requests during cooldown
do not extend it; they still consume the existing IP allowance. Known, unknown,
inactive and cooling accounts receive the same generic authentication failure.
Limiter keys use an HMAC of the normalized email, never the raw email/password.
Case normalization affects limiter keys only, not existing credential matching.

This release has no hash-rewrite job or credential migration. Laravel's existing
hash verification/maintenance configuration is unchanged; do not change hash
settings as part of deployment. Synthetic legacy eight-character passwords are
tested for all three roles, including unchanged stored hashes, session ID
regeneration, remember-cookie restoration and logout. This is local evidence,
not verification of any real production account.

## Newly created or explicitly changed passwords

User management and the existing profile form share `Password::default()`:
minimum 15 characters and the native uncompromised-password rule. Omitted/blank
unchanged passwords are not revalidated or rewritten. Existing short passwords
continue to authenticate. No forced password change is introduced.

Only the first five SHA-1 hash characters go to the HTTPS Pwned Passwords range
API, with response padding. The check has a one-second connection timeout and a
three-second total timeout. A known breached password is rejected. If the service
is unavailable or malformed, the check fails open while the local length rule
still applies. A generic warning contains no password, email, hash prefix or
exception trace. Login never calls the breach service.

## Confidential responses and sessions

Global response middleware adds `private, no-store, max-age=0, must-revalidate` to
authenticated responses, confidential export routes, login, Livewire POSTs and
application errors. It removes ETag/Last-Modified validators and overrides the
public defaults of file downloads without changing bytes, names or content types.
The exception response hook also handles Laravel-rendered failures. These headers
cannot revoke a document already downloaded to an employee's device or previously
cached elsewhere.

Patient history, treatment-plan and RS report routes retain their existing auth
and policy checks and now also use Laravel's password-change session validation.
Filament's existing equivalent checks remain. Secure session cookies now default
to true when `APP_ENV=production`; explicit deployment overrides are respected.
HttpOnly and SameSite=Lax defaults, session lifetime, CSRF middleware, remember-me
and ordinary logout behavior are retained.

## ERP-only indexing controls

Application responses whose request host is exactly `erp.renome.ge` receive
`X-Robots-Tag: noindex, nofollow, noarchive`. The Filament head hook adds
`<meta name="robots" content="noindex, nofollow">` on that host, including login.
The ERP host also receives `nosniff` and `Referrer-Policy: same-origin` headers.
`renome.ge`, `www.renome.ge` and localhost receive no new noindex directive.

The existing robots.txt intentionally allows crawling so cooperative crawlers can
read noindex. No sitemap is registered or published. Do not replace this with a
blanket crawl block and expect noindex to be seen. Authentication protects data;
robots directives do not. Existing search listings may require Search Console
inspection/removal and recrawling. Noncooperative crawlers can ignore directives.

## Production items requiring manual verification

Before any public-IP rollout, inspect effective runtime configuration and actual
responses on the server. Do not publish secrets or whole configuration dumps.

- `APP_ENV=production`, `APP_DEBUG=false`, correct HTTPS `APP_URL`. Disable/remove
  development packages/routes, including Boost browser logging. Development
  example configuration is not evidence of production settings.
- `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`,
  `SESSION_SAME_SITE=lax`, host-only cookie domain unless deliberately required.
  Check actual session and remember-cookie attributes through the real TLS path.
- Stable existing `APP_KEY`, database credentials and hashing configuration.
  Do not run key generation, credential rotation, seeding or reset commands.
- Shared persistent cache (database/Redis) for all application workers/servers.
  Do not use array/null cache for production throttling. Verify existing cache
  tables/locks and clocks; do not flush the application cache during rollout.
- Inspect proxy topology and client IP handling. Direct Nginx/PHP should forward
  HTTPS correctly. If a proxy/CDN exists, trust only its actual configured
  addresses; never blindly trust all forwarded headers. No wildcard proxy trust
  or forced URL scheme has been introduced without this information.
- Confirm Nginx serves only Laravel's `public` directory, rejects unknown hosts,
  protects dotfiles/private storage/backups/logs and does not expose PHP source.
  Review public storage and signed-link lifetime/access; no storage locations or
  existing file workflows were changed by this release.
- Audit deployed Composer/frontend/OS dependencies. Confirm Nginx/CDN does not
  cache authenticated HTML, Livewire, PDFs, Word files or reports. Purge only
  affected confidential entries if a prior cache was configured improperly.
- Verify TLS, a suitable HSTS policy, monitoring of failed logins/cooldowns and
  unusual downloads, and backup restoration. Do not log passwords or form bodies.

Password-only access still has phishing/stolen-password risk. MFA is intentionally
not implemented. Short existing passwords are not upgraded by this release.
An attacker can temporarily deny a targeted account by repeatedly exhausting its
cooldown. Existing carrier-IP limits are unchanged, not broadened.

## Nginx noindex coverage — proposal only

Application middleware cannot decorate static files, PHP-startup failures or
Nginx-generated denials/errors. Review `nginx -T` privately and add this only to
the existing **erp.renome.ge HTTPS server**, keeping the IP allowlist intact:

```nginx
add_header X-Robots-Tag "noindex, nofollow, noarchive" always;
```

Nginx 1.24 inherits `add_header` only where a child location defines no headers
of its own. Preserve existing security headers and repeat/include the complete
required header set in affected static/PHP/error locations after inspection.
Do not apply this globally or to the public renome.ge server. Duplicate identical
upstream and Nginx noindex headers are redundant; avoid conflicting directives.
Do not add server-wide long-lived caching. Verify login, Laravel 404/403/419/500,
static files and Nginx-generated 403/404 errors. Check HTTP-to-HTTPS redirects too.

Validate with `nginx -t` before any separately approved reload. No server files
were edited, Nginx was not reloaded and the allowlist was not removed.

## Testing, deployment and rollback

Tests refuse deployment configuration caches and any database except SQLite
`:memory:`. Run Pest with a separate nonexistent `APP_CONFIG_CACHE`, never the
Composer test shortcut that clears a deployment cache. The route-cache test uses
a UUID path inside `storage/framework/testing`, exercises a fresh cached login
request, and removes only its own generated file. No deployed route cache is used
or cleared. External breach checks are stubbed for policy tests.

After review, stage the release with synthetic accounts for each role and the
same proxy/cache layout as production. Verify old credentials, invalid login,
cross-IP cooldown and expiry, remember-me/logout, CSRF, revoked/inactive accounts,
direct URLs, Livewire, exports, cross-patient denial, response headers and noindex.
Perform browser/mobile smoke checks for normal clinical/lab/finance workflows.

Back up the current release and server configuration and retain console/SSH
access. Keep production users/credentials intact. No database migration is needed.
Deploy the reviewed release and rebuild configuration/route/view caches for that
release only. Keep the Nginx IP allowlist active throughout validation. Do not
combine this release with hash-setting changes, user resets or IP-policy removal.

If validation fails, retain/restore the allowlist and return to the prior release.
Rebuild that release's configuration/routes/views so cached references to the new
login/middleware do not outlive its code. Preserve APP_KEY, secrets, existing users
and database data. New password changes explicitly made after deployment remain
ordinary valid hashes under either release. Cooldown keys expire naturally; no
bulk cache flush or database rollback is necessary.

GO for local review requires passing focused verification. Removing the IP
allowlist remains NO-GO until live configuration, headers, login/mobile behavior,
monitoring, dependency checks and rollback are verified and password-only risk
is explicitly accepted. Nothing is deployed automatically.

## Local verification results

On 2026-10-30, the combined focused run passed **142 tests / 2,314 assertions**
with no failed or errored tests. It included:

- `SecurityLoginTest`, `SecurityPasswordPolicyTest`, `SecurityHeadersTest`;
- `AuthorizationPhaseTwoTest`, `AuthorizationHardeningTest`,
  `ClinicalAuthorizationTest`, `LabTechnicianAccessTest`,
  `AdministratorAccessTest`, `DoctorCompensationAuthorizationTest`;
- `PatientHistoryExportTest`, `TreatmentPlanExportLanguageTest`,
  `RsUploadSecurityTest`.

The tests cover legacy credentials for all three roles, remember-me restoration,
logout, temporary distributed/IP throttles, strong new/changed passwords,
unchanged password preservation, external breach-service failures, resource/page
role matrices, forged and Livewire requests, inactive/revoked users,
unauthenticated/cross-patient export denial, real PDF/Word export bytes and
caching headers, ERP-only headers/meta, real CSRF enforcement, cookie settings,
route inventory and a fresh HTTP request with an isolated route cache.

Three existing regression tests were adjusted: the positive account-creation
fixture now satisfies the new password policy; resource/page checks enumerate
the current inventory instead of stopping at an obsolete hardcoded count; and
authorized partner-patient view checks verify the existing canonical patient
redirect and its destination. No application permission or routing behavior was
changed for those adjustments.

PHP syntax checks and `git diff --check` passed. Focused Pint validation passed.
The entire application test suite, live production credentials, real mobile
browsers, and production Nginx/proxy/cache/storage/dependency configuration were
not verified. There was no production request, deployment or real-data operation.

## Complete changed-file inventory

Application/configuration (existing):

- `app/Filament/Resources/Users/UserResource.php`
- `app/Providers/AppServiceProvider.php`
- `app/Providers/Filament/AdminPanelProvider.php`
- `bootstrap/app.php`
- `config/session.php`
- `routes/web.php`

Application/view (new):

- `app/Filament/Auth/Login.php`
- `app/Http/Middleware/SecurityResponseHeaders.php`
- `app/Validation/BoundedPasswordVerifier.php`
- `resources/views/filament/security-meta.blade.php`

Existing regression tests:

- `tests/Feature/AdministratorAccessTest.php`
- `tests/Feature/AuthorizationPhaseTwoTest.php`
- `tests/Feature/ClinicalAuthorizationTest.php`

New regression tests and documentation:

- `tests/Feature/SecurityLoginTest.php`
- `tests/Feature/SecurityPasswordPolicyTest.php`
- `tests/Feature/SecurityHeadersTest.php`
- `docs/security-hardening.md`
