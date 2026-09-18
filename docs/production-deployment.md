# Production deployment

Nothing is deployed by adding these files locally. Deployment starts only after the workflow is committed/pushed, server setup is complete, and the repository variable `PROD_DEPLOY_ENABLED` is set to `true`. Do not enable it until production changes and pending migrations have been reviewed.

The workflow uses GitHub-hosted Ubuntu, OpenSSH and the existing `/var/www/renome-clinic` checkout. It does not replace `.env`, seed/reset data, touch stashes, restart nginx, or change application permissions. No production credentials belong in this repository.

## GitHub settings

Create a **production** environment in Settings → Environments. Restrict deployment branches to `main`. Optional required reviewers provide a deployment approval gate; omit them if fully automatic deployment is intended.

Add these **environment secrets** (repository secrets also work):

| Secret | Exact content |
| --- | --- |
| `PROD_HOST` | Ubuntu server DNS name or IPv4 address, without `https://` or a path |
| `PROD_USER` | Dedicated non-root deployment account, e.g. `renome-deploy` |
| `PROD_PORT` | SSH port; omit to use `22` |
| `PROD_SSH_KEY` | Complete private OpenSSH key, including BEGIN/END lines and real newlines |
| `PROD_KNOWN_HOSTS` | Verified OpenSSH known_hosts line(s) for that exact host/port; see below |

Add **repository variable** `PROD_DEPLOY_ENABLED=false` initially. Set it to `true` only when ready for automatic deployment. It must be repository-level because the job condition is evaluated before environment variables are loaded.

Add variable `PROD_FPM_SERVICE` (environment or repository-level): the **verified** unit name, including `.service`, e.g. `php8.4-fpm.service`. No default is assumed. The script verifies the unit exists and is active before changing anything. The actual production service cannot be verified from this local checkout.

Keep DB, APP_KEY, BOG and other application credentials solely in the existing server `.env`. It is already gitignored and is checked again before deployment. Do not run `composer setup` or `key:generate` on production.

## Dedicated SSH keys

On a trusted administrative computer, generate a new key used only for Actions → production:

```bash
ssh-keygen -t ed25519 -C renome-actions-production -f renome-actions-production -N ''
```

Put the **private** file in `PROD_SSH_KEY`, not Git. Add the single line from `renome-actions-production.pub` to `/home/renome-deploy/.ssh/authorized_keys` on Ubuntu, prefixed with `restrict ` (OpenSSH disables forwarding and PTY; noninteractive commands remain available). Preserve existing authorized keys.

One-time Ubuntu setup, performed by an administrator (replace the username with the actual account):

```bash
sudo adduser --disabled-password --gecos '' renome-deploy
sudo install -d -m 700 -o renome-deploy -g renome-deploy /home/renome-deploy/.ssh
sudo -u renome-deploy touch /home/renome-deploy/.ssh/authorized_keys
sudo chmod 600 /home/renome-deploy/.ssh/authorized_keys
sudoedit /home/renome-deploy/.ssh/authorized_keys
```

For `PROD_KNOWN_HOSTS`, obtain host keys from the trusted server console, e.g. `/etc/ssh/ssh_host_ed25519_key.pub`, and compare their fingerprints with `ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub`. From the trusted computer:

```bash
ssh-keyscan -p 22 YOUR_PROD_HOST > renome-known-hosts
ssh-keygen -lf renome-known-hosts
```

Verify against console fingerprints before storing the matching line(s). For a nonstandard port, keep the generated `[hostname]:port` syntax. Do not trust an unverified keyscan result or turn off host checking. Key scanning is not performed in the workflow.

Production → GitHub uses a **different** read-only repository key. Keep the server's existing working repository access if available. Otherwise, as the deployment user, generate a separate key in `~/.ssh`, register its public key as a read-only Deploy Key in the GitHub repository, and configure its SSH `IdentityFile` for github.com. Verify GitHub's host key using GitHub's published fingerprints. The Actions-to-server key is not forwarded or reused for repository access.

