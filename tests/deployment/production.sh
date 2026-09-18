#!/usr/bin/env bash
# Hermetic shell tests: all Git, PHP, package, service and HTTP operations are fakes.
set -Eeuo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
scratch_parent=$(cd "${TMPDIR:-/tmp}" && pwd -P)
sandbox=$(mktemp -d "$scratch_parent/renome-deploy-test.XXXXXXXX")
cleanup() {
    # Only the exact resolved temporary child made by this test may be removed.
    [[ "$sandbox" == "$scratch_parent"/renome-deploy-test.* && -d "$sandbox" ]] || return
    rm -rf -- "$sandbox"
}
trap cleanup EXIT
mkdir -p "$sandbox/bin"
cat > "$sandbox/bin/mock" <<'MOCK'
#!/usr/bin/env bash
set -eu
name=$(basename "$0")
printf '%s %s\n' "$name" "$*" >> "$DEPLOY_FIXTURE/trace"
if [[ "$name $*" == "$MOCK_FAIL" ]]; then
    echo 'PRIVATE_SERVER_DIAGNOSTIC' >&2
    exit 1
fi
case "$name $*" in
    'git rev-parse --show-toplevel') echo /var/www/renome-clinic ;;
    'git status --porcelain --untracked-files=all') [[ "$MOCK_DIRTY" != true ]] || echo '?? server-change.php' ;;
    'git branch --show-current') echo main ;;
    'git ls-files -- .env') [[ "$MOCK_ENV_TRACKED" != true ]] || echo .env ;;
    'git rev-parse HEAD') echo aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ;;
    'git rev-parse FETCH_HEAD') echo "$MOCK_TARGET" ;;
    'git cat-file -e FETCH_HEAD:.env') [[ "$MOCK_INCOMING_ENV" == true ]] ;;
    'systemctl show --property=LoadState --value php8.4-fpm.service') echo loaded ;;
    'php artisan down --render=errors::503 --retry=60') touch "$DEPLOY_FIXTURE/storage/framework/down" ;;
    'php artisan up') rm -f -- "$DEPLOY_FIXTURE/storage/framework/down" ;;
    'npm run build') mkdir -p public/build; printf '{}' > public/build/manifest.json ;;
esac
MOCK
chmod +x "$sandbox/bin/mock"
for command in git php composer npm node flock systemctl sudo curl; do cp "$sandbox/bin/mock" "$sandbox/bin/$command"; done
# Redirect only the deployment's fixed application path into our isolated fixture.
cd() {
    if [[ "${1:-}" == /var/www/renome-clinic ]]; then builtin cd "$DEPLOY_FIXTURE"; else builtin cd "$@"; fi
}
export -f cd
export PATH="$sandbox/bin:$PATH"
count=0
run_case() {
    label=$1 mode=$2 should_pass=$3
    count=$((count + 1))
    export DEPLOY_FIXTURE="$sandbox/case-$count"
    mkdir -p "$DEPLOY_FIXTURE"/{.git,vendor,storage/logs,storage/framework,bootstrap/cache,public}
    printf 'DO_NOT_REPLACE\n' > "$DEPLOY_FIXTURE/.env"
    touch "$DEPLOY_FIXTURE"/{vendor/autoload.php,composer.lock,package-lock.json}
    export MOCK_FAIL=${4:-} MOCK_DIRTY=${5:-false} MOCK_TARGET=${6:-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa}
    export MOCK_ENV_TRACKED=${7:-false} MOCK_INCOMING_ENV=${8:-false}
    result=0
    bash "$root/scripts/deploy-production.sh" "$mode" php8.4-fpm.service aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa > "$DEPLOY_FIXTURE/output" 2>&1 || result=$?
    if [[ "$should_pass" == true ]]; then [[ "$result" == 0 ]]; else [[ "$result" != 0 ]]; fi
    [[ $(cat "$DEPLOY_FIXTURE/.env") == DO_NOT_REPLACE ]]
    ! grep -q 'PRIVATE_SERVER_DIAGNOSTIC' "$DEPLOY_FIXTURE/output"
    ! grep -Eq 'git (reset|stash|clean)|migrate:(fresh|refresh)|db:(wipe|seed)|composer update|npm install' "$DEPLOY_FIXTURE/trace"
    printf 'PASS: %s\n' "$label"
}

run_case 'read-only preflight' preflight true
! grep -Eq 'git fetch|artisan|composer install|npm ci|sudo -n /usr/bin/systemctl' "$DEPLOY_FIXTURE/trace"
run_case 'untracked/dirty tree stops before fetch or maintenance' deploy false '' true
! grep -Eq 'git fetch|artisan|composer install' "$DEPLOY_FIXTURE/trace"
run_case 'tracked environment file rejected' deploy false '' false aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa true
run_case 'failed status check stops safely' deploy false 'git status --porcelain --untracked-files=all'
! grep -Eq 'git fetch|artisan' "$DEPLOY_FIXTURE/trace"
run_case 'incoming tracked environment file rejected' deploy false '' false aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa false true
run_case 'newer main skips stale deployment' deploy true '' false bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb
! grep -q 'artisan' "$DEPLOY_FIXTURE/trace"
run_case 'divergent production stops before maintenance' deploy false 'git merge-base --is-ancestor HEAD FETCH_HEAD'
! grep -q 'artisan' "$DEPLOY_FIXTURE/trace"
for failure in \
    'git fetch origin main' \
    'php artisan config:clear' \
    'git checkout main' \
    'git pull --ff-only origin main' \
    'composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader' \
    'npm ci --include=dev --no-audit --no-fund' \
    'npm run build' \
    'php artisan migrate --force --no-interaction' \
    'php artisan route:cache' \
    'sudo -n /usr/bin/systemctl reload php8.4-fpm.service'; do
    run_case "stop on $failure" deploy false "$failure"
    ! grep -q 'php artisan up' "$DEPLOY_FIXTURE/trace"
    if [[ "$failure" != 'git fetch origin main' ]]; then [[ -f "$DEPLOY_FIXTURE/storage/framework/down" ]]; fi
done
run_case 'successful ordered deployment' deploy true
[[ ! -e "$DEPLOY_FIXTURE/storage/framework/down" ]]
grep -q 'Deployment complete' "$DEPLOY_FIXTURE/output"
migrate_line=$(grep -n 'php artisan migrate' "$DEPLOY_FIXTURE/trace" | cut -d: -f1)
build_line=$(grep -n 'npm run build' "$DEPLOY_FIXTURE/trace" | cut -d: -f1)
(( build_line < migrate_line ))
run_case 'failed health check returns to maintenance' deploy false 'curl --fail --silent --output /dev/null --max-time 20 https://erp.renome.ge/up'
[[ -f "$DEPLOY_FIXTURE/storage/framework/down" ]]
printf '%s deployment safety scenarios passed (no real server or database used).\n' "$count"
