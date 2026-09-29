#!/usr/bin/env bash
# Tests .github/scripts/open-pr.sh against a local bare remote and a stub `gh` that logs its arguments.
# The base branch is deliberately not called "main".
set -euo pipefail

script="$(cd "$(dirname "$0")/../.." && pwd)/.github/scripts/open-pr.sh"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
fails=0
check() { if eval "$2"; then echo "ok: $1"; else echo "FAIL: $1"; fails=1; fi; }

# Stub gh: "pr list" prints $work/open-pr (empty = no open PR), "pr view" prints the comments that
# "pr comment" appended to $work/comments and exits with cat's status, like gh killed by SIGPIPE would;
# every call is logged.
mkdir "$work/stub"
cat > "$work/stub/gh" <<STUB
#!/usr/bin/env bash
echo "\$*" >> "$work/gh.log"
if [ "\$1 \$2" = "pr list" ]; then cat "$work/open-pr" 2>/dev/null || true; fi
if [ "\$1 \$2" = "pr view" ] && [ -f "$work/comments" ]; then exec cat "$work/comments"; fi
if [ "\$1 \$2" = "pr comment" ]; then echo "\$*" >> "$work/comments"; fi
exit 0
STUB
# Stub git: runs \$PUSH_HOOK before a push, to simulate someone pushing between the script's fetch and push.
real_git="$(command -v git)"
cat > "$work/stub/git" <<STUB
#!/usr/bin/env bash
if [ "\$1" = push ] && [ -n "\${PUSH_HOOK:-}" ]; then env -u PUSH_HOOK bash -c "\$PUSH_HOOK"; fi
exec "$real_git" "\$@"
STUB
chmod +x "$work/stub/gh" "$work/stub/git"
export PATH="$work/stub:$PATH"
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=test@example.org GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=test@example.org

git init --quiet --bare "$work/remote.git"
git init --quiet -b v2 "$work/repo"
cd "$work/repo"
git remote add origin "$work/remote.git"
echo '{"extra": {"deployer": {"version": "8.0.5"}}}' > composer.json
git add composer.json && git commit --quiet -m init && git push --quiet origin v2
echo body > "$work/body.md"
bump() { echo "{\"extra\": {\"deployer\": {\"version\": \"$1\"}}}" > composer.json; }
remote_head() { git --git-dir="$work/remote.git" rev-parse deployer/9; }
remote_version() { git --git-dir="$work/remote.git" show "deployer/9:composer.json" | grep -o '"version": "[^"]*"'; }
# Pushes a commit by someone else to deployer/9
human_commit() {
    rm -rf "$work/human"
    git clone --quiet --branch deployer/9 "$work/remote.git" "$work/human"
    (cd "$work/human" && echo "$1" >> recipe-fix.php && git add recipe-fix.php \
        && GIT_AUTHOR_EMAIL=human@example.org git commit --quiet -m "$1" && git push --quiet origin deployer/9)
}

# 1. No branch, no PR: pushes the branch and creates a draft PR.
bump 9.0.0
"$script" deployer/9 9.0.0 true "$work/body.md" v2 > /dev/null
check 'first run pushes the branch' '[ "$(remote_version)" = "\"version\": \"9.0.0\"" ]'
check 'first run creates a draft PR' 'grep -q -- "pr create --base v2 --head deployer/9 --title Bundle Deployer 9.0.0 --body-file $work/body.md --draft" "$work/gh.log"'

# 1b. --bundled-version reports what a branch on origin bundles.
check '--bundled-version prints the version on the branch' '[ "$("$script" --bundled-version deployer/9)" = 9.0.0 ]'
check '--bundled-version prints nothing for a missing branch' '[ -z "$("$script" --bundled-version deployer/7)" ]'

# 2. Same version again: no push, no gh calls.
git switch --quiet v2 && bump 9.0.0 && : > "$work/gh.log"
before="$(remote_head)"
"$script" deployer/9 9.0.0 true "$work/body.md" v2 > /dev/null
check 'unchanged version does not push' '[ "$(remote_head)" = "$before" ]'
check 'unchanged version does not call gh' '[ ! -s "$work/gh.log" ]'

# 3. Newer version with the PR still open: force-pushes and edits the PR instead of creating another.
#    Someone else's commit on the base branch (not on the PR branch) must not count as a foreign commit on the branch.
git clone --quiet --branch v2 "$work/remote.git" "$work/main-human"
(cd "$work/main-human" && echo docs > README.md && git add README.md \
    && GIT_AUTHOR_EMAIL=human@example.org git commit --quiet -m "Docs" && git push --quiet origin v2)
