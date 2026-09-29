#!/usr/bin/env bash
# Usage: open-pr.sh <branch> <deployer-version> <draft: true|false> <body-file> <base-branch>
#        open-pr.sh --bundled-version <branch>
# Commits the working tree to <branch>, pushes it and opens or updates its pull request against <base-branch>.
# Does nothing if <branch> on origin already bundles <deployer-version>, so daily runs don't churn the PR.
# Never overwrites commits made by anyone else (e.g. recipe fixes on a Deployer major branch): comments on the
# open pull request once per version instead, and fails if there is none. Clean merges (e.g. from GitHub's
# "Update branch") don't count; merges with changes of their own, like a resolved conflict, do.
# Fails if origin cannot be read, rather than treating the branch as missing.
# --bundled-version prints the Deployer version bundled on origin's <branch>, or nothing if there is no such branch
# or its composer.json is missing or unreadable.
set -euo pipefail
shopt -s inherit_errexit

scripts="$(cd "$(dirname "$0")" && pwd)"

usage() {
    sed -n 's/^# \{0,1\}\(Usage: \|       open-pr\)/\1/p' "$0" >&2
    exit 2
}

# extra.deployer.version bundled at commit $1; nothing if its composer.json is missing or not valid JSON
version_at() {
    if git cat-file -e "$1:composer.json" 2>/dev/null; then
        git show "$1:composer.json" | php "$scripts/deployer-update.php" bundled-version
    fi
}

# Fetches origin's branch $1 into refs/remotes/origin/$1 and prints its commit, or prints nothing if it does not exist.
fetch_branch() {
    local status=0
    git ls-remote --exit-code origin "refs/heads/$1" > /dev/null || status=$?
    case $status in
        0)
            git fetch --quiet origin "+refs/heads/$1:refs/remotes/origin/$1"
            git rev-parse "refs/remotes/origin/$1"
            ;;
        2) ;;
        *)
            echo "Cannot read branch $1 from origin (git ls-remote exited with $status)." >&2
            return 1
            ;;
    esac
}

open_pr_number() {
    gh pr list --head "$branch" --state open --json number --jq '.[0].number // empty'
}

if [ "${1:-}" = --bundled-version ]; then
    [ $# = 2 ] || usage
    remote="$(fetch_branch "$2")"
    if [ -n "$remote" ]; then
        version_at "$remote"
    fi
    exit 0
fi

[ $# = 5 ] || usage
branch=$1 version=$2 draft=$3 body_file=$4 base=$5
title="Bundle Deployer $version"

remote="$(fetch_branch "$branch")"
if [ -n "$remote" ]; then
    if [ "$(version_at "$remote")" = "$version" ]; then
        echo "$branch already bundles Deployer $version; nothing to do."
        exit 0
    fi

    git fetch --quiet origin "+refs/heads/$base:refs/remotes/origin/$base"
    self="$(git var GIT_AUTHOR_IDENT | sed -E 's/^.*<([^>]*)>.*$/\1/')"
    # grep without -q reads all of git log's output, so pipefail can't see a SIGPIPE
    foreign="$(git log --no-merges --format=%ae "refs/remotes/origin/$base..$remote" | { grep -vxF "$self" || true; })"
    # A merge only counts if it changed something itself: --cc shows nothing for a clean merge
    for merge in $(git rev-list --merges "refs/remotes/origin/$base..$remote"); do
        if [ -n "$(git show --cc --format= "$merge")" ]; then
            foreign+="$(git show --no-patch --format=%ae "$merge")"
        fi
    done
    if [ -n "$foreign" ]; then
        number="$(open_pr_number)"
        if [ -z "$number" ]; then
            echo "$branch has commits by others and no open pull request, so it was not replaced with Deployer $version." >&2
            echo "Delete the branch if its pull request was merged or closed, or open a pull request for it." >&2
            exit 1
        fi
        echo "$branch has commits by others; not replacing it with Deployer $version." >&2
        marker="<!-- deployer-update: $version -->"
        comments="$(gh pr view "$number" --json comments --jq '.comments[].body')"
        if ! grep -qF "$marker" <<<"$comments"; then
            gh pr comment "$number" --body "Deployer $version was released. This branch has commits that were not made by the update workflow, so it was not updated automatically. To update it, run \`.github/scripts/fetch-phar.sh $version bin\` and \`composer config extra.deployer.version $version\` on this branch. $marker"
        fi
        exit 0
    fi
fi

git switch --quiet -C "$branch"
git commit --quiet -am "$title"
# Rejected if the branch moved since it was fetched; an empty $remote means it must not exist yet
git push --quiet --force-with-lease="refs/heads/$branch:$remote" origin "HEAD:refs/heads/$branch"

number="$(open_pr_number)"
if [ -n "$number" ]; then
    gh pr edit "$number" --title "$title" --body-file "$body_file"
else
    args=(--base "$base" --head "$branch" --title "$title" --body-file "$body_file")
    if [ "$draft" = true ]; then
        args+=(--draft)
    fi
    gh pr create "${args[@]}"
fi
