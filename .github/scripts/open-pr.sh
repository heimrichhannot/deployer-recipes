#!/usr/bin/env bash
# Usage: open-pr.sh <branch> <deployer-version> <draft: true|false> <body-file>
# Commits the working tree to <branch>, force-pushes it and opens or updates its pull request against main.
# Does nothing if <branch> on origin already bundles <deployer-version>, so daily runs don't churn the PR.
set -euo pipefail

branch=$1 version=$2 draft=$3 body_file=$4
title="Bundle Deployer $version"

bundled_version() {
    php -r 'echo json_decode(stream_get_contents(STDIN), true)["extra"]["deployer"]["version"] ?? "";'
}

if git fetch --quiet origin "refs/heads/$branch" 2>/dev/null \
    && [ "$(git show FETCH_HEAD:composer.json | bundled_version)" = "$version" ]; then
    echo "$branch already bundles Deployer $version; nothing to do."
    exit 0
fi

git switch --quiet -C "$branch"
git commit --quiet -am "$title"
git push --quiet --force origin "HEAD:refs/heads/$branch"

number="$(gh pr list --head "$branch" --state open --json number --jq '.[0].number // empty')"
if [ -n "$number" ]; then
    gh pr edit "$number" --title "$title" --body-file "$body_file"
else
    args=(--base main --head "$branch" --title "$title" --body-file "$body_file")
    if [ "$draft" = true ]; then
        args+=(--draft)
    fi
    gh pr create "${args[@]}"
fi