git checkout --quiet -f v2 && git pull --quiet --ff-only origin v2 && bump 9.0.1 && echo 42 > "$work/open-pr"
"$script" deployer/9 9.0.1 true "$work/body.md" v2 > /dev/null
check 'newer version force-pushes the branch' '[ "$(remote_version)" = "\"version\": \"9.0.1\"" ]'
check 'newer version edits the open PR' 'grep -q -- "pr edit 42 --title Bundle Deployer 9.0.1" "$work/gh.log"'
check 'newer version creates no second PR' '[ "$(grep -c "pr create" "$work/gh.log")" = 0 ]'

# 3b. The branch now contains someone else's commit from the base branch; that must not block the next update.
git checkout --quiet -f v2 && bump 9.0.2 && : > "$work/gh.log"
"$script" deployer/9 9.0.2 true "$work/body.md" v2 > /dev/null
check 'commits from the base branch do not block updates' '[ "$(remote_version)" = "\"version\": \"9.0.2\"" ]'

# 3c. Someone merged the base branch into the PR branch (GitHub's "Update branch"); the merge commit must not block updates.
(cd "$work/main-human" && git pull --quiet --ff-only origin v2 && echo more >> README.md \
    && GIT_AUTHOR_EMAIL=human@example.org git commit --quiet -am "More docs" && git push --quiet origin v2)
rm -rf "$work/human"
git clone --quiet --branch deployer/9 "$work/remote.git" "$work/human"
(cd "$work/human" && GIT_AUTHOR_EMAIL=human@example.org GIT_COMMITTER_EMAIL=human@example.org \
    git merge --quiet --no-ff --no-edit origin/v2 && git push --quiet origin deployer/9)
git checkout --quiet -f v2 && git pull --quiet --ff-only origin v2 && bump 9.0.3 && : > "$work/gh.log"
"$script" deployer/9 9.0.3 true "$work/body.md" v2 > /dev/null
check 'merging the base branch into the PR does not block updates' '[ "$(remote_version)" = "\"version\": \"9.0.3\"" ]'

# 3d. Someone pushes to the branch after the script fetched it: the push is rejected instead of overwriting the commit.
rm -rf "$work/racer"
git clone --quiet --branch deployer/9 "$work/remote.git" "$work/racer"
git checkout --quiet -f v2 && bump 9.0.4 && : > "$work/gh.log"
racer_commit="cd '$work/racer' && echo race > race.txt && git add race.txt && GIT_AUTHOR_EMAIL=human@example.org git commit --quiet -m Race && git push --quiet origin deployer/9"
status=0; PUSH_HOOK="$racer_commit" "$script" deployer/9 9.0.4 true "$work/body.md" v2 > /dev/null 2>&1 || status=$?
check 'a push after the fetch makes the script fail' '[ "$status" != 0 ]'
check 'a push after the fetch is not overwritten' '[ "$(remote_head)" = "$(git -C "$work/racer" rev-parse HEAD)" ]'

# 5. Someone pushed a commit to the branch: a newer version must not force-push over it, but comment once.
human_commit "Fix recipe for Deployer 9"
human_head="$(remote_head)"
git checkout --quiet -f v2 && bump 9.0.5 && : > "$work/gh.log"
"$script" deployer/9 9.0.5 true "$work/body.md" v2 > /dev/null 2>&1
check 'branch with foreign commits is not force-pushed' '[ "$(remote_head)" = "$human_head" ]'
check 'branch with foreign commits gets a comment instead' 'grep -q -- "pr comment 42 --body Deployer 9.0.5" "$work/gh.log"'

# 6. Same situation on the next run: no second comment, even if the PR has so many comments that
#    reading them stops early (SIGPIPE under pipefail).
head -c 300000 /dev/zero | tr '\0' x | fold -w 100 >> "$work/comments"
git checkout --quiet -f v2 && bump 9.0.5 && : > "$work/gh.log"
"$script" deployer/9 9.0.5 true "$work/body.md" v2 > /dev/null 2>&1
check 'foreign-commit comment is posted only once' '[ "$(grep -c "pr comment" "$work/gh.log")" = 0 ]'

# 7. Foreign commits and no open pull request (e.g. a merged PR whose branch was not deleted): fail visibly.
rm -f "$work/open-pr"
git checkout --quiet -f v2 && bump 9.0.6 && : > "$work/gh.log"
status=0; "$script" deployer/9 9.0.6 true "$work/body.md" v2 > /dev/null 2>&1 || status=$?
check 'foreign commits without an open PR make the script fail' '[ "$status" = 1 ]'
check 'foreign commits without an open PR are not force-pushed' '[ "$(remote_head)" = "$human_head" ]'
check 'foreign commits without an open PR get no comment' '[ "$(grep -c "pr comment" "$work/gh.log")" = 0 ]'

