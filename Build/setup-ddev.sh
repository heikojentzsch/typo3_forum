#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"
readonly DDEV_ROOT="${REPOSITORY_ROOT}/ddev"
readonly STATE_DIR="${DDEV_ROOT}/.bootstrap"
readonly LOCK_DIR="${STATE_DIR}/setup.lock"
readonly CREDENTIALS_FILE="${STATE_DIR}/credentials.json"
readonly PROJECT_NAME="typo3forum"

mode=provision

usage() {
    cat <<'EOF'
Usage: ./Build/setup-ddev.sh [--check|--show-credentials|--help]

  (no option)          Start DDEV and safely provision or update the development site.
  --check              Verify the existing installation without changing it.
  --show-credentials   Show the persisted local development credentials.
  --help               Show this help.
EOF
}

fail() {
    printf 'ERROR: %s\n' "$*" >&2
    exit 1
}

phase() {
    printf '\n==> %s\n' "$*"
}

if (($# > 1)); then
    usage >&2
    exit 2
fi

case "${1:-}" in
    '') mode=provision ;;
    --check) mode=check ;;
    --show-credentials) mode=credentials ;;
    --help|-h) usage; exit 0 ;;
    *) usage >&2; exit 2 ;;
esac

if [[ "${mode}" == credentials ]]; then
    [[ -f "${CREDENTIALS_FILE}" ]] || fail 'No generated credentials are known. Run the bootstrap first.'
    permissions="$(stat -c '%a' "${CREDENTIALS_FILE}")"
    if (( (8#${permissions} & 8#077) != 0 )); then
        fail "Credential file permissions are too broad (${permissions}); expected 600."
    fi
    printf 'Local development credentials (%s):\n' "${CREDENTIALS_FILE}"
    cat -- "${CREDENTIALS_FILE}"
    exit 0
fi

command -v ddev >/dev/null 2>&1 || fail 'DDEV is not installed or is not available on PATH.'
command -v docker >/dev/null 2>&1 || fail 'Docker is not installed or is not available on PATH.'
docker info >/dev/null 2>&1 || fail 'Docker is installed but the daemon is not available. Start Docker and retry.'

[[ -f "${DDEV_ROOT}/.ddev/config.yaml" ]] || fail "Missing DDEV configuration: ${DDEV_ROOT}/.ddev/config.yaml"
grep -Eq '^name:[[:space:]]*typo3forum[[:space:]]*$' "${DDEV_ROOT}/.ddev/config.yaml" \
    || fail "Refusing to operate: DDEV project must be ${PROJECT_NAME}."

mkdir -p -- "${STATE_DIR}"
if ! mkdir -- "${LOCK_DIR}" 2>/dev/null; then
    if [[ -r "${LOCK_DIR}/pid" ]] && kill -0 "$(cat -- "${LOCK_DIR}/pid")" 2>/dev/null; then
        fail "Another bootstrap process is active (PID $(cat -- "${LOCK_DIR}/pid"))."
    fi
    fail "A stale bootstrap lock exists at ${LOCK_DIR}. Verify no setup is active, then remove that directory."
fi
printf '%s\n' "$$" >"${LOCK_DIR}/pid"
cleanup() { rm -rf -- "${LOCK_DIR}"; }
trap cleanup EXIT HUP INT TERM

cd -- "${DDEV_ROOT}"

if [[ "${mode}" == check ]]; then
    phase 'Starting DDEV for read-only verification'
    ddev start
    [[ -x vendor/bin/typo3 ]] || fail 'Dependencies are missing. Run ./Build/setup-ddev.sh first.'
    [[ -f "${CREDENTIALS_FILE}" ]] || fail 'Credentials state is missing. Run ./Build/setup-ddev.sh first.'
    phase 'Running read-only installation checks'
    ddev exec env TYPO3_FORUM_DDEV_BOOTSTRAP=1 vendor/bin/typo3 forum-dev:check
    ddev exec bash packages/typo3_forum/Build/Ddev/http-check.sh
    exit 0
fi

phase 'Starting DDEV'
ddev start

phase 'Installing development dependencies from the local checkout'
ddev composer install --no-interaction --prefer-dist
ddev exec php packages/typo3_forum/Build/Ddev/verify-local-package.php

phase 'Preparing persistent local credentials'
ddev exec php packages/typo3_forum/Build/Ddev/Credentials.php create /var/www/html/.bootstrap/credentials.json

table_count="$(ddev mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")"
has_backend_users="$(ddev mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'be_users'")"
has_owned_state="$(ddev mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'tx_typo3forumdevbootstrap_owned'")"

