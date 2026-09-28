#!/usr/bin/env bash
# Usage: open-pr.sh <branch> <deployer-version> <draft: true|false> <body-file>
# Commits the working tree to <branch>, force-pushes it and opens or updates its pull request against main.
# Does nothing if <branch> on origin already bundles <deployer-version>, so daily runs don't churn the PR.
# Never force-pushes over commits made by anyone else (e.g. recipe fixes on a Deployer major branch);
# comments on the open pull request once per version instead. Needs full history of main.
set -euo pipefail

branch=$1 version=$2 draft=$3 body_file=$4
title="Bundle Deployer $version"

bundled_version() {
    php -r 'echo json_decode(stream_get_contents(STDIN), true)["extra"]["deployer"]["version"] ?? "";'
}

open_pr_number() {
    gh pr list --head "$branch" --state open --json number --jq '.[0].number // empty'
}

if git fetch --quiet origin "refs/heads/$branch" 2>/dev/null; then
    remote="$(git rev-parse FETCH_HEAD)"
    if [ "$(git show "$remote:composer.json" | bundled_version)" = "$version" ]; then
        echo "$branch already bundles Deployer $version; nothing to do."
        exit 0
    fi

    git fetch --quiet origin refs/heads/main
    self="$(git var GIT_AUTHOR_IDENT | sed -E 's/^.*<([^>]*)>.*$/\1/')"
    if git log --format=%ae "FETCH_HEAD..$remote" | grep -qvxF "$self"; then
        echo "$branch has commits by others; not replacing it with Deployer $version." >&2
        number="$(open_pr_number)"
        marker="<!-- deployer-update: $version -->"
        if [ -n "$number" ] && ! gh pr view "$number" --json comments --jq '.comments[].body' | grep -qF "$marker"; then
            gh pr comment "$number" --body "Deployer $version was released. This branch has commits that were not made by the update workflow, so it was not updated automatically. To update it, run \`.github/scripts/fetch-phar.sh $version bin\` and \`composer config extra.deployer.version $version\` on this branch. $marker"
        fi
        exit 0
    fi
fi

git switch --quiet -C "$branch"
git commit --quiet -am "$title"
git push --quiet --force origin "HEAD:refs/heads/$branch"

number="$(open_pr_number)"
if [ -n "$number" ]; then
    gh pr edit "$number" --title "$title" --body-file "$body_file"
else
    args=(--base main --head "$branch" --title "$title" --body-file "$body_file")
    if [ "$draft" = true ]; then
        args+=(--draft)
    fi
    gh pr create "${args[@]}"
fi
