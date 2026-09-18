#!/usr/bin/env bash
# Sent over SSH by the workflow. Never source .env or enable shell tracing.
set -Eeuo pipefail
umask 027
export GIT_TERMINAL_PROMPT=0

mode=${1:-preflight}
fpm_service=${2:?Pass the verified PHP-FPM systemd service name}
expected_sha=${3:?Pass the main commit SHA from the workflow}
[[ "$mode" == deploy || "$mode" == preflight ]]
[[ "$fpm_service" =~ ^[a-zA-Z0-9][a-zA-Z0-9_.@-]*\.service$ ]]
[[ "$expected_sha" =~ ^[a-f0-9]{40}$ ]]

stage=preflight
maintenance_started=false
deploy_log=''
fail() { printf 'Deployment stopped: %s\n' "$1" >&2; exit 1; }
on_exit() {
    code=$?
    if (( code != 0 )); then
        printf 'Failed during: %s. No reset, stash, or database rollback was attempted.\n' "$stage" >&2
        if [[ "$maintenance_started" == true ]]; then
            printf 'Maintenance remains enabled. Review the server log and repair before running artisan up.\n' >&2
        fi
        if [[ -n "$deploy_log" ]]; then printf 'Private server log: %s\n' "$deploy_log" >&2; fi
    fi
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP

cd /var/www/renome-clinic
[[ $(git rev-parse --show-toplevel) == /var/www/renome-clinic ]] || fail 'Wrong repository path.'
for command in git php composer npm node flock systemctl sudo curl; do
    command -v "$command" > /dev/null || fail "Missing executable: $command"
done

# Also serialize manual invocations on this server. Lock is outside the working tree.
exec 9>.git/renome-deploy.lock
flock -n 9 || fail 'Another deployment is running on the server.'
assert_clean() {
    local status
    status=$(git status --porcelain --untracked-files=all 2>/dev/null) || fail 'Cannot inspect production git status.'
    [[ -z "$status" ]] || fail 'Production working tree is dirty (including untracked files). Inspect git status over SSH; preserve and resolve changes manually.'
}
assert_clean
[[ $(git branch --show-current) == main ]] || fail 'Production must already be on main; review the current branch manually.'
tracked_env=$(git ls-files -- .env)
[[ -z "$tracked_env" ]] || fail '.env is tracked; stop and resolve this before deployment.'
git check-ignore -q .env || fail '.env must be gitignored.'
[[ -r .env && -f vendor/autoload.php && -f composer.lock && -f package-lock.json ]] || fail 'Existing installation, .env, and both lockfiles are required.'
[[ ! -e storage/framework/down && ! -e storage/framework/maintenance.php ]] || fail 'Maintenance is already active; repair the previous deployment manually first.'
[[ ! -e public/hot ]] || fail 'Remove the stale Vite development hot file manually after checking the server setup.'
for directory in storage storage/logs storage/framework bootstrap/cache public; do
    [[ -d "$directory" && -w "$directory" ]] || fail "Deployment user cannot write $directory; fix targeted ownership/ACLs during setup."
done
php -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 4 ? 0 : 1);' || fail 'CLI PHP must be 8.4, matching FPM.'
node -e 'const [a,b]=process.versions.node.split(".").map(Number); process.exit(a>22 || (a===22 && b>=13) ? 0 : 1)' || fail 'Use Node 22.13+ (Node 24 LTS recommended).'
# Parse only: never bootstrap the app or print environment values in preflight.
php -r '
require "vendor/autoload.php";
try {
    $env = Dotenv\Dotenv::parse(file_get_contents(".env"));
    $ok = ($env["APP_ENV"] ?? "") === "production"
        && in_array(strtolower($env["APP_DEBUG"] ?? ""), ["false", "(false)", "0"], true)
        && !empty($env["APP_KEY"])
        && ($env["APP_MAINTENANCE_DRIVER"] ?? "file") === "file";
    exit($ok ? 0 : 1);
} catch (Throwable $e) { exit(1); }
' || fail 'Check production APP_ENV, APP_DEBUG=false, existing APP_KEY, and file maintenance driver privately on the server.'
[[ $(systemctl show --property=LoadState --value "$fpm_service") == loaded ]] || fail 'Configured FPM unit does not exist.'
systemctl is-active --quiet "$fpm_service" || fail 'Configured FPM unit is not active.'
sudo -n -l /usr/bin/systemctl reload "$fpm_service" > /dev/null 2>&1 || fail 'Missing narrowly scoped passwordless FPM reload permission.'
GIT_TERMINAL_PROMPT=0 git ls-remote --exit-code origin refs/heads/main > /dev/null 2>&1 || fail 'Server cannot read origin/main with its own repository credentials.'
if [[ "$mode" == preflight ]]; then
    printf 'Preflight passed. No code, dependencies, migrations, caches, or services changed.\n'
    exit 0