## One-time server checks

Run from the existing deployment user's login shell; do not initialize a second checkout:

```bash
cd /var/www/renome-clinic
git status --porcelain --untracked-files=all
git branch --show-current
git check-ignore .env
git ls-files -- .env
git ls-remote --exit-code origin refs/heads/main
php -v
composer --version
node --version
npm --version
systemctl list-units --type=service --all 'php*fpm*'
```

`git status` and `git ls-files -- .env` must be empty; branch must be `main`. Preserve server changes, review them, and integrate them deliberately before enabling deployment. Never reset, clean or automatically stash production to make this check pass. Existing stashes are untouched.

Verify the actual FPM unit and nginx socket configuration privately on the server. CLI PHP must be **8.4**, matching FPM. Install Composer 2 and Node **24 LTS** (minimum supported here: 22.13), npm, Git, curl and util-linux (`flock`) via your normal Ubuntu administration process. They must be in the noninteractive SSH user's PATH; a shell-only nvm alias is insufficient. Confirm required PHP extensions with `composer check-platform-reqs --no-dev`.

Production `.env` must retain its existing APP_KEY and credentials, with `APP_ENV=production`, `APP_DEBUG=false`, and the existing/default file maintenance driver. Review pending migrations with `php artisan migrate:status` and maintain a verified database/upload backup and restore procedure before enabling automation. `migrate --force` runs pending migrations: it cannot guarantee future migration code is nondestructive. Review migration changes before merging to main.

Allow **only** the verified FPM reload through sudo. For the example unit, create a sudoers file with `sudo visudo -f /etc/sudoers.d/renome-deploy`:

```sudoers
renome-deploy ALL=(root) NOPASSWD: /usr/bin/systemctl reload php8.4-fpm.service
```

Use the actual verified unit in both sudoers and `PROD_FPM_SERVICE`; check the systemctl path with `command -v systemctl`. Do not grant blanket passwordless sudo.

The deployment account needs ownership/write access to the existing Git checkout, `.git`, dependencies and generated `public` assets. Inspect existing ownership before assigning it; no broad ownership change is performed automatically. Both the deploy user and the **actual** FPM pool user need access to `storage` and `bootstrap/cache`. If targeted ACL repair is needed, first confirm the resolved paths stay inside the application, then an administrator may run (replace `www-data` with the verified FPM user):

```bash
realpath /var/www/renome-clinic/storage /var/www/renome-clinic/bootstrap/cache
sudo setfacl -R -m u:renome-deploy:rwX,u:www-data:rwX /var/www/renome-clinic/storage /var/www/renome-clinic/bootstrap/cache
sudo find /var/www/renome-clinic/storage /var/www/renome-clinic/bootstrap/cache -type d -exec setfacl -m d:u:renome-deploy:rwx,d:u:www-data:rwx {} +
```

This is a one-time conditional repair, not a deployment step. No `chmod 777` is needed. Verify nginx/FPM can read newly built assets. If queue workers exist, their supervisor should restart exited workers, they must respect maintenance mode (no `--force`), and long-running in-flight work should be drained before schema changes. No queue system is installed by this workflow.

## What each deployment does

1. Serialize Actions runs (`cancel-in-progress: false`) and acquire a server `flock`.
2. Verify clean tracked/untracked state, `.env` exclusion, toolchain, permissions, maintenance state, production configuration, repository access and verified FPM unit/sudo rule.
3. Fetch main. Skip a superseded queued SHA; reject divergent/unpushed server commits and an incoming tracked `.env`.
4. Enter maintenance and clear old generated configuration/routes/views/component caches while old code and dependencies agree.
5. `git checkout main` then `git pull --ff-only origin main`. Verify the resulting SHA matches the workflow; preserve `.env`.
6. `composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader`.
7. `npm ci --include=dev --no-audit --no-fund` then `npm run build`; verify the manifest. Build happens before migrations so a failed frontend build does not alter the schema.
8. `php artisan migrate --force --no-interaction`.
9. Run `config:cache`, `event:cache`, `route:cache`, `view:cache`, `filament:cache-components`, `icons:cache` individually; signal `queue:restart`.
10. Reload the verified PHP-FPM service, leave maintenance, and check `https://erp.renome.ge/up` without printing response contents.

