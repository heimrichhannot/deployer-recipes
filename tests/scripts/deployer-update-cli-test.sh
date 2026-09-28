#!/usr/bin/env bash
# Tests the .github/scripts/deployer-update.php CLI in a throwaway git repository.
set -euo pipefail

cli="$(cd "$(dirname "$0")/../.." && pwd)/.github/scripts/deployer-update.php"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fails=0
check() { if eval "$2"; then echo "ok: $1"; else echo "FAIL: $1"; fails=1; fi; }
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.org GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.org

git init --quiet "$work/repo"
cd "$work/repo"
echo '{"extra": {"deployer": {"version": "8.0.5", "recipes-major": 2}}}' > composer.json
printf '# Changelog\n\n## [Unreleased]\n\n## [2.0.0] - 2026-10-01\n\n- x\n' > CHANGELOG.md
git add . && git commit --quiet -m init && git tag 1.15.0 && git tag 2.0.0

plan="$(printf 'v9.0.0\nv8.0.6\nv8.1.0-rc.1\nv8.0.5\n' | php "$cli" plan)"
check 'plan releases 2.0.1' 'grep -qx "mode=release" <<<"$plan" && grep -qx "recipes_version=2.0.1" <<<"$plan"'
check 'plan picks same-major 8.0.6' 'grep -qx "same_major=8.0.6" <<<"$plan"'
check 'plan reports next major 9.0.0' 'grep -qx "next_major=9.0.0" <<<"$plan" && grep -qx "next_recipes_major=3" <<<"$plan"'

php "$cli" release-changelog 2.0.1 8.0.6
check 'release-changelog adds a dated section' 'grep -qx "## \[2.0.1\] - $(date -u +%F)" CHANGELOG.md'

git checkout --quiet CHANGELOG.md
php "$cli" unreleased-changelog 9.0.0
check 'unreleased-changelog adds the entry' 'grep -q "^- Bundle Deployer 9.0.0 " CHANGELOG.md'

git checkout --quiet CHANGELOG.md
echo fix > fix.txt && git add fix.txt && git commit --quiet -m "Unreleased fix"
plan="$(printf 'v8.0.6\n' | php "$cli" plan)"
check 'plan opens a PR when main has commits since the latest tag' 'grep -qx "mode=pr" <<<"$plan" && grep -qx "latest_tag=2.0.0" <<<"$plan"'
git tag 2.0.1

printf '# Changelog\n\n## [2.0.1] - 2026-10-02\n\n- y\n' > CHANGELOG.md
git commit --quiet -am "Release without [Unreleased] heading" && git tag -f 2.0.1 > /dev/null
plan="$(printf 'v8.0.6\n' | php "$cli" plan)"
check 'plan ignores the CHANGELOG' 'grep -qx "mode=release" <<<"$plan" && grep -qx "recipes_version=2.0.2" <<<"$plan"'
php "$cli" release-changelog 2.0.2 8.0.6
check 'release-changelog works without an [Unreleased] heading' 'grep -qx "## \[2.0.2\] - $(date -u +%F)" CHANGELOG.md'

cp composer.json composer.json.bak
echo '{"extra": {"deployer": {"version": "8.0.5"}}}' > composer.json
check 'plan fails without extra.deployer.recipes-major' '! printf "v8.0.6\n" | php "$cli" plan > /dev/null 2>&1'
mv composer.json.bak composer.json

check 'unknown command exits 2' '[ "$(php "$cli" bogus 2>/dev/null; echo $?)" = 2 ]'

rm -rf .git
check 'plan fails without git tags instead of reporting "untagged"' '! printf "v8.0.6\n" | php "$cli" plan > /dev/null 2>&1'

exit $fails
