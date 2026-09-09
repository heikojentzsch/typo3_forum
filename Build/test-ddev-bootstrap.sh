#!/usr/bin/env bash

set -Eeuo pipefail

readonly SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd -P)"

if [[ "${1:-}" != '--isolated-empty-project' ]]; then
    cat >&2 <<'EOF'
This harness uses the current DDEV database and is only safe in a disposable,
isolated checkout whose database is empty. Run with --isolated-empty-project
to acknowledge that condition. It never deletes or resets the database.
EOF
    exit 2
fi

cd -- "${REPOSITORY_ROOT}/ddev"
ddev start
table_count="$(ddev mysql -Nse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")"
[[ "${table_count}" == 0 ]] || { echo 'Smoke harness requires an empty isolated DDEV database.' >&2; exit 1; }

echo 'Running fresh TYPO3 setup and simulating interruption during fixture provisioning.'
if TYPO3_FORUM_DDEV_SMOKE=1 TYPO3_FORUM_TEST_FAIL_AFTER_PHASE=content "${REPOSITORY_ROOT}/Build/setup-ddev.sh" </dev/null; then
    echo 'Expected the injected fixture interruption, but setup succeeded.' >&2
    exit 1
fi

echo 'Resuming the interrupted fixture without stdin.'
TYPO3_FORUM_DDEV_SMOKE=1 "${REPOSITORY_ROOT}/Build/setup-ddev.sh" </dev/null
credential_hash_before="$(sha256sum .bootstrap/credentials.json | cut -d' ' -f1)"
owned_before="$(ddev mysql -Nse "SELECT GROUP_CONCAT(CONCAT(logical_key, ':', record_uid) ORDER BY logical_key) FROM tx_typo3forumdevbootstrap_owned")"

echo 'Adding unmanaged data and changing managed fixture content.'
ddev mysql -e "INSERT INTO pages (pid, title, slug, doktype, hidden, deleted, crdate, tstamp) SELECT record_uid, 'Preservation marker', '/preservation-marker', 1, 0, 0, UNIX_TIMESTAMP(), UNIX_TIMESTAMP() FROM tx_typo3forumdevbootstrap_owned WHERE logical_key='page.root'"
ddev mysql -e "UPDATE tx_typo3forum_domain_model_forum_post SET text='DDEV-FORUM-SAMPLE: developer-edited content' WHERE uid=(SELECT record_uid FROM tx_typo3forumdevbootstrap_owned WHERE logical_key='post.sample')"

TYPO3_FORUM_DDEV_SMOKE=1 "${REPOSITORY_ROOT}/Build/setup-ddev.sh" </dev/null
credential_hash_after="$(sha256sum .bootstrap/credentials.json | cut -d' ' -f1)"
owned_after="$(ddev mysql -Nse "SELECT GROUP_CONCAT(CONCAT(logical_key, ':', record_uid) ORDER BY logical_key) FROM tx_typo3forumdevbootstrap_owned")"

[[ "${credential_hash_before}" == "${credential_hash_after}" ]] || { echo 'Credentials rotated during repeat setup.' >&2; exit 1; }
[[ "${owned_before}" == "${owned_after}" ]] || { echo 'Managed UIDs changed during repeat setup.' >&2; exit 1; }
[[ "$(ddev mysql -Nse "SELECT COUNT(*) FROM pages WHERE title='Preservation marker' AND deleted=0")" == 1 ]] \
    || { echo 'Unmanaged preservation record was lost.' >&2; exit 1; }
[[ "$(ddev mysql -Nse "SELECT text FROM tx_typo3forum_domain_model_forum_post WHERE uid=(SELECT record_uid FROM tx_typo3forumdevbootstrap_owned WHERE logical_key='post.sample')")" == 'DDEV-FORUM-SAMPLE: developer-edited content' ]] \
    || { echo 'Developer-edited fixture content was overwritten.' >&2; exit 1; }

"${REPOSITORY_ROOT}/Build/setup-ddev.sh" --check </dev/null
echo 'Fresh, interrupted-resume, repeat, credentials and preservation scenarios passed.'