fi

# Keep command output on the server: migration exceptions may contain sensitive data.
deploy_log=$(mktemp storage/logs/deploy-XXXXXXXX.log)
chmod 600 "$deploy_log"
run() {
    stage=$1
    shift
    printf '==> %s\n' "$stage"
    "$@" >> "$deploy_log" 2>&1
}
before_sha=$(git rev-parse HEAD)
env_hash=$(sha256sum .env)
run 'Fetch main' git fetch origin main
target_sha=$(git rev-parse FETCH_HEAD)
if [[ "$target_sha" != "$expected_sha" ]]; then
    printf 'A newer main commit superseded this run; leaving production unchanged.\n'
    exit 0
fi
git merge-base --is-ancestor HEAD FETCH_HEAD || fail 'Production has diverged or contains unpushed commits; fast-forward is unsafe.'
if git cat-file -e FETCH_HEAD:.env 2>/dev/null; then fail 'Incoming main tracks .env; refusing deployment.'; fi
assert_clean
printf 'Deploying %s -> %s\n' "$before_sha" "$target_sha"
maintenance_started=true
run 'Enable maintenance' php artisan down --render=errors::503 --retry=60
# Clear old class/route/config caches while old code and dependencies still agree.
for command in config:clear route:clear event:clear view:clear clear-compiled filament:clear-cached-components icons:clear; do
    run "$command" php artisan "$command"
done
run 'Checkout main' git checkout main
run 'Fast-forward main' git pull --ff-only origin main
[[ $(git rev-parse HEAD) == "$expected_sha" ]] || fail 'Main changed during pull; inspect and retry the latest workflow.'
[[ $(sha256sum .env) == "$env_hash" ]] || fail '.env changed unexpectedly.'
run 'Install locked Composer dependencies' composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
run 'Install locked frontend dependencies' npm ci --include=dev --no-audit --no-fund
run 'Build Vite assets' npm run build
[[ -s public/build/manifest.json ]] || fail 'Vite manifest is missing.'
# Fail before touching the schema if package scripts unexpectedly modify tracked assets.
assert_clean
run 'Migrate production' php artisan migrate --force --no-interaction
# Individual calls propagate failures, unlike this version's aggregate optimize command.
# Application cache is preserved (no cache:clear / optimize:clear).
for command in config:cache event:cache route:cache view:cache filament:cache-components icons:cache; do
    run "$command" php artisan "$command"
done
run 'Notify queue workers to reload' php artisan queue:restart
[[ $(sha256sum .env) == "$env_hash" ]] || fail '.env changed unexpectedly.'
assert_clean
run 'Reload verified PHP-FPM unit' sudo -n /usr/bin/systemctl reload "$fpm_service"
run 'Leave maintenance' php artisan up
maintenance_started=false
if ! curl --fail --silent --output /dev/null --max-time 20 https://erp.renome.ge/up; then
    maintenance_started=true
    run 'Restore maintenance after failed health check' php artisan down --render=errors::503 --retry=60
    fail 'Public /up health check failed. Inspect deployment and routing before reopening.'
fi
printf 'Deployment complete: %s\n' "$expected_sha"
