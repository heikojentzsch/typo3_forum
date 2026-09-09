#!/usr/bin/env bash
set -euo pipefail

: "${GITHUB_REPOSITORY:?Set the public owner/repository}"
: "${GITHUB_TOKEN:?Set a protected, masked GitHub token with contents write access}"
: "${CI_COMMIT_TAG:?Run only for a release tag}"
: "${CI_COMMIT_SHA:?Missing GitLab commit}"
[[ "$GITHUB_REPOSITORY" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || exit 1
[[ "$CI_COMMIT_TAG" =~ ^v?[0-9]+\.[0-9]+\.[0-9]+([+-][A-Za-z0-9.+-]+)?$ ]] || exit 1
version="${CI_COMMIT_TAG#v}"
archive="dist/typo3_forum_${version}.zip"
[[ -f "$archive" && -f "$archive.sha256" ]] || { echo 'Release artifacts missing' >&2; exit 1; }
(cd dist && sha256sum --check "$(basename "$archive").sha256")
git fetch origin v14:refs/remotes/origin/v14
git merge-base --is-ancestor "$CI_COMMIT_SHA" refs/remotes/origin/v14
[[ "$(git rev-parse "$CI_COMMIT_TAG^{commit}")" == "$CI_COMMIT_SHA" ]] || exit 1

askpass="$(mktemp)"
trap 'rm -f -- "$askpass"' EXIT
cat > "$askpass" <<'ASKPASS'
#!/usr/bin/env bash
case "$1" in
  *Username*) printf '%s\n' 'x-access-token' ;;
  *Password*) printf '%s\n' "$GITHUB_TOKEN" ;;
esac
ASKPASS
chmod 700 "$askpass"
GIT_ASKPASS="$askpass" GIT_TERMINAL_PROMPT=0 git -c credential.helper= push --atomic \
  "https://github.com/${GITHUB_REPOSITORY}.git" \
  'refs/remotes/origin/v14:refs/heads/v14' "refs/tags/$CI_COMMIT_TAG:refs/tags/$CI_COMMIT_TAG"

export GH_TOKEN="$GITHUB_TOKEN"
gh release create "$CI_COMMIT_TAG" "$archive" "$archive.sha256" \
  --repo "$GITHUB_REPOSITORY" --verify-tag --draft --title "$CI_COMMIT_TAG" \
  --notes "Release $CI_COMMIT_TAG. Built by the authoritative GitLab pipeline. SHA-256 checksums are included."
prerelease=false
[[ "$version" != *-* ]] || prerelease=true
gh release edit "$CI_COMMIT_TAG" --repo "$GITHUB_REPOSITORY" --draft=false --prerelease="$prerelease"