# 8. origin cannot be read (the push URL still works): fail instead of treating the branch as missing.
echo 42 > "$work/open-pr"
git remote set-url origin "$work/missing.git" && git remote set-url --push origin "$work/remote.git"
git checkout --quiet -f v2 && bump 9.0.6 && : > "$work/gh.log"
status=0; "$script" deployer/9 9.0.6 true "$work/body.md" v2 > /dev/null 2>&1 || status=$?
git remote set-url origin "$work/remote.git" && git config --unset remote.origin.pushurl
check 'an unreadable origin makes the script fail' '[ "$status" != 0 ]'
check 'an unreadable origin does not push' '[ "$(remote_head)" = "$human_head" ]'

# 9. Someone broke composer.json on the branch: the branch is not current, so the script comments instead of crashing.
(cd "$work/human" && git pull --quiet --ff-only origin deployer/9 && echo '<<<<<<< HEAD' > composer.json \
    && GIT_AUTHOR_EMAIL=human@example.org git commit --quiet -am "Break composer.json" && git push --quiet origin deployer/9)
git checkout --quiet -f v2 && bump 9.0.7 && : > "$work/gh.log"
status=0; "$script" deployer/9 9.0.7 true "$work/body.md" v2 > /dev/null 2>&1 || status=$?
check 'an unreadable composer.json on the branch does not fail the script' '[ "$status" = 0 ]'
check 'an unreadable composer.json on the branch gets a comment' 'grep -q -- "pr comment 42 --body Deployer 9.0.7" "$work/gh.log"'
status=0; bundled="$("$script" --bundled-version deployer/9 2>/dev/null)" || status=$?
check '--bundled-version prints nothing for an unreadable composer.json' '[ "$status" = 0 ] && [ -z "$bundled" ]'
(cd "$work/human" && git rm --quiet composer.json \
    && GIT_AUTHOR_EMAIL=human@example.org git commit --quiet -m "Remove composer.json" && git push --quiet origin deployer/9)
status=0; bundled="$("$script" --bundled-version deployer/9 2>/dev/null)" || status=$?
check '--bundled-version prints nothing for a missing composer.json' '[ "$status" = 0 ] && [ -z "$bundled" ]'

# 10. Someone merged the base branch into the PR branch and resolved a conflict by hand: that merge is someone
#     else's work and must not be force-pushed away.
git checkout --quiet -f v2 && git pull --quiet --ff-only origin v2 && bump 10.0.0 && echo 43 > "$work/open-pr"
"$script" deployer/10 10.0.0 true "$work/body.md" v2 > /dev/null
(cd "$work/main-human" && git pull --quiet --ff-only origin v2 \
    && echo '{"name": "base", "extra": {"deployer": {"version": "8.0.5"}}}' > composer.json \
    && GIT_AUTHOR_EMAIL=human@example.org git commit --quiet -am "Name the package" && git push --quiet origin v2)
rm -rf "$work/merger"
git clone --quiet --branch deployer/10 "$work/remote.git" "$work/merger"
(cd "$work/merger" && export GIT_AUTHOR_EMAIL=human@example.org GIT_COMMITTER_EMAIL=human@example.org \
    && { git merge --quiet origin/v2 > /dev/null 2>&1 || true; } \
    && echo '{"name": "base", "extra": {"deployer": {"version": "10.0.0"}}}' > composer.json \
    && git add composer.json && git commit --quiet --no-edit && git push --quiet origin deployer/10)
merge_head="$(git --git-dir="$work/remote.git" rev-parse deployer/10)"
git checkout --quiet -f v2 && git pull --quiet --ff-only origin v2 && bump 10.0.1 && : > "$work/gh.log"
"$script" deployer/10 10.0.1 true "$work/body.md" v2 > /dev/null 2>&1
check 'a hand-resolved merge is not force-pushed away' '[ "$(git --git-dir="$work/remote.git" rev-parse deployer/10)" = "$merge_head" ]'
check 'a hand-resolved merge gets a comment instead' 'grep -q -- "pr comment 43 --body Deployer 10.0.1" "$work/gh.log"'

# 11. Wrong arguments print the usage.
status=0; out="$("$script" --bundled-version 2>&1)" || status=$?
check '--bundled-version without a branch prints the usage' '[ "$status" = 2 ] && grep -q "^Usage:" <<<"$out"'
status=0; out="$("$script" deployer/9 2>&1)" || status=$?
check 'too few arguments print the usage' '[ "$status" = 2 ] && grep -q "^Usage:" <<<"$out"'

# 4. Non-draft mode omits --draft.
git checkout --quiet -f v2 && bump 8.0.6 && rm -f "$work/open-pr" && : > "$work/gh.log"
"$script" deployer/8-update 8.0.6 false "$work/body.md" v2 > /dev/null
check 'non-draft PR has no --draft' 'grep -q "pr create" "$work/gh.log" && ! grep -q -- "--draft" "$work/gh.log"'

exit $fails
