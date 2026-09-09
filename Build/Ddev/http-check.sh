#!/usr/bin/env bash

set -Eeuo pipefail

readonly base_url="${DDEV_PRIMARY_URL:?DDEV_PRIMARY_URL is not available}"
readonly curl_options=(--fail --silent --show-error --location --connect-timeout 5 --max-time 20)
temporary_directory="$(mktemp -d)"
cleanup() { rm -rf -- "${temporary_directory}"; }
trap cleanup EXIT HUP INT TERM

fetch() {
    local path="$1"
    local output="$2"
    curl "${curl_options[@]}" "${base_url}${path}" --output "${output}" \
        || { echo "HTTP request failed: ${base_url}${path}" >&2; return 1; }
}

fetch '/typo3' "${temporary_directory}/backend.html"
grep -Eqi 'TYPO3|backend' "${temporary_directory}/backend.html" \
    || { echo 'Backend endpoint returned no recognizable TYPO3 login page.' >&2; exit 1; }

fetch '/login' "${temporary_directory}/login.html"
grep -Eqi 'login|username|userident' "${temporary_directory}/login.html" \
    || { echo 'Frontend login page did not render a login form.' >&2; exit 1; }

fetch '/forum' "${temporary_directory}/forum.html"
grep -Fq 'Development forum' "${temporary_directory}/forum.html" \
    || { echo 'Forum landing page did not contain the managed forum.' >&2; exit 1; }

fetch '/forum/topic/welcome-to-the-development-forum' "${temporary_directory}/topic.html"
grep -Fq 'DDEV-FORUM-SAMPLE' "${temporary_directory}/topic.html" \
    || { echo 'Sample topic route did not contain the fixture marker.' >&2; exit 1; }

curl "${curl_options[@]}" --request POST "${base_url}/?type=43568275" \
    --data-urlencode 'tx_typo3forum_ajax[text]=[b]DDEV-PREVIEW[/b]' \
    --output "${temporary_directory}/preview.html" \
    || { echo "BBCode preview request failed: ${base_url}/?type=43568275" >&2; exit 1; }
grep -Fq 'DDEV-PREVIEW' "${temporary_directory}/preview.html" \
    || { echo 'BBCode preview did not return the requested marker.' >&2; exit 1; }

asset_path="$(grep -m 1 -Eo 'href="[^"]*typo3_forum\.css(\?[^" ]*)?' "${temporary_directory}/forum.html" | cut -d'"' -f2)"
[[ -n "${asset_path}" ]] || { echo 'Forum page did not reference the extension stylesheet.' >&2; exit 1; }
if [[ "${asset_path}" == http://* || "${asset_path}" == https://* ]]; then
    asset_url="${asset_path}"
else
    asset_url="${base_url}/${asset_path#/}"
fi
curl "${curl_options[@]}" "${asset_url}" --output /dev/null \
    || { echo "Frontend asset request failed: ${asset_url}" >&2; exit 1; }

php packages/typo3_forum/Build/Ddev/login-check.php "${base_url}" /var/www/html/.bootstrap/credentials.json

echo 'HTTP checks passed: backend, login/session, forum, topic, preview and frontend asset.'