No nginx reload. No seeding/reset/test commands. No application-cache purge: this Laravel 13 version's `optimize:clear` also clears application cache, and aggregate optimization handlers can return success despite child failures. Individual cache calls avoid both problems and duplicate work.

Vite builds the app CSS, Filament theme and JS. `public/build` is gitignored. Production's current asset-build procedure could not be inspected remotely; this pipeline explicitly establishes an on-server locked build. CI-built artifacts could later shorten maintenance, but Tailwind/Filament scanning requires the matching Composer dependencies and an artifact transfer strategy. This initial version keeps one build path. Vite's configured Bunny font plugin also needs outbound internet during build.

**Tracked Filament assets:** Composer's existing `filament:upgrade` hook republishes assets into tracked `public/js/filament` and `public/css/filament` files. If it changes them, deployment stops before migrations and preserves them. Generate and commit the corresponding assets locally with dependency upgrades. Do not discard production differences automatically; otherwise the next deployment would overwrite unexplained server changes.

## Safe first test and failure recovery

Commit these files after review; no deployment has been run as part of setup. Keep `PROD_DEPLOY_ENABLED=false`. In Actions → Deploy production → Run workflow, choose **main** and leave **preflight_only checked**. This validates SSH, keys and readiness without fetching/updating Git, entering maintenance, executing Artisan, installing packages or changing the DB. The lock file in `.git` is the only persistent preflight file; preflight is not an end-to-end deployment test.

Test a full run against a staging copy with its own credentials/data first. Use the same installation path there and a staging-only script copy with its health-check URL pointing to staging; never use production secrets/data for that test. Then, after reviewing the main commit, backups and pending migrations, set `PROD_DEPLOY_ENABLED=true` and manually run with **preflight_only unchecked** for the first real production deployment. Check `/login`, role-specific landing pages, Lab and Finance afterward; `/up` is only a boot health check.

Any important failure stops immediately. If failure occurs after entering maintenance, maintenance remains enabled; an automatic `artisan up` in a failure trap would expose partially updated code/schema. A failed post-deployment health check attempts to re-enable maintenance. The action prints the failed stage and a server-only log path (`storage/logs/deploy-*.log`, mode 600), not dependency/migration output or `.env` contents. Inspect that log privately. Retain/rotate these logs according to the server's existing policy.

This is an in-place, maintenance-window deployment, not an atomic release/automatic rollback system. Code/dependencies or some migrations may already have changed on failure. Do not blindly roll back migrations or run a reset. Diagnose the failure, complete the intended version safely, rebuild caches/reload FPM as appropriate, then run `php artisan up` once the application is consistent. Fix any generated Git changes deliberately. A retry refuses an already-maintained application to avoid overriding an operator's maintenance state.

To disable automatic deployments quickly, set repository variable `PROD_DEPLOY_ENABLED=false` or disable this workflow in the Actions menu. This does not interrupt an already running deployment. Avoid cancelling an active deployment mid-migration; cancellation can leave maintenance enabled and requires inspection.

After successful setup, the usual flow is local commit → `git push origin main` → automatic deployment. Review/stage only intended changes; never stage `.env` or private keys. Protect `main` and review changes to workflows, scripts, Composer scripts and migrations because deployment executes trusted main-branch code.

References: [GitHub deployment controls](https://docs.github.com/en/actions/how-tos/deploy/configure-and-manage-deployments/control-deployments), [Laravel deployment](https://laravel.com/docs/13.x/deployment), [GitHub SSH fingerprints](https://docs.github.com/en/authentication/keeping-your-account-and-data-secure/githubs-ssh-key-fingerprints).