if [[ "${table_count}" == 0 ]]; then
    if [[ -f config/system/settings.php ]]; then
        ddev exec php packages/typo3_forum/Build/Ddev/repair-local-settings.php prepare /var/www/html/config/system/settings.php
    fi
    phase 'Initializing TYPO3 with the DDEV TCP database connection'
    ddev exec bash -c 'set -Eeuo pipefail; eval "$(php packages/typo3_forum/Build/Ddev/Credentials.php export /var/www/html/.bootstrap/credentials.json)"; export TYPO3_DB_DRIVER=mysqli TYPO3_DB_HOST=db TYPO3_DB_PORT=3306 TYPO3_DB_DBNAME=db TYPO3_DB_USERNAME=db TYPO3_DB_PASSWORD=db TYPO3_SETUP_ADMIN_EMAIL=forum-admin@example.invalid TYPO3_PROJECT_NAME="TYPO3 Forum Development" TYPO3_SERVER_TYPE=other; vendor/bin/typo3 setup --no-interaction --force'
    if [[ "${TYPO3_FORUM_DDEV_SMOKE:-}" == 1 && "${TYPO3_FORUM_TEST_FAIL_AFTER_SETUP:-}" == 1 ]]; then
        fail 'Intentional smoke-test interruption after TYPO3 initialization.'
    fi
elif [[ "${has_backend_users}" == 0 ]]; then
    [[ -f config/system/settings.php ]] \
        || fail 'The database is nonempty but no local TYPO3 settings exist. Refusing to adopt it.'
    ddev exec php packages/typo3_forum/Build/Ddev/repair-local-settings.php inspect /var/www/html/config/system/settings.php
    if [[ "${has_owned_state}" == 0 ]]; then
        table_names="$(ddev mysql -Nse "SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()")"
        grep -Eq '^(cache_|cf_|sys_registry$)' <<<"${table_names}" \
            || fail 'The database is nonempty but is not a recognized interrupted TYPO3 setup. Refusing to adopt it.'
    fi
    phase 'Resuming the recognized incomplete TYPO3 database without rerunning setup'
else
    backend_admin_count="$(ddev mysql -Nse "SELECT COUNT(*) FROM be_users WHERE admin = 1 AND disable = 0 AND deleted = 0")"
    [[ -f config/system/settings.php ]] \
        || fail 'TYPO3 tables exist but config/system/settings.php is missing.'
    if [[ "${backend_admin_count}" == 0 ]]; then
        phase 'Resuming an incomplete TYPO3 installation without rerunning setup'
        ddev exec php packages/typo3_forum/Build/Ddev/repair-local-settings.php inspect /var/www/html/config/system/settings.php
    else
        phase 'Using the existing TYPO3 installation without resetting it'
        ddev exec php packages/typo3_forum/Build/Ddev/repair-local-settings.php verify /var/www/html/config/system/settings.php
    fi
fi

phase 'Applying extension schema safely'
ddev exec vendor/bin/typo3 extension:setup --extension=typo3_forum_dev

phase 'Provisioning the deterministic development fixture'
provision_environment=(TYPO3_FORUM_DDEV_BOOTSTRAP=1)
if [[ "${TYPO3_FORUM_DDEV_SMOKE:-}" == 1 ]]; then
    provision_environment+=(TYPO3_FORUM_DDEV_SMOKE=1)
    if [[ -n "${TYPO3_FORUM_TEST_FAIL_AFTER_PHASE:-}" ]]; then
        provision_environment+=("TYPO3_FORUM_TEST_FAIL_AFTER_PHASE=${TYPO3_FORUM_TEST_FAIL_AFTER_PHASE}")
    fi
fi
ddev exec env "${provision_environment[@]}" vendor/bin/typo3 forum-dev:provision

phase 'Publishing assets and refreshing caches'
ddev exec vendor/bin/typo3 asset:publish
ddev exec vendor/bin/typo3 cache:flush

phase 'Verifying database state and rendered endpoints'
ddev exec env TYPO3_FORUM_DDEV_BOOTSTRAP=1 vendor/bin/typo3 forum-dev:check
ddev exec bash packages/typo3_forum/Build/Ddev/http-check.sh

primary_url="$(ddev exec --quiet bash -c 'printf %s "$DDEV_PRIMARY_URL"')"
mail_url="$(ddev exec --quiet bash -c 'printf "%s:%s" "$DDEV_PRIMARY_URL_WITHOUT_PORT" "$DDEV_MAILPIT_HTTPS_PORT"')"

cat <<EOF

Environment ready
Frontend URL: ${primary_url}/
Backend URL: ${primary_url}/typo3
Login page URL: ${primary_url}/login
Development mail UI URL: ${mail_url}
Backend username: forum_admin
Frontend/member username: forum_member
Moderator username: forum_moderator
Local credentials file: ${CREDENTIALS_FILE}
Show credentials: ./Build/setup-ddev.sh --show-credentials
Rerun verification: ./Build/setup-ddev.sh --check
EOF
